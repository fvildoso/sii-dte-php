<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Document\DteTypes;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\CafInspector;

class CafInspectorTest extends TestCase
{
    private $privateKey;
    private string $privateKeyPem;
    private string $publicKeyPem;
    private array $rsaDetails;
    private ?string $opensslConfigPath = null;

    protected function setUp(): void
    {
        $this->opensslConfigPath = tempnam(sys_get_temp_dir(), 'sii-caf-openssl-');
        self::assertNotFalse($this->opensslConfigPath);
        file_put_contents(
            $this->opensslConfigPath,
            "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n"
        );
        $this->privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($this->privateKey);
        $csr = openssl_csr_new(['commonName' => 'caf-test.local'], $this->privateKey, [
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $this->privateKey, 1, [
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($certificate);
        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $this->privateKey, 'caf-test'));
        $certificateData = [];
        self::assertTrue(openssl_pkcs12_read($pkcs12, $certificateData, 'caf-test'));
        $this->privateKeyPem = $certificateData['pkey'];
        $details = openssl_pkey_get_details($this->privateKey);
        self::assertIsArray($details);
        $this->rsaDetails = $details['rsa'];
        $this->publicKeyPem = $details['key'];
    }

    protected function tearDown(): void
    {
        if ($this->opensslConfigPath !== null && file_exists($this->opensslConfigPath)) {
            unlink($this->opensslConfigPath);
        }
    }

    public function testInspeccionNoAfirmaFirmaSiiSinLlaveConfiableYNoExponeLlavePrivada(): void
    {
        $inspection = new CafInspector()->inspect($this->cafXml(), '76123456-0', 33, '2026-04-15');

        $this->assertSame('76123456-0', $inspection['rut_emisor']);
        $this->assertSame(33, $inspection['tipo_dte']);
        $this->assertSame(1, $inspection['folio_desde']);
        $this->assertSame(10, $inspection['folio_hasta']);
        $this->assertSame('2026-01-15', $inspection['fecha_autorizacion']);
        $this->assertSame('2026-07-15', $inspection['fecha_vencimiento_calculada']);
        $this->assertSame('vigente', $inspection['vigencia']);
        $this->assertSame('no_verificada', $inspection['firma_sii']);
        $this->assertTrue($inspection['llave_publica_coincide']);
        $this->assertTrue($inspection['llave_timbrado_corresponde']);
        $this->assertFalse($inspection['ambiente_demostrado']);
        $this->assertFalse($inspection['emision_habilitada']);
        $this->assertArrayNotHasKey('llave_privada', $inspection);
        $this->assertStringNotContainsString($this->privateKeyPem, json_encode($inspection, JSON_THROW_ON_ERROR));
    }

    public function testValidaFolioExternoContraEmisorTipoYRangoSinAsignarlo(): void
    {
        $inspection = new CafInspector()->validateFolio(
            $this->cafXml(),
            '76123456-0',
            33,
            7,
            '2026-04-15'
        );

        $this->assertSame(7, $inspection['folio_verificado']);
        $this->assertSame(1, $inspection['folio_desde']);
        $this->assertSame(10, $inspection['folio_hasta']);
        $this->assertFalse($inspection['emision_habilitada']);
    }

    public function testRechazaFolioExternoFueraDelRango(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('fuera del rango');
        new CafInspector()->validateFolio($this->cafXml(), '76123456-0', 33, 11, '2026-04-15');
    }

    public function testRechazaDtdYEntidadesExternas(): void
    {
        $xml = '<!DOCTYPE AUTORIZACION [<!ENTITY xxe SYSTEM "file:///not-read">]>'
            . '<AUTORIZACION><CAF><DA><RE>&xxe;</RE></DA></CAF></AUTORIZACION>';

        $this->expectException(SiiException::class);
        new CafInspector()->inspect($xml, '76123456-0', 33, '2026-04-15');
    }

    public function testRechazaXmlUtf16QuePodriaEludirElFiltroLexicoDeDtd(): void
    {
        $xml = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', '<!DOCTYPE AUTORIZACION><AUTORIZACION/>');

        $this->expectException(SiiException::class);
        new CafInspector()->inspect($xml, '76123456-0', 33, '2026-04-15');
    }

    public function testRechazaRutDistintoDelEsperado(): void
    {
        $this->expectException(SiiException::class);
        new CafInspector()->inspect($this->cafXml(), '96790970-K', 33, '2026-04-15');
    }

    public function testRechazaTipoDistintoDelEsperado(): void
    {
        $this->expectException(SiiException::class);
        new CafInspector()->inspect($this->cafXml(), '76123456-0', 34, '2026-04-15');
    }

    public function testRechazaRangoInvalido(): void
    {
        $this->expectException(SiiException::class);
        new CafInspector()->inspect($this->cafXml(start: 10, end: 1), '76123456-0', 33, '2026-04-15');
    }

    public function testRechazaFechaDeAutorizacionImposible(): void
    {
        $this->expectException(SiiException::class);
        new CafInspector()->inspect(
            $this->cafXml(authorizationDate: '2026-02-30'),
            '76123456-0',
            33,
            '2026-04-15'
        );
    }

    public function testDetectaLlaveDeTimbradoQueNoCorresponde(): void
    {
        $inspection = new CafInspector()->inspect(
            $this->cafXml(privateKeyPem: $this->otherPrivateKey()),
            '76123456-0',
            33,
            '2026-04-15'
        );

        $this->assertFalse($inspection['llave_timbrado_corresponde']);
        $this->assertFalse($inspection['emision_habilitada']);
    }

    public function testFRMADummyNoRequiereResolverIDKParaInspeccionarElCaf(): void
    {
        $inspection = new CafInspector()->inspect(
            $this->cafXml(frma: base64_encode('dummy-frma')),
            '76123456-0',
            33,
            '2026-04-15'
        );

        $this->assertSame('999', $inspection['idk_sii']);
        $this->assertSame('Empresa de prueba', $inspection['razon_social_autorizacion']);
        $this->assertSame('no_verificada', $inspection['firma_sii']);
        $this->assertFalse($inspection['emision_habilitada']);
        $this->assertStringNotContainsString('FRMA no se verificó', implode(' ', $inspection['bloqueos']));
    }

    public function testRechazaRSAPUBKQueNoCoincideConRSAPK(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('RSAPUBK no coincide con RSAPK');
        new CafInspector()->inspect(
            $this->cafXml(publicKeyPem: $this->otherPublicKey()),
            '76123456-0',
            33,
            '2026-04-15'
        );
    }

    public function testCalculaLaFechaLimiteDeVigenciaYMarcaCafVencido(): void
    {
        $inspection = new CafInspector()->inspect($this->cafXml(), '76123456-0', 33, '2026-11-15');

        $this->assertSame('2026-07-15', $inspection['fecha_vencimiento_calculada']);
        $this->assertSame('vencido', $inspection['vigencia']);
        $this->assertFalse($inspection['emision_habilitada']);
    }

    public function testLaVigenciaEsIndeterminadaEnLaFechaLimite(): void
    {
        $inspection = new CafInspector()->inspect($this->cafXml(), '76123456-0', 33, '2026-07-15');

        $this->assertSame('indeterminada', $inspection['vigencia']);
    }

    public function testCalculaVigenciaSoloParaTiposConReglaDocumentada(): void
    {
        $typesWithDocumentedPeriod = [33, 43, 46, 56, 61];

        foreach (DteTypes::todos() as $type) {
            $inspection = new CafInspector()->inspect(
                $this->cafXml(type: $type),
                '76123456-0',
                $type,
                '2026-04-15'
            );

            if (in_array($type, $typesWithDocumentedPeriod, true)) {
                $this->assertSame('2026-07-15', $inspection['fecha_vencimiento_calculada']);
                $this->assertSame('vigente', $inspection['vigencia']);
            } else {
                $this->assertNull($inspection['fecha_vencimiento_calculada']);
                $this->assertSame('indeterminada', $inspection['vigencia']);
            }
        }
    }

    public function testElCalculoDeSeisMesesAjustaElFinDeMesYNoDecideElDiaLimite(): void
    {
        $cafXml = $this->cafXml(authorizationDate: '2025-08-31');

        $beforeLimit = new CafInspector()->inspect($cafXml, '76123456-0', 33, '2026-02-27');
        $onLimit = new CafInspector()->inspect($cafXml, '76123456-0', 33, '2026-02-28');
        $afterLimit = new CafInspector()->inspect($cafXml, '76123456-0', 33, '2026-03-01');

        $this->assertSame('2026-02-28', $beforeLimit['fecha_vencimiento_calculada']);
        $this->assertSame('vigente', $beforeLimit['vigencia']);
        $this->assertSame('indeterminada', $onLimit['vigencia']);
        $this->assertSame('vencido', $afterLimit['vigencia']);
    }

    public function testCafAnteriorAlCorteUsaDieciochoMesesConTopeDe2018(): void
    {
        $inspection = new CafInspector()->inspect(
            $this->cafXml(authorizationDate: '2017-01-15'),
            '76123456-0',
            33,
            '2018-07-14'
        );

        $this->assertSame('2018-07-15', $inspection['fecha_vencimiento_calculada']);
        $this->assertSame('vigente', $inspection['vigencia']);

        $onLimit = new CafInspector()->inspect(
            $this->cafXml(authorizationDate: '2017-01-15'),
            '76123456-0',
            33,
            '2018-07-15'
        );
        $afterLimit = new CafInspector()->inspect(
            $this->cafXml(authorizationDate: '2017-01-15'),
            '76123456-0',
            33,
            '2018-07-16'
        );

        $this->assertSame('indeterminada', $onLimit['vigencia']);
        $this->assertSame('vencido', $afterLimit['vigencia']);
    }

    public function testAplicaTopeHistoricoEnElLimiteAmbiguo(): void
    {
        $cafXml = $this->cafXml(authorizationDate: '2017-07-01');
        $beforeLimit = new CafInspector()->inspect($cafXml, '76123456-0', 33, '2018-12-30');
        $onLimit = new CafInspector()->inspect($cafXml, '76123456-0', 33, '2018-12-31');
        $afterLimit = new CafInspector()->inspect($cafXml, '76123456-0', 33, '2019-01-01');

        $this->assertSame('2018-12-31', $onLimit['fecha_vencimiento_calculada']);
        $this->assertSame('vigente', $beforeLimit['vigencia']);
        $this->assertSame('indeterminada', $onLimit['vigencia']);
        $this->assertSame('vencido', $afterLimit['vigencia']);
    }

    private function cafXml(
        int $start = 1,
        int $end = 10,
        ?string $privateKeyPem = null,
        string $authorizationDate = '2026-01-15',
        ?string $publicKeyPem = null,
        int $type = 33,
        ?string $frma = null,
    ): string {
        $privateKeyPem ??= $this->privateKeyPem;
        $publicKeyPem ??= $this->publicKeyPem;
        $modulus = chunk_split(base64_encode($this->rsaDetails['n']), 64, "\n");
        $exponent = base64_encode($this->rsaDetails['e']);
        $signature = $frma ?? chunk_split(base64_encode(str_repeat('a', 100)), 64, "\n");
        $privateKeyXml = htmlspecialchars($privateKeyPem, ENT_XML1, 'UTF-8');
        $publicKeyXml = htmlspecialchars($publicKeyPem, ENT_XML1, 'UTF-8');

        return '<AUTORIZACION><CAF version="1.0"><DA>'
            . "<RE>76123456-0</RE><RS>Empresa de prueba</RS><TD>{$type}</TD>"
            . "<RNG><D>{$start}</D><H>{$end}</H></RNG><FA>{$authorizationDate}</FA>"
            . "<RSAPK><M>{$modulus}</M><E>{$exponent}</E></RSAPK><IDK>999</IDK>"
            . "</DA><FRMA algoritmo=\"SHA1withRSA\">{$signature}</FRMA></CAF>"
            . '<RSASK>' . $privateKeyXml . '</RSASK><RSAPUBK>' . $publicKeyXml . '</RSAPUBK></AUTORIZACION>';
    }

    private function otherPublicKey(): string
    {
        $privateKey = openssl_pkey_get_private($this->otherPrivateKey());
        self::assertNotFalse($privateKey);
        $details = openssl_pkey_get_details($privateKey);
        self::assertIsArray($details);

        return $details['key'];
    }

    private function otherPrivateKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($key);
        $csr = openssl_csr_new(['commonName' => 'caf-other.local'], $key, [
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $key, 1, [
            'config' => $this->opensslConfigPath,
        ]);
        self::assertNotFalse($certificate);
        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $key, 'caf-test'));
        $certificateData = [];
        self::assertTrue(openssl_pkcs12_read($pkcs12, $certificateData, 'caf-test'));

        return $certificateData['pkey'];
    }
}
