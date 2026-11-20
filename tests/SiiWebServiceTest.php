<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\TransportException;
use SiiDte\Utils\HttpResponse;
use SiiDte\Utils\HttpTransport;
use SiiDte\Utils\SiiWebService;

class SiiWebServiceTest extends TestCase
{
    public function testUploadDevuelveTrackIdSinClasificarloComoAceptacion(): void
    {
        $transport = new SiiWebServiceTestTransport([
            new HttpResponse(200, '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>123456789</TRACKID></RECEPCIONDTE>'),
        ]);
        $service = new SiiWebService('certificacion', $transport, 24);

        self::assertSame('123456789', $service->uploadDte('97975000', '5', '<EnvioDTE/>', 'token-test', '60803000', 'K'));
        self::assertCount(1, $transport->requests);
        self::assertSame('https://maullin.sii.cl/cgi_dte/UPL/DTEUpload', $transport->requests[0]['url']);
        self::assertSame(24, $transport->requests[0]['timeout']);
        self::assertContains('Cookie: TOKEN=token-test', $transport->requests[0]['headers']);
        self::assertStringContainsString("name=\"rutSender\"\r\n\r\n60803000\r\n", $transport->requests[0]['body']);
        self::assertStringContainsString("name=\"dvSender\"\r\n\r\nK\r\n", $transport->requests[0]['body']);
        self::assertStringContainsString("name=\"rutCompany\"\r\n\r\n97975000\r\n", $transport->requests[0]['body']);
        self::assertStringContainsString("name=\"dvCompany\"\r\n\r\n5\r\n", $transport->requests[0]['body']);
        self::assertStringContainsString('name="archivo"; filename="envio.xml"', $transport->requests[0]['body']);
    }

