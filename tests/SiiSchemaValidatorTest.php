<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Document\DteBuilder;
use SiiDte\Document\DteTypes;
use SiiDte\Document\SiiSchemaValidator;
use SiiDte\Envelope\EnvelopeBuilder;
use SiiDte\Exceptions\SiiException;

class SiiSchemaValidatorTest extends TestCase
{
    public function testRechazaUnPerfilNoReconocido(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('Perfil XSD SII no reconocido.');

        new SiiSchemaValidator()->validate('<DTE/>', 'custom');
    }

    public function testRechazaXmlMayorAlLimiteAntesDeValidar(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('5 MiB');

        new SiiSchemaValidator()->validate(str_repeat(' ', 5 * 1024 * 1024 + 1), 'dte');
    }

    public function testRechazaDtdSinResolverEntidadesExternas(): void
    {
        $xml = '<!DOCTYPE DTE [<!ENTITY xxe SYSTEM "file:///sensitive-test-file">]>'
            . '<DTE xmlns="http://www.sii.cl/SiiDte">&xxe;</DTE>';

        try {
            new SiiSchemaValidator()->validate($xml, 'dte');
            self::fail('Se esperaba el rechazo del DTD.');
        } catch (SiiException $exception) {
            $this->assertSame('No se permiten declaraciones DTD en el XML.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive-test-file', $exception->getMessage());
        }
    }

    public function testRechazaXmlMalformadoSinExponerSuContenido(): void
    {
        $xml = '<DTE><Dato>dato sensible';

        try {
            new SiiSchemaValidator()->validate($xml, 'dte');
            self::fail('Se esperaba el rechazo del XML mal formado.');
        } catch (SiiException $exception) {
            $this->assertStringStartsWith('El XML está mal formado', $exception->getMessage());
            $this->assertStringNotContainsString('dato sensible', $exception->getMessage());
        }
    }

    public function testFallaCerradoSiFaltanLosEsquemasOficiales(): void
    {
        $xml = '<DTE xmlns="http://www.sii.cl/SiiDte"/>';
        $missingSchemaDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'fixtures' . DIRECTORY_SEPARATOR . 'missing-sii-schemas';

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('Los esquemas XSD SII no están disponibles');

        new SiiSchemaValidator($missingSchemaDirectory)->validate($xml, 'dte');
    }

    public function testCargaLosEsquemasOficialesEnLosTresPerfiles(): void
    {
        $samples = [
            'dte' => '<DTE xmlns="http://www.sii.cl/SiiDte"/>',
            'envio_dte' => '<EnvioDTE xmlns="http://www.sii.cl/SiiDte"/>',
            'envio_boleta' => '<EnvioBOLETA xmlns="http://www.sii.cl/SiiDte"/>',
        ];

        foreach ($samples as $profile => $xml) {
            try {
                (new SiiSchemaValidator())->validate($xml, $profile);
                self::fail(sprintf('El esquema del perfil %s debe rechazar su estructura incompleta.', $profile));
            } catch (SiiException $exception) {
                $this->assertStringStartsWith(
                    'El XML no cumple el esquema XSD SII fijado',
                    $exception->getMessage(),
                    sprintf('El perfil %s debe haber cargado el XSD oficial y validado el contenido.', $profile)
                );
            }
        }
    }

    public function testRechazaLaRaizQueNoCorrespondeAlPerfil(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('La raíz XML no corresponde al perfil XSD seleccionado.');

        (new SiiSchemaValidator())->validate(
            '<EnvioDTE xmlns="http://www.sii.cl/SiiDte"/>',
            'dte'
        );
    }

    public function testFallaCerradoSiSeAlteraUnEsquema(): void
    {
        $testSchemaDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'sii-schema-' . bin2hex(random_bytes(6));
        $profileDirectory = $testSchemaDirectory . DIRECTORY_SEPARATOR . 'dte';
        $sourceDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'schemas' . DIRECTORY_SEPARATOR . 'sii' . DIRECTORY_SEPARATOR . 'dte';
        $schemaFiles = ['DTE_v10.xsd', 'SiiTypes_v10.xsd', 'xmldsignature_v10.xsd'];

        self::assertTrue(mkdir($profileDirectory, 0777, true));
        foreach ($schemaFiles as $schemaFile) {
            self::assertTrue(copy($sourceDirectory . DIRECTORY_SEPARATOR . $schemaFile, $profileDirectory . DIRECTORY_SEPARATOR . $schemaFile));
        }
        self::assertNotFalse(file_put_contents($profileDirectory . DIRECTORY_SEPARATOR . 'DTE_v10.xsd', 'alterado'));

        try {
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('Los esquemas XSD SII no están disponibles o su integridad no pudo verificarse.');

            (new SiiSchemaValidator($testSchemaDirectory))->validate('<DTE xmlns="http://www.sii.cl/SiiDte"/>', 'dte');
        } finally {
            foreach ($schemaFiles as $schemaFile) {
                unlink($profileDirectory . DIRECTORY_SEPARATOR . $schemaFile);
            }
            rmdir($profileDirectory);
            rmdir($testSchemaDirectory);
        }
    }

