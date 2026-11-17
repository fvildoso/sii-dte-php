<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Auth\TokenManager;
use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\TransportException;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;
use SiiDte\Utils\HttpResponse;
use SiiDte\Utils\HttpTransport;

class TokenManagerTest extends TestCase
{
    private ?string $opensslConfigPath = null;
    private XmlSigner $signer;

    protected function setUp(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'sii-token-openssl-');
        self::assertNotFalse($configPath);
        $this->opensslConfigPath = $configPath;
        file_put_contents(
            $this->opensslConfigPath,
            "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n"
        );

        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($privateKey);
        $csr = openssl_csr_new(['commonName' => 'token-manager-test.invalid'], $privateKey, [
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, ['config' => $this->opensslConfigPath]);
        self::assertNotFalse($certificate);

        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $privateKey, 'token-test', [
            'config' => $this->opensslConfigPath,
        ]));
        $this->signer = new XmlSigner(CertificateStore::fromBytes($pkcs12, 'token-test'));
    }

    protected function tearDown(): void
    {
        if ($this->opensslConfigPath !== null && file_exists($this->opensslConfigPath)) {
            unlink($this->opensslConfigPath);
        }
    }

    public function testObtieneTokenConRespuestasSoapAnidadasYNoRepitePorCache(): void
    {
        $transport = new QueueHttpTransport([
            new HttpResponse(200, $this->soapResponse('getSeedReturn', $this->siiResponse('SEMILLA', '000123'))),
            new HttpResponse(200, $this->soapResponse('getTokenReturn', $this->siiResponse('TOKEN', 'token-de-prueba'))),
        ]);
        $manager = new TokenManager($this->signer, 'certificacion', $transport, 17);

        self::assertSame('token-de-prueba', $manager->getToken('12345678', '5'));
        self::assertSame('token-de-prueba', $manager->getToken('12345678', '5'));
        self::assertCount(2, $transport->requests);
        self::assertSame('https://maullin.sii.cl/DTEWS/CrSeed.jws', $transport->requests[0]['url']);
        self::assertSame('https://maullin.sii.cl/DTEWS/GetTokenFromSeed.jws', $transport->requests[1]['url']);
        self::assertSame(17, $transport->requests[0]['timeout']);
        self::assertSame(['Content-Type: text/xml; charset=UTF-8', 'SOAPAction: ""'], $transport->requests[1]['headers']);

        $soapDocument = new \DOMDocument();
        self::assertTrue($soapDocument->loadXML($transport->requests[1]['body'], LIBXML_NONET));
        $parameters = $soapDocument->getElementsByTagName('pszXml');
        self::assertSame(1, $parameters->length);
        self::assertSame($this->signer->signTokenRequest('000123'), $parameters->item(0)?->textContent);
    }

    public function testRechazaSemillaNoNumericaSinIntentarPedirToken(): void
    {
        $transport = new QueueHttpTransport([
            new HttpResponse(200, $this->soapResponse('getSeedReturn', $this->siiResponse('SEMILLA', '123x'))),
        ]);
        $manager = new TokenManager($this->signer, 'certificacion', $transport);

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('semilla numérica');
        $manager->getToken('12345678', '5');
    }

    public function testConservaEstadoYGlosaDelSiiSinVolcarLaRespuestaCompleta(): void
    {
        $inner = '<SII:RESPUESTA xmlns:SII="urn:sii"><SII:RESP_HDR><ESTADO>-1</ESTADO>'
            . '<GLOSA>semilla rechazada</GLOSA></SII:RESP_HDR></SII:RESPUESTA>';
        $transport = new QueueHttpTransport([
            new HttpResponse(200, $this->soapResponse('getSeedReturn', $inner)),
        ]);
        $manager = new TokenManager($this->signer, 'certificacion', $transport);

        try {
            $manager->getToken('12345678', '5');
            self::fail('Se esperaba el error de estado SII.');
        } catch (SiiException $exception) {
            self::assertStringContainsString('Estado: -1', $exception->getMessage());
            self::assertStringContainsString('semilla rechazada', $exception->getMessage());
            self::assertStringNotContainsString('<SII:RESPUESTA', $exception->getMessage());
        }
        self::assertCount(1, $transport->requests);
    }

    public function testRechazaSoapFaultYRespuestaHttpNoExitosa(): void
    {
        $faultTransport = new QueueHttpTransport([
            new HttpResponse(200, '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
                . '<soap:Body><soap:Fault><faultstring>internal</faultstring></soap:Fault></soap:Body></soap:Envelope>'),
        ]);
        $manager = new TokenManager($this->signer, 'certificacion', $faultTransport);

        try {
            $manager->getToken('12345678', '5');
            self::fail('Se esperaba rechazar SOAP Fault.');
        } catch (SiiException $exception) {
            self::assertStringContainsString('error SOAP', $exception->getMessage());
            self::assertStringNotContainsString('internal', $exception->getMessage());
        }

        $httpTransport = new QueueHttpTransport([new HttpResponse(503, 'untrusted response')]);
        $httpManager = new TokenManager($this->signer, 'certificacion', $httpTransport);
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('HTTP 503');
        $httpManager->getToken('12345678', '5');
    }

    public function testErrorDeTransporteSeExponeComoAmbiguoYNoSeReintenta(): void
    {
        $transport = new QueueHttpTransport([
            new TransportException('remote outcome unknown', true),
        ]);
        $manager = new TokenManager($this->signer, 'certificacion', $transport);

        try {
            $manager->getToken('12345678', '5');
            self::fail('Se esperaba propagar el error de transporte.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->outcomeMayBeUnknown);
        }
        self::assertCount(1, $transport->requests);
    }

    private function soapResponse(string $returnElement, string $innerXml): string
    {
        return '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body><ns1:response xmlns:ns1="http://DefaultNamespace">'
            . '<' . $returnElement . '>' . htmlspecialchars($innerXml, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</' . $returnElement . '></ns1:response></soapenv:Body></soapenv:Envelope>';
    }

    private function siiResponse(string $field, string $value): string
    {
        return '<SII:RESPUESTA xmlns:SII="urn:sii"><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR>'
            . '<SII:RESP_BODY><' . $field . '>' . $value . '</' . $field . '></SII:RESP_BODY></SII:RESPUESTA>';
    }
}

final class QueueHttpTransport implements HttpTransport
{
    /** @var list<array{url: string, body: string, headers: list<string>, timeout: int}> */
    public array $requests = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses)
    {
    }

    public function post(string $url, string $body, array $headers, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = [
            'url' => $url,
            'body' => $body,
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
        ];
        $response = array_shift($this->responses);
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (!$response instanceof HttpResponse) {
            throw new \LogicException('No hay respuesta HTTP simulada disponible.');
        }

        return $response;
    }
}