    public function testConsultaEstadoDesenvuelveRespuestaSoapNamespacedYConservaEstadoIndividual(): void
    {
        $inner = '<SII:RESPUESTA xmlns:SII="urn:sii"><SII:RESP_HDR><ESTADO>EPR</ESTADO>'
            . '<GLOSA>Sobre recibido</GLOSA></SII:RESP_HDR><SII:RESP_BODY>'
            . '<DTE_ENVIADO><FOLIO>27</FOLIO><TIPO_DTE>33</TIPO_DTE><ESTADO>RCH</ESTADO>'
            . '<GLOSA>Documento rechazado</GLOSA></DTE_ENVIADO></SII:RESP_BODY></SII:RESPUESTA>';
        $response = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soap:Body><m:getEstadoAvanzadoResponse xmlns:m="urn:service">'
            . '<getEstadoAvanzadoReturn>' . htmlspecialchars($inner, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</getEstadoAvanzadoReturn></m:getEstadoAvanzadoResponse></soap:Body></soap:Envelope>';
        $transport = new SiiWebServiceTestTransport([new HttpResponse(200, $response)]);
        $service = new SiiWebService('certificacion', $transport);

        $result = $service->getStatus('97975000', '5', '123456789', 'token-test');

        self::assertSame('EPR', $result['estado']);
        self::assertSame('Sobre recibido', $result['glosa']);
        self::assertSame('27', $result['detalle'][0]['folio']);
        self::assertSame('33', $result['detalle'][0]['tipo']);
        self::assertSame('RCH', $result['detalle'][0]['estado']);
        self::assertSame('Documento rechazado', $result['detalle'][0]['glosa']);
        self::assertSame('https://maullin.sii.cl/DTEWS/QueryEstUp.jws', $transport->requests[0]['url']);
        self::assertStringContainsString('getEstUp', $transport->requests[0]['body']);
        self::assertStringContainsString('>97975000</RutCompania>', $transport->requests[0]['body']);
        self::assertStringContainsString('>5</DvCompania>', $transport->requests[0]['body']);
    }

    public function testConsultaDteUsaContratoOficialConConsultanteYFechaSeparados(): void
    {
        $inner = '<RESPUESTA><RESP_HDR><ESTADO>DOK</ESTADO><GLOSA>Documento encontrado</GLOSA></RESP_HDR></RESPUESTA>';
        $soap = '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"><soap:Body>'
            . '<getEstDteResponse><getEstDteReturn>' . htmlspecialchars($inner, ENT_XML1, 'UTF-8')
            . '</getEstDteReturn></getEstDteResponse></soap:Body></soap:Envelope>';
        $transport = new SiiWebServiceTestTransport([new HttpResponse(200, $soap)]);
        $result = new SiiWebService('certificacion', $transport)->queryDte(
            '97975000',
            '5',
            '8414240',
            '9',
            33,
            27,
            '2024-01-15',
            119000,
            'token-test',
            '60803000',
            'K'
        );

        self::assertSame('DOK', $result['estado']);
        self::assertSame('https://maullin.sii.cl/DTEWS/QueryEstDte.jws', $transport->requests[0]['url']);
        $body = $transport->requests[0]['body'];
        foreach (['RutConsultante' => '60803000', 'DvConsultante' => 'K', 'RutCompania' => '97975000',
            'DvCompania' => '5', 'RutReceptor' => '8414240', 'DvReceptor' => '9', 'TipoDte' => '33',
            'FolioDte' => '27', 'FechaEmisionDte' => '15012024', 'MontoDte' => '119000'] as $name => $value) {
            self::assertStringContainsString('>' . $value . '</' . $name . '>', $body);
        }
    }

    public function testTimeoutDeEnvioSeMarcaAmbiguoYNoSeReintenta(): void
    {
        $transport = new SiiWebServiceTestTransport([
            new TransportException('timeout', true),
        ]);
        $service = new SiiWebService('certificacion', $transport);

        try {
            $service->uploadDte('97975000', '5', '<EnvioDTE/>', 'token-test');
            self::fail('Se esperaba propagar el error ambiguo del transporte.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->outcomeMayBeUnknown);
        }
        self::assertCount(1, $transport->requests);
    }

    public function testRespuestaDeRecepcionNoInterpretableEsAmbigua(): void
    {
        $transport = new SiiWebServiceTestTransport([new HttpResponse(200, '<response><unrelated/></response>')]);
        $service = new SiiWebService('certificacion', $transport);

        try {
            $service->uploadDte('97975000', '5', '<EnvioDTE/>', 'token-test');
            self::fail('Se esperaba marcar como ambiguo el resultado no interpretable.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->outcomeMayBeUnknown);
        }
        self::assertCount(1, $transport->requests);
    }

    public function testValidaDatosAntesDeTransmitir(): void
    {
        $transport = new SiiWebServiceTestTransport([]);
        $service = new SiiWebService('certificacion', $transport);

        try {
            $service->uploadDte('97975000', '0', '<EnvioDTE/>', 'token-test');
            self::fail('Se esperaba rechazar el RUT inválido.');
        } catch (\SiiDte\Exceptions\SiiException) {
            self::assertCount(0, $transport->requests);
        }
    }

    public function testRechazaRutConSaltosDeLineaAntesDelMultipart(): void
    {
        $transport = new SiiWebServiceTestTransport([]);
        $service = new SiiWebService('certificacion', $transport);

        try {
            $service->uploadDte("97975000\r\n", '5', '<EnvioDTE/>', 'token-test');
            self::fail('Se esperaba rechazar el RUT con saltos de línea.');
        } catch (\SiiDte\Exceptions\SiiException) {
            self::assertCount(0, $transport->requests);
        }
    }

    public function testRespuestaSinTrackOContradictoriaEsAmbigua(): void
    {
        foreach (['<RECEPCIONDTE><STATUS>0</STATUS></RECEPCIONDTE>',
            '<RECEPCIONDTE><STATUS>7</STATUS><TRACKID>123</TRACKID></RECEPCIONDTE>'] as $xml) {
            $transport = new SiiWebServiceTestTransport([new HttpResponse(200, $xml)]);

            try {
                new SiiWebService('certificacion', $transport)->uploadDte('97975000', '5', '<EnvioDTE/>', 'token-test');
                self::fail('La recepción contradictoria o incompleta debe ser ambigua.');
            } catch (TransportException $exception) {
                self::assertTrue($exception->outcomeMayBeUnknown);
            }
            self::assertCount(1, $transport->requests);
        }
    }

    public function testConservaTrackIdConCerosIniciales(): void
    {
        $transport = new SiiWebServiceTestTransport([
            new HttpResponse(200, '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>0069186080</TRACKID></RECEPCIONDTE>'),
        ]);
        self::assertSame(
            '0069186080',
            new SiiWebService('certificacion', $transport)->uploadDte('97975000', '5', '<EnvioDTE/>', 'token-test')
        );
    }
}

final class SiiWebServiceTestTransport implements HttpTransport
{
    /** @var list<array{url: string, body: string, headers: list<string>, timeout: int}> */
    public array $requests = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses) {}

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
