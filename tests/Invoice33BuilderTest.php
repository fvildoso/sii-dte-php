<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Document\Invoice33Builder;
use SiiDte\Exceptions\SiiException;

class Invoice33BuilderTest extends TestCase
{
    private static function emisor(): array
    {
        return [
            'rut_emisor' => '76123456', 'dv_emisor' => '0',
            'razon_social' => 'Empresa SpA', 'giro' => 'Servicios',
            'codigo_actividad' => '620900', 'direccion' => 'Calle 1', 'ciudad' => 'Santiago',
        ];
    }

    private static function datos(): array
    {
        return [
            'fecha' => '2024-02-29', 'tasa_iva' => '19',
            'receptor' => [
                'rut' => '98765432', 'dv' => '5', 'razon_social' => 'Cliente',
                'giro' => 'Comercio', 'direccion' => 'Calle 2', 'ciudad' => 'Valparaíso',
            ],
            'detalle' => [['nombre' => 'Té & <café>', 'cantidad' => '2.500000', 'precio_unitario' => '40.00']],
        ];
    }

    private function xpath(string $xml): \DOMXPath
    {
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sii.cl/SiiDte');

        return $xpath;
    }

    public function testConstruyeDocumentoParcialConMontosNetosYOrden(): void
    {
        $datos = self::datos();
        $datos['detalle'][] = ['nombre' => 'Segundo', 'cantidad' => '0.5', 'precio_unitario' => '200'];
        $xml = (new Invoice33Builder(self::emisor()))->build(123, $datos);
        self::assertStringContainsString('encoding="ISO-8859-1"', $xml);
        self::assertStringContainsString(iconv('UTF-8', 'ISO-8859-1', 'Valparaíso'), $xml);
        $xpath = $this->xpath($xml);
        self::assertSame('DTE-33F123', $xpath->evaluate('string(/s:DTE/s:Documento/@ID)'));
        self::assertSame('33', $xpath->evaluate('string(//s:TipoDTE)'));
        self::assertSame('123', $xpath->evaluate('string(//s:Folio)'));
        self::assertSame('Té & <café>', $xpath->evaluate('string(//s:Detalle[1]/s:NmbItem)'));
        self::assertSame('2.5', $xpath->evaluate('string(//s:Detalle[1]/s:QtyItem)'));
        self::assertSame('40', $xpath->evaluate('string(//s:Detalle[1]/s:PrcItem)'));
        self::assertSame('100', $xpath->evaluate('string(//s:Detalle[1]/s:MontoItem)'));
        self::assertSame('100', $xpath->evaluate('string(//s:Detalle[2]/s:MontoItem)'));
        self::assertSame('200', $xpath->evaluate('string(//s:MntNeto)'));
        self::assertSame('19', $xpath->evaluate('string(//s:TasaIVA)'));
        self::assertSame('38', $xpath->evaluate('string(//s:IVA)'));
        self::assertSame('238', $xpath->evaluate('string(//s:MntTotal)'));
        $names = [];
        foreach ($xpath->query('//s:Detalle[1]/*') as $node) {
            $names[] = $node->localName;
        }
        self::assertSame(['NroLinDet', 'NmbItem', 'QtyItem', 'PrcItem', 'MontoItem'], $names);
        self::assertSame(0, $xpath->query('//s:TED | //s:TmstFirma | //s:Signature')->length);
    }

    public function testDecimalesYEnterosGrandesSinPerdida(): void
    {
        $datos = self::datos();
        $datos['detalle'] = [
            ['nombre' => 'Grande', 'cantidad' => '999999999999', 'precio_unitario' => '100'],
            ['nombre' => 'Fraccionario', 'cantidad' => '0.000001', 'precio_unitario' => '100000000'],
        ];
        $xpath = $this->xpath((new Invoice33Builder(self::emisor()))->build(9999999999, $datos));
        self::assertSame('100000000000000', $xpath->evaluate('string(//s:MntNeto)'));
        self::assertSame('19000000000000', $xpath->evaluate('string(//s:IVA)'));
        self::assertSame('119000000000000', $xpath->evaluate('string(//s:MntTotal)'));
        self::assertSame('9999999999', $xpath->evaluate('string(//s:Folio)'));
    }