    public function testAceptaMuestraSinteticaDteContraXsd(): void
    {
        (new SiiSchemaValidator())->validate($this->syntheticInvoice(), 'dte');
        $this->addToAssertionCount(1);
    }

    public function testAceptaMuestraSinteticaEnvioDteContraXsd(): void
    {
        $invoice = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $this->syntheticInvoice());
        $xml = '<EnvioDTE version="1.0" xmlns="http://www.sii.cl/SiiDte">'
            . '<SetDTE ID="SetDoc"><Caratula version="1.0">'
            . '<RutEmisor>76123456-0</RutEmisor><RutEnvia>76123456-0</RutEnvia>'
            . '<RutReceptor>60803000-K</RutReceptor><FchResol>2019-10-18</FchResol>'
            . '<NroResol>0</NroResol><TmstFirmaEnv>2024-01-15T12:00:00</TmstFirmaEnv>'
            . '<SubTotDTE><TpoDTE>33</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>'
            . '</Caratula>' . $invoice . '</SetDTE>' . $this->signature('SetDoc') . '</EnvioDTE>';

        (new SiiSchemaValidator())->validate($xml, 'envio_dte');
        $this->addToAssertionCount(1);
    }

    public function testEnvelopeBuilderGeneraCaratulaConsistenteYValidaContraXsd(): void
    {
        $emisor = [
            'rut_emisor' => '76123456',
            'dv_emisor' => '0',
            'fecha_resolucion' => '2019-10-18',
            'numero_resolucion' => 0,
        ];
        $receptor = ['rut' => '98765432', 'dv' => '5'];
        $envelope = EnvelopeBuilder::build(
            $this->syntheticInvoice(),
            $emisor,
            $receptor,
            '60803000-K',
            new \DateTimeImmutable('2024-01-15T12:00:00-03:00'),
            '60803000-K',
        );
        $envelope = str_replace('</EnvioDTE>', $this->signature('SetDoc') . '</EnvioDTE>', $envelope);
        (new SiiSchemaValidator())->validate($envelope, 'envio_dte');

        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($envelope, LIBXML_NONET));
        self::assertSame('60803000-K', $document->getElementsByTagName('RutEnvia')->item(0)?->textContent);
        self::assertSame('60803000-K', $document->getElementsByTagName('RutReceptor')->item(0)?->textContent);
        self::assertSame('98765432-5', $document->getElementsByTagName('RUTRecep')->item(0)?->textContent);
        self::assertSame('2019-10-18', $document->getElementsByTagName('FchResol')->item(0)?->textContent);
        self::assertSame('2024-01-15T12:00:00', $document->getElementsByTagName('TmstFirmaEnv')->item(0)?->textContent);
    }

    public function testEnvelopeBuilderRechazaDteConEmisorQueNoCoincide(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('no coinciden');
        EnvelopeBuilder::build(
            $this->syntheticInvoice(),
            [
                'rut_emisor' => '97975000',
                'dv_emisor' => '5',
                'fecha_resolucion' => '2019-10-18',
                'numero_resolucion' => 0,
            ],
            ['rut' => '98765432', 'dv' => '5'],
        );
    }

    public function testEnvelopeBuilderRechazaDtd(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('DTD');
        EnvelopeBuilder::build(
            '<!DOCTYPE DTE><DTE/>',
            ['rut_emisor' => '76123456', 'dv_emisor' => '0'],
            ['rut' => '98765432', 'dv' => '5'],
        );
    }

    public function testAceptaMuestraSinteticaEnvioBoletaContraXsd(): void
    {
        $xml = '<EnvioBOLETA version="1.0" xmlns="http://www.sii.cl/SiiDte">'
            . '<SetDTE ID="SetBol"><Caratula version="1.0">'
            . '<RutEmisor>76123456-0</RutEmisor><RutEnvia>76123456-0</RutEnvia>'
            . '<RutReceptor>60803000-K</RutReceptor><FchResol>2019-10-18</FchResol>'
            . '<NroResol>0</NroResol><TmstFirmaEnv>2024-01-15T12:00:00</TmstFirmaEnv>'
            . '<SubTotDTE><TpoDTE>39</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>'
            . '</Caratula><DTE version="1.0"><Documento ID="BOL39F1">'
            . '<Encabezado><IdDoc><TipoDTE>39</TipoDTE><Folio>1</Folio>'
            . '<FchEmis>2024-01-15</FchEmis><IndServicio>3</IndServicio></IdDoc>'
            . '<Emisor><RUTEmisor>76123456-0</RUTEmisor></Emisor>'
            . '<Receptor><RUTRecep>60803000-K</RUTRecep></Receptor>'
            . '<Totales><MntTotal>119000</MntTotal></Totales></Encabezado>'
            . $this->syntheticTed(39)
            . '<TmstFirma>2024-01-15T12:00:00</TmstFirma></Documento>'
            . $this->signature('BOL39F1') . '</DTE></SetDTE>'
            . $this->signature('SetBol') . '</EnvioBOLETA>';

        (new SiiSchemaValidator())->validate($xml, 'envio_boleta');
        $this->addToAssertionCount(1);
    }

    private function syntheticInvoice(): string
    {
        $builder = new DteBuilder([
            'rut_emisor'        => '76123456',
            'dv_emisor'         => '0',
            'razon_social'      => 'Empresa Test SpA',
            'giro'              => 'Servicios de TI',
            'direccion'         => 'Av. Ejemplo 100',
            'ciudad'            => 'Santiago',
            'numero_resolucion' => 0,
            'fecha_resolucion'  => '2019-10-18',
        ]);
        $invoice = $builder->build(DteTypes::FACTURA_ELECTRONICA, [
            'folio'    => 1,
            'fecha'    => '2024-01-15',
            'receptor' => [
                'rut'          => '98765432',
                'dv'           => '5',
                'razon_social' => 'Cliente Test',
                'giro'         => 'Comercio',
                'direccion'    => 'Calle Test 1',
                'ciudad'       => 'Valparaiso',
            ],
            'detalle' => [[
                'nombre'          => 'Producto A',
                'cantidad'        => 1,
                'precio_unitario' => 119000,
            ]],
        ]);
        $invoice = iconv('ISO-8859-1', 'UTF-8', $invoice);
        self::assertIsString($invoice);

        return str_replace(
            '</Documento>',
            $this->syntheticTed(33) . '<TmstFirma>2024-01-15T12:00:00</TmstFirma></Documento>',
            str_replace('</DTE>', $this->signature('DTE-33F1') . '</DTE>', $invoice)
        );
    }

    private function syntheticTed(int $documentType): string
    {
        return '<TED version="1.0"><DD><RE>76123456-0</RE><TD>' . $documentType
            . '</TD><F>1</F><FE>2024-01-15</FE><RR>98765432-5</RR><RSR>Cliente Test</RSR>'
            . '<MNT>119000</MNT><IT1>Producto A</IT1><CAF version="1.0"><DA>'
            . '<RE>76123456-0</RE><RS>Empresa Test SpA</RS><TD>' . $documentType
            . '</TD><RNG><D>1</D><H>100</H></RNG><FA>2024-01-01</FA>'
            . '<RSAPK><M>AQ==</M><E>AQAB</E></RSAPK><IDK>100</IDK></DA>'
            . '<FRMA algoritmo="SHA1withRSA">AA==</FRMA></CAF>'
            . '<TSTED>2024-01-15T12:00:00</TSTED></DD>'
            . '<FRMT algoritmo="SHA1withRSA">AA==</FRMT></TED>';
    }

    private function signature(string $reference): string
    {
        return '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#"><ds:SignedInfo>'
            . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<ds:SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
            . '<ds:Reference URI="#' . $reference . '"><ds:DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
            . '<ds:DigestValue>AA==</ds:DigestValue></ds:Reference></ds:SignedInfo>'
            . '<ds:SignatureValue>AA==</ds:SignatureValue><ds:KeyInfo><ds:KeyValue><ds:RSAKeyValue>'
            . '<ds:Modulus>AQ==</ds:Modulus><ds:Exponent>AQAB</ds:Exponent></ds:RSAKeyValue></ds:KeyValue>'
            . '<ds:X509Data><ds:X509Certificate>AA==</ds:X509Certificate></ds:X509Data></ds:KeyInfo>'
            . '</ds:Signature>';
    }
}
