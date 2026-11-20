<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use SiiDte\Document\DteBuilder;
use SiiDte\Document\DteTypes;
use SiiDte\Document\Invoice33Builder;
use SiiDte\Document\SiiSchemaValidator;
use SiiDte\Document\TedBuilder;
use SiiDte\Envelope\EnvelopeBuilder;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\CafInspector;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;

class TedBuilderTest extends TestCase
{
    private \OpenSSLAsymmetricKey $cafPrivateKey;
    private string $cafPrivateKeyPem;
    private string $cafPublicKeyPem;
    private ?string $opensslConfigPath = null;
    /** @var array<string, string> */
    private array $rsaDetails;

    protected function setUp(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'sii-ted-openssl-');
        self::assertNotFalse($configPath);
        $this->opensslConfigPath = $configPath;
        file_put_contents(
            $this->opensslConfigPath,
            "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n"
        );
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($key);
        $this->cafPrivateKey = $key;
        $privateKeyPem = '';
        self::assertTrue(openssl_pkey_export($key, $privateKeyPem, null, ['config' => $this->opensslConfigPath]));
        $this->cafPrivateKeyPem = $privateKeyPem;

        $details = openssl_pkey_get_details($key);
        self::assertIsArray($details);
        self::assertSame(OPENSSL_KEYTYPE_RSA, $details['type']);
        self::assertIsString($details['key'] ?? null);
        $this->cafPublicKeyPem = $details['key'];
        $this->rsaDetails = $details['rsa'];
    }

    protected function tearDown(): void
    {
        if ($this->opensslConfigPath !== null && file_exists($this->opensslConfigPath)) {
            unlink($this->opensslConfigPath);
        }
    }

    public function testAgregaTedConFolioExternoYValidaEstructuraYFrmt(): void
    {
        $dte = $this->builder()->addTedToDte(
            $this->dte(),
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
        $signedDte = $this->signer()->signDte($dte);
        new SiiSchemaValidator()->validate($signedDte, 'dte');

        $result = $this->builder()->verify($signedDte);
        self::assertTrue($result['frmt_verificada']);
        self::assertTrue($result['datos_coinciden']);
        self::assertTrue($result['llave_timbrado_corresponde']);
        self::assertFalse($result['frma_verificada']);
        self::assertFalse($result['emision_habilitada']);
        self::assertStringNotContainsString('FRMA no se verificó', implode(' ', $result['bloqueos']));
        self::assertStringNotContainsString('RSASK', $signedDte);
        self::assertStringNotContainsString($this->cafPrivateKeyPem, $signedDte);
    }

    public function testConservaBytesOriginalesDelCafAlFirmarDteYSobre(): void
    {
        $authorization = str_replace(
            ['<DA>', '<RS>Empresa de prueba</RS>', '</DA><FRMA'],
            ["<DA>\n  ", '<RS>Empresa&#x20;de prueba</RS>', "</DA>\n<FRMA"],
            $this->authorizationXml()
        );
        self::assertSame(1, preg_match('/<CAF\b.*?<\/CAF>/s', $authorization, $matches));
        $originalCaf = $matches[0];

        $stamped = $this->builder()->addTedToDte(
            $this->dte(),
            $authorization,
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
        self::assertStringContainsString($originalCaf, $stamped);

        $issuer = [
            'rut_emisor' => '97975000', 'dv_emisor' => '5', 'razon_social' => 'Empresa de prueba',
            'giro' => 'Servicios de prueba', 'codigo_actividad' => '620900',
            'direccion' => 'Av. Ejemplo 100', 'ciudad' => 'Santiago',
            'fecha_resolucion' => '2019-10-18', 'numero_resolucion' => 0,
        ];
        $receiver = [
            'rut' => '8414240', 'dv' => '9', 'razon_social' => 'Cliente café & té',
            'giro' => 'Comercio', 'direccion' => 'Calle Test 1', 'ciudad' => 'Santiago',
        ];
        $signer = $this->signer();
        $signed = $signer->signDte($stamped);
        $envelope = EnvelopeBuilder::build(
            $signed,
            $issuer,
            $receiver,
            '60803000-K',
            new \DateTimeImmutable('2024-01-15T12:28:33-03:00'),
            '60803000-K'
        );
        $signedEnvelope = $signer->signEnvelope($envelope);

        self::assertStringContainsString($originalCaf, $signed);
        self::assertStringContainsString($originalCaf, $signedEnvelope);
        self::assertTrue($this->builder()->verify($signed)['frmt_verificada']);
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'public']);
        $key->loadKey($this->cafPublicKeyPem);
        $signedDocument = new \DOMDocument();
        self::assertTrue($signedDocument->loadXML($signed, LIBXML_NONET));
        $documentVerifier = new XMLSecurityDSig();
        $documentVerifier->idKeys = ['ID'];
        $documentVerifier->allowedSignatureAlgorithms = [XMLSecurityKey::RSA_SHA1];
        $documentVerifier->allowedDigestAlgorithms = [XMLSecurityDSig::SHA1];
        self::assertArrayHasKey('DTE-33F27', $documentVerifier->verifyDocument($key, $signedDocument));

        $envelopeDocument = new \DOMDocument();
        self::assertTrue($envelopeDocument->loadXML($signedEnvelope, LIBXML_NONET));
        foreach (['DTE-33F27', 'SetDoc'] as $position => $id) {
            $envelopeVerifier = new XMLSecurityDSig();
            $envelopeVerifier->idKeys = ['ID'];
            $envelopeVerifier->allowedSignatureAlgorithms = [XMLSecurityKey::RSA_SHA1];
            $envelopeVerifier->allowedDigestAlgorithms = [XMLSecurityDSig::SHA1];
            self::assertArrayHasKey($id, $envelopeVerifier->verifyDocument($key, $envelopeDocument, $position));
        }
        new SiiSchemaValidator()->validate($signed, 'dte');
        new SiiSchemaValidator()->validate($signedEnvelope, 'envio_dte');
    }

    public function testVectorOficialSiiVerificaFrmtYDetectaFirmaAlterada(): void
    {
        $dte = <<<'XML'
            <DTE xmlns="http://www.sii.cl/SiiDte" version="1.0"><Documento ID="F60T33"><Encabezado><IdDoc><TipoDTE>33</TipoDTE><Folio>60</Folio><FchEmis>2003-10-13</FchEmis></IdDoc><Emisor><RUTEmisor>97975000-5</RUTEmisor></Emisor><Receptor><RUTRecep>77777777-7</RUTRecep><RznSocRecep>EMPRESA  LTDA</RznSocRecep></Receptor><Totales><MntTotal>119000</MntTotal></Totales></Encabezado><Detalle><NmbItem>Parlantes Multimedia 180W.</NmbItem></Detalle><TED version="1.0"><DD><RE>97975000-5</RE><TD>33</TD><F>60</F><FE>2003-10-13</FE><RR>77777777-7</RR><RSR>EMPRESA  LTDA</RSR><MNT>119000</MNT><IT1>Parlantes Multimedia 180W.</IT1><CAF version="1.0"><DA><RE>97975000-5</RE><RS>RUT DE PRUEBA</RS><TD>33</TD><RNG><D>1</D><H>200</H></RNG><FA>2003-09-04</FA><RSAPK><M>0a4O6Kbx8Qj3K4iWSP4w7KneZYeJ+g/prihYtIEolKt3cykSxl1zO8vSXu397QhTmsX7SBEudTUx++2zDXBhZw==</M><E>Aw==</E></RSAPK><IDK>100</IDK></DA><FRMA algoritmo="SHA1withRSA">g1AQX0sy8NJugX52k2hTJEZAE9Cuul6pqYBdFxj1N17umW7zG/hAavCALKByHzdYAfZ3LhGTXCai5zNxOo4lDQ==</FRMA></CAF><TSTED>2003-10-13T09:33:20</TSTED></DD><FRMT algoritmo="SHA1withRSA">GbmDcS9e/jVC2LsLIe1iRV12Bf6lxsILtbQiCkh6mbjckFCJ7fj/kakFTS06Jo8iS4HXvJj3oYZuey53Krniew==</FRMT></TED><TmstFirma>2003-10-13T09:33:20</TmstFirma></Documento></DTE>
            XML;

        $builder = $this->builder();
        $result = $builder->verify($dte);
        self::assertTrue($result['frmt_verificada']);
        self::assertTrue($result['datos_coinciden']);
        self::assertTrue($result['llave_timbrado_corresponde']);
        self::assertFalse($result['frma_verificada']);
        self::assertFalse($result['emision_habilitada']);

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($dte, LIBXML_NONET));
        $frmt = $document->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'FRMT')->item(0);
        self::assertInstanceOf(\DOMElement::class, $frmt);
        $signature = trim($frmt->textContent);
        self::assertNotSame('', $signature);
        $frmt->nodeValue = ($signature[0] === 'A' ? 'B' : 'A') . substr($signature, 1);

        $mutatedDte = $document->saveXML();
        self::assertIsString($mutatedDte);
        $mutatedResult = $builder->verify($mutatedDte);
        self::assertFalse($mutatedResult['frmt_verificada']);
        self::assertTrue($mutatedResult['datos_coinciden']);
        self::assertFalse($mutatedResult['frma_verificada']);
        self::assertFalse($mutatedResult['emision_habilitada']);
    }

    public function testFactura33SinteticaCompletaLocalConTedFirmaYSobre(): void
    {
        $issuer = [
            'rut_emisor' => '97975000', 'dv_emisor' => '5', 'razon_social' => 'Empresa de prueba',
            'giro' => 'Servicios de prueba', 'codigo_actividad' => '620900',
            'direccion' => 'Av. Ejemplo 100', 'ciudad' => 'Santiago',
        ];
        $receiver = [
            'rut' => '8414240', 'dv' => '9', 'razon_social' => 'Cliente café & té',
            'giro' => 'Comercio', 'direccion' => 'Calle Test 1', 'ciudad' => 'Santiago',
        ];
        $invoice = new Invoice33Builder($issuer)->build(27, [
            'fecha' => '2024-01-15', 'tasa_iva' => '19', 'receptor' => $receiver,
            'detalle' => [['nombre' => 'Café', 'cantidad' => '2.5', 'precio_unitario' => '400']],
        ]);
        $stamped = $this->builder()->addTedToDte(
            $invoice,
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
        $result = $this->builder()->verify($stamped);
        self::assertTrue($result['frmt_verificada']);
        self::assertTrue($result['datos_coinciden']);
        self::assertFalse($result['frma_verificada']);
        $signer = $this->signer();
        $signed = $signer->signDte($stamped);
        new SiiSchemaValidator()->validate($signed, 'dte');
        $envelope = EnvelopeBuilder::build(
            $signed,
            $issuer + ['fecha_resolucion' => '2019-10-18', 'numero_resolucion' => 0],
            $receiver,
            '60803000-K',
            new \DateTimeImmutable('2024-01-15T12:28:33-03:00'),
            '60803000-K',
        );
        $envelope = $signer->signEnvelope($envelope);
        new SiiSchemaValidator()->validate($envelope, 'envio_dte');
        self::assertStringNotContainsString('RSASK', $envelope);
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($envelope, LIBXML_NONET));
        $original = new \DOMDocument();
        self::assertTrue($original->loadXML($signed, LIBXML_NONET));
        $originalNode = $original->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'Documento')->item(0);
        $wrappedNode = $document->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'Documento')->item(0);
        self::assertNotNull($originalNode);
        self::assertNotNull($wrappedNode);
        self::assertSame($originalNode->C14N(), $wrappedNode->C14N());
        $nestedDte = $document->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'DTE')->item(0);
        self::assertNotNull($nestedDte);
        $nestedXml = $document->saveXML($nestedDte);
        self::assertIsString($nestedXml);
        self::assertTrue($this->builder()->verify($nestedXml)['frmt_verificada']);
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'public']);
        $key->loadKey($this->cafPublicKeyPem);
        foreach (['DTE-33F27', 'SetDoc'] as $position => $id) {
            $verifier = new XMLSecurityDSig();
            $verifier->idKeys = ['ID'];
            $verifier->allowedSignatureAlgorithms = [XMLSecurityKey::RSA_SHA1];
            $verifier->allowedDigestAlgorithms = [XMLSecurityDSig::SHA1];
            self::assertArrayHasKey($id, $verifier->verifyDocument($key, $document, $position));
        }
    }

    public function testRechazaFolioQueNoCoincideConElDocumentoORangoCaf(): void
    {
        try {
            $this->builder()->addTedToDte(
                $this->dte(),
                $this->authorizationXml(),
                28,
                '2024-01-15T12:28:31',
                '2024-01-15T12:28:32'
            );
            self::fail('Se esperaba rechazar el folio distinto del DTE.');
        } catch (SiiException $exception) {
            self::assertStringContainsString('no coincide', $exception->getMessage());
        }

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('fuera del rango');
        $this->builder()->addTedToDte(
            $this->dte(),
            $this->authorizationXml(start: 30, end: 40),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
    }

    public function testRechazaLlavePrivadaDeTimbradoIncorrecta(): void
    {
        $wrongKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($wrongKey);
        $wrongPrivateKeyPem = '';
        self::assertTrue(openssl_pkey_export($wrongKey, $wrongPrivateKeyPem, null, [
            'config' => $this->opensslConfigPath,
        ]));

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('no corresponde');
        $this->builder()->addTedToDte(
            $this->dte(),
            $this->authorizationXml(privateKeyPem: $wrongPrivateKeyPem),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
    }

    public function testRechazaFechaHoraInvalida(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('TSTED');
        $this->builder()->addTedToDte(
            $this->dte(),
            $this->authorizationXml(),
            27,
            '2024-02-31T12:28:31',
            '2024-03-01T12:28:32'
        );
    }

    public function testRechazaDteQueYaTieneFirmaXmlDsig(): void
    {
        $signedDte = $this->signer()->signDte($this->dte());

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('XMLDSig');
        $this->builder()->addTedToDte(
            $signedDte,
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
    }

    public function testRechazaTedConNamespaceAjenoAntesDeTimbrar(): void
    {
        $ambiguousDte = str_replace(
            '</Documento>',
            '<evil:TED xmlns:evil="urn:unexpected"/></Documento>',
            $this->dte()
        );
        self::assertNotSame($this->dte(), $ambiguousDte);

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('ya contiene TED');
        $this->builder()->addTedToDte(
            $ambiguousDte,
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
    }

    public function testRechazaTiposNoSoportadosOCafDeOtroTipo(): void
    {
        $unsupported = str_replace('<TipoDTE>33</TipoDTE>', '<TipoDTE>99</TipoDTE>', $this->dte());
        try {
            $this->builder()->addTedToDte($unsupported, $this->authorizationXml(), 27, '2024-01-15T12:28:31', '2024-01-15T12:28:32');
            self::fail('Debía rechazar el tipo 99.');
        } catch (SiiException $e) {
            self::assertStringContainsString('no está soportado', $e->getMessage());
        }

        // Un tipo soportado, pero con un CAF de otro tipo (33), debe rechazarse.
        $otroTipo = str_replace('<TipoDTE>33</TipoDTE>', '<TipoDTE>34</TipoDTE>', $this->dte());
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('no coincide con el tipo esperado');
        $this->builder()->addTedToDte($otroTipo, $this->authorizationXml(), 27, '2024-01-15T12:28:31', '2024-01-15T12:28:32');
    }

    public function testRechazaSignatureConNamespaceAjeno(): void
    {
        $ambiguousDte = str_replace(
            '</DTE>',
            '<evil:Signature xmlns:evil="urn:unexpected"/></DTE>',
            $this->dte()
        );
        self::assertNotSame($this->dte(), $ambiguousDte);

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('XMLDSig');
        $this->builder()->addTedToDte(
            $ambiguousDte,
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
    }

    public function testVerificadorDetectaAlteracionDeDatosYDeFrmt(): void
    {
        $dte = $this->builder()->addTedToDte(
            $this->dte(),
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );

        $alteredData = str_replace('<MntTotal>119000</MntTotal>', '<MntTotal>119001</MntTotal>', $dte);
        self::assertNotSame($dte, $alteredData);
        $result = $this->builder()->verify($alteredData);
        self::assertTrue($result['frmt_verificada']);
        self::assertFalse($result['datos_coinciden']);

        $alteredSignature = preg_replace(
            '/(<FRMT algoritmo="SHA1withRSA">)([A-Za-z0-9+\/])/',
            '$1A',
            $dte,
            1
        );
        self::assertIsString($alteredSignature);
        self::assertNotSame($dte, $alteredSignature);
        self::assertFalse($this->builder()->verify($alteredSignature)['frmt_verificada']);
    }

    public function testRechazaDtd(): void
    {
        $this->expectException(SiiException::class);
        $this->builder()->addTedToDte(
            '<!DOCTYPE DTE><DTE/>',
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
    }

    public function testRechazaCaracterUnicodeNoRepresentableEnIso88591(): void
    {
        $dte = str_replace('Producto A', '&#x1F600;', $this->dte());

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('ISO-8859-1');
        $this->builder()->addTedToDte(
            $dte,
            $this->authorizationXml(),
            27,
            '2024-01-15T12:28:31',
            '2024-01-15T12:28:32'
        );
    }

    private function builder(): TedBuilder
    {
        return new TedBuilder(new CafInspector());
    }

    private function signer(): XmlSigner
    {
        $csr = openssl_csr_new(['commonName' => 'ted-builder-test.invalid'], $this->cafPrivateKey, [
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $this->cafPrivateKey, 2, ['config' => $this->opensslConfigPath]);
        self::assertNotFalse($certificate);

        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $this->cafPrivateKey, 'ted-test', [
            'config' => $this->opensslConfigPath,
        ]));

        return new XmlSigner(CertificateStore::fromBytes($pkcs12, 'ted-test'));
    }

    private function dte(): string
    {
        return new DteBuilder([
            'rut_emisor'        => '97975000',
            'dv_emisor'         => '5',
            'razon_social'      => 'Empresa de prueba',
            'giro'              => 'Servicios de prueba',
            'direccion'         => 'Av. Ejemplo 100',
            'ciudad'            => 'Santiago',
            'numero_resolucion' => 0,
            'fecha_resolucion'  => '2019-10-18',
        ])->build(DteTypes::FACTURA_ELECTRONICA, [
            'folio'    => 27,
            'fecha'    => '2024-01-15',
            'receptor' => [
                'rut'          => '8414240',
                'dv'           => '9',
                'razon_social' => ' Cliente café & té ',
                'giro'         => 'Comercio',
                'direccion'    => 'Calle Test 1',
                'ciudad'       => 'Santiago',
            ],
            'detalle' => [[
                'nombre'          => ' Producto A ',
                'cantidad'        => 1,
                'precio_unitario' => 119000,
            ]],
        ]);
    }

    private function authorizationXml(int $start = 1, int $end = 200, ?string $privateKeyPem = null): string
    {
        $modulus = chunk_split(base64_encode($this->rsaDetails['n']), 64, "\n");
        $exponent = base64_encode($this->rsaDetails['e']);
        $publicKey = htmlspecialchars($this->cafPublicKeyPem, ENT_XML1, 'UTF-8');
        $privateKey = htmlspecialchars($privateKeyPem ?? $this->cafPrivateKeyPem, ENT_XML1, 'UTF-8');

        return '<AUTORIZACION><CAF version="1.0"><DA>'
            . '<RE>97975000-5</RE><RS>Empresa de prueba</RS><TD>33</TD>'
            . "<RNG><D>{$start}</D><H>{$end}</H></RNG><FA>2024-01-01</FA>"
            . "<RSAPK><M>{$modulus}</M><E>{$exponent}</E></RSAPK><IDK>100</IDK>"
            . '</DA><FRMA algoritmo="SHA1withRSA">' . base64_encode(str_repeat('A', 32)) . '</FRMA></CAF>'
            . '<RSASK>' . $privateKey . '</RSASK><RSAPUBK>' . $publicKey . '</RSAPUBK></AUTORIZACION>';
    }
}