    public function testFraccionesEnAmbosFactoresYLimiteDeTotal(): void
    {
        $datos = self::datos();
        $datos['detalle'] = [
            ['nombre' => 'Fracciones', 'cantidad' => '1.25', 'precio_unitario' => '2.4'],
            ['nombre' => 'Complemento', 'cantidad' => '1', 'precio_unitario' => '97'],
        ];
        $xpath = $this->xpath((new Invoice33Builder(self::emisor()))->build(1, $datos));
        self::assertSame('3', $xpath->evaluate('string(//s:Detalle[1]/s:MontoItem)'));
        self::assertSame('119', $xpath->evaluate('string(//s:MntTotal)'));

        $datos['detalle'] = [
            ['nombre' => 'Grande', 'cantidad' => '840336134453', 'precio_unitario' => '1000000'],
            ['nombre' => 'Complemento', 'cantidad' => '1', 'precio_unitario' => '781500'],
        ];
        $xpath = $this->xpath((new Invoice33Builder(self::emisor()))->build(1, $datos));
        self::assertSame('840336134453781500', $xpath->evaluate('string(//s:MntNeto)'));
        self::assertSame('159663865546218485', $xpath->evaluate('string(//s:IVA)'));
        self::assertSame('999999999999999985', $xpath->evaluate('string(//s:MntTotal)'));
    }

    public function testMultiplicacionConAcarreosYDecimalesDeSeisDigitos(): void
    {
        $datos = self::datos();
        $datos['detalle'] = [
            ['nombre' => 'Acarreos', 'cantidad' => '123456789.125', 'precio_unitario' => '800'],
            ['nombre' => 'Escala', 'cantidad' => '0.000125', 'precio_unitario' => '800000'],
        ];
        $xpath = $this->xpath((new Invoice33Builder(self::emisor()))->build(1, $datos));
        self::assertSame('98765431300', $xpath->evaluate('string(//s:Detalle[1]/s:MontoItem)'));
        self::assertSame('100', $xpath->evaluate('string(//s:Detalle[2]/s:MontoItem)'));
        self::assertSame('98765431400', $xpath->evaluate('string(//s:MntNeto)'));
        self::assertSame('18765431966', $xpath->evaluate('string(//s:IVA)'));
        self::assertSame('117530863366', $xpath->evaluate('string(//s:MntTotal)'));
    }

    public function testDocumentoIncompletoNoValidaContraXsd(): void
    {
        $xml = (new Invoice33Builder(self::emisor()))->build(1, self::datos());
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml));
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            self::assertFalse($document->schemaValidate(dirname(__DIR__) . '/schemas/sii/dte/DTE_v10.xsd'));
            $messages = implode(' ', array_map(static fn (\LibXMLError $error): string => $error->message, libxml_get_errors()));
            self::assertStringContainsString('Signature', $messages);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public function testFechasLimiteDelEsquema(): void
    {
        foreach (['2000-01-01', '2050-12-31'] as $fecha) {
            $datos = self::datos();
            $datos['fecha'] = $fecha;
            $xpath = $this->xpath((new Invoice33Builder(self::emisor()))->build(1, $datos));
            self::assertSame($fecha, $xpath->evaluate('string(//s:FchEmis)'));
        }
    }

    public function testLimiteDeLineas(): void
    {
        $datos = self::datos();
        $datos['detalle'] = array_fill(0, 60, ['nombre' => 'Item', 'cantidad' => '1', 'precio_unitario' => '100']);
        $xpath = $this->xpath((new Invoice33Builder(self::emisor()))->build(1, $datos));
        self::assertSame(60, $xpath->query('//s:Detalle')->length);
        self::assertSame('60', $xpath->evaluate('string(//s:Detalle[60]/s:NroLinDet)'));
        $datos['detalle'][] = $datos['detalle'][0];
        $this->expectException(SiiException::class);
        (new Invoice33Builder(self::emisor()))->build(1, $datos);
    }

    public static function entradasInvalidas(): array
    {
        $cases = [];
        $add = static function (string $name, array $emisor, int $folio, array $datos) use (&$cases): void {
            $cases[$name] = [$emisor, $folio, $datos];
        };
        $e = self::emisor();
        $d = self::datos();
        $add('folio cero', $e, 0, $d);
        $add('folio mayor a 10 dígitos', $e, 10000000000, $d);
        foreach (['2024-02-30', '2024-2-29', '0000-01-01', '1999-12-31', '2051-01-01'] as $date) {
            $bad = $d;
            $bad['fecha'] = $date;
            $add('fecha ' . $date, $e, 1, $bad);
        }
        $bad = $e;
        $bad['dv_emisor'] = '1';
        $add('RUT emisor', $bad, 1, $d);
        $bad = $d;
        $bad['receptor']['dv'] = '1';
        $add('RUT receptor', $e, 1, $bad);
        $bad = $d;
        $bad['receptor']['rut'] = '98.765.432';
        $add('RUT con formato no admitido', $e, 1, $bad);
        $bad = $e;
        unset($bad['codigo_actividad']);
        $add('actividad ausente', $bad, 1, $d);
        $bad = $e;
        $bad['codigo_actividad'] = '0';
        $add('actividad cero', $bad, 1, $d);
        $bad = $e;
        $bad['telefono'] = '123';
        $add('campo emisor desconocido', $bad, 1, $d);
        $bad = $d;
        $bad['receptor']['email'] = 'a@b.cl';
        $add('campo receptor desconocido', $e, 1, $bad);
        $bad = $d;
        $bad['referencias'] = [];
        $add('referencias no soportadas', $e, 1, $bad);
        $bad = $d;
        $bad['folio'] = 1;
        $add('folio duplicado en datos', $e, 1, $bad);
        $bad = $d;
        $bad['tasa_iva'] = '18';
        $add('tasa no soportada', $e, 1, $bad);
        $bad = $d;
        unset($bad['tasa_iva']);
        $add('tasa ausente', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'] = [];
        $add('sin detalle', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'] = [1 => $d['detalle'][0]];
        $add('detalle no es lista', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'][0] = 'item';
        $add('detalle no es arreglo', $e, 1, $bad);
        foreach (['descuento_pct' => '0', 'exento' => false, 'recargo' => '0'] as $key => $value) {
            $bad = $d;
            $bad['detalle'][0][$key] = $value;
            $add('no soportado ' . $key, $e, 1, $bad);
        }
        foreach (['0', '-1', '+1', '1e2', '1,5', '0.0000001', '1000000000000', 1, 1.5, ''] as $value) {
            $bad = $d;
            $bad['detalle'][0]['cantidad'] = $value;
            $add('cantidad ' . var_export($value, true), $e, 1, $bad);
        }
        $bad = $d;
        unset($bad['detalle'][0]['precio_unitario']);
        $add('precio ausente', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'][0]['cantidad'] = '0.1';
        $bad['detalle'][0]['precio_unitario'] = '1';
        $add('peso fraccionario', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'][0]['cantidad'] = '1';
        $bad['detalle'][0]['precio_unitario'] = '101';
        $add('IVA fraccionario', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'][0]['cantidad'] = '10000000000';
        $bad['detalle'][0]['precio_unitario'] = '100000000';
        $add('monto demasiado grande', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'][0]['cantidad'] = '9999999999';
        $bad['detalle'][0]['precio_unitario'] = '100000000';
        $bad['detalle'][] = ['nombre' => 'Otro', 'cantidad' => '1', 'precio_unitario' => '100000000'];
        $add('suma demasiado grande', $e, 1, $bad);
        $bad = $d;
        $bad['detalle'][0]['cantidad'] = '9000000000';
        $bad['detalle'][0]['precio_unitario'] = '100000000';
        $add('total demasiado grande', $e, 1, $bad);
        foreach (['', '   ', str_repeat('a', 81), "Error\x01", "Error\xC3", 'Emoji 😀'] as $value) {
            $bad = $d;
            $bad['detalle'][0]['nombre'] = $value;
            $add('texto ' . bin2hex($value), $e, 1, $bad);
        }

        return $cases;
    }

    /** @dataProvider entradasInvalidas */
    public function testRechazaEntradasInvalidas(array $emisor, int $folio, array $datos): void
    {
        $this->expectException(SiiException::class);
        (new Invoice33Builder($emisor))->build($folio, $datos);
    }
}
