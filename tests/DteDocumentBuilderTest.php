<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Document\DteDocumentBuilder;
use SiiDte\Document\TedBuilder;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\CafInspector;
use SiiDte\Tests\Support\SyntheticSii;

final class DteDocumentBuilderTest extends TestCase
{
    private const RECEPTOR = [
        'rut' => '8414240', 'dv' => '9', 'razon_social' => 'Cliente café & té SpA',
        'giro' => 'Comercio', 'direccion' => 'Calle Test 1', 'comuna' => 'Santiago',
    ];

    private function builder(): DteDocumentBuilder
    {
        return new DteDocumentBuilder(SyntheticSii::emisorConfig());
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, string>}> */
    public static function documentos(): array
    {
        $base = ['folio' => 7, 'fecha' => '2026-11-20'];
        $ref = ['tipo_doc' => '33', 'folio' => '5', 'fecha' => '2026-11-10', 'codigo' => 1, 'razon' => 'Anula factura'];

        return [
            '33 factura afecta y exenta con descuento' => [
                $base + ['tipo' => 33, 'receptor' => self::RECEPTOR, 'forma_pago' => 1, 'medio_pago' => 'EF', 'detalle' => [
                    ['nombre' => 'Producto A', 'cantidad' => '3', 'precio' => '1990', 'descuento_pct' => 10, 'codigos' => [['tipo' => 'INT1', 'valor' => 'A-1']]],
                    ['nombre' => 'Servicio exento', 'cantidad' => 1, 'precio' => 5000, 'exento' => true],
                ]],
                ['neto' => '5373', 'iva' => '1021', 'exento' => '5000', 'total' => '11394'],
            ],
            '33 con descuento global e impuesto adicional' => [
                $base + ['tipo' => 33, 'receptor' => self::RECEPTOR, 'impuestos' => [271 => '18'],
                    'descuentos_recargos' => [['tipo' => 'D', 'valor_tipo' => '%', 'valor' => '10', 'glosa' => 'Dcto cliente']],
                    'detalle' => [
                        ['nombre' => 'Bebida', 'cantidad' => 10, 'precio' => 1000, 'impuesto' => 271],
                        ['nombre' => 'Otro', 'cantidad' => 1, 'precio' => 1000],
                    ]],
                ['neto' => '9900', 'iva' => '1881', 'exento' => '0', 'total' => '13401'],
            ],
            '34 factura exenta' => [
                $base + ['tipo' => 34, 'receptor' => self::RECEPTOR, 'detalle' => [['nombre' => 'Honorarios', 'cantidad' => 1, 'precio' => 100000]]],
                ['neto' => '0', 'iva' => '0', 'exento' => '100000', 'total' => '100000'],
            ],
            '39 boleta con IVA incluido' => [
                $base + ['tipo' => 39, 'detalle' => [['nombre' => 'Café', 'cantidad' => 2, 'precio' => 1500], ['nombre' => 'Pan', 'cantidad' => 1, 'precio' => 990]]],
                ['neto' => '3353', 'iva' => '637', 'exento' => '0', 'total' => '3990'],
            ],
            '41 boleta exenta' => [
                $base + ['tipo' => 41, 'receptor' => ['rut' => '66666666-6'], 'detalle' => [['nombre' => 'Libro', 'cantidad' => 1, 'precio' => 12000]]],
                ['neto' => '0', 'iva' => '0', 'exento' => '12000', 'total' => '12000'],
            ],
            '43 liquidación factura' => [
                $base + ['tipo' => 43, 'receptor' => self::RECEPTOR,
                    'comisiones' => [['tipo' => 'C', 'glosa' => 'Comisión', 'tasa' => 10, 'neto' => 1000, 'iva' => 190]],
                    'detalle' => [['nombre' => 'Venta liquidada', 'cantidad' => 1, 'precio' => 10000, 'tipo_doc_liq' => '33']]],
                ['neto' => '10000', 'iva' => '1900', 'exento' => '0', 'total' => '10710'],
            ],
            '46 factura de compra con IVA retenido' => [
                $base + ['tipo' => 46, 'receptor' => self::RECEPTOR, 'impuestos' => [15 => '19'],
                    'detalle' => [['nombre' => 'Chatarra', 'cantidad' => 4, 'precio' => 2500, 'impuesto' => 15]]],
                ['neto' => '10000', 'iva' => '1900', 'exento' => '0', 'total' => '10000'],
            ],
            '52 guía de despacho' => [
                $base + ['tipo' => 52, 'ind_traslado' => 1, 'receptor' => self::RECEPTOR,
                    'transporte' => ['patente' => 'AB1234', 'rut_transportista' => '76123456-0',
                        'chofer' => ['rut' => '12345678-5', 'nombre' => 'Juan Pérez'],
                        'direccion_destino' => 'Av. Destino 55', 'comuna_destino' => 'Providencia'],
                    'detalle' => [['nombre' => 'Mercadería', 'cantidad' => 5, 'precio' => 2000]]],
                ['neto' => '10000', 'iva' => '1900', 'exento' => '0', 'total' => '11900'],
            ],
            '52 guía sin precios' => [
                $base + ['tipo' => 52, 'ind_traslado' => 5, 'receptor' => self::RECEPTOR, 'detalle' => [['nombre' => 'Traslado interno', 'cantidad' => 5]]],
                ['neto' => '0', 'iva' => '0', 'exento' => '0', 'total' => '0'],
            ],
            '56 nota de débito' => [
                $base + ['tipo' => 56, 'receptor' => self::RECEPTOR, 'referencias' => [['codigo' => 3] + $ref],
                    'detalle' => [['nombre' => 'Ajuste de precio', 'cantidad' => 1, 'precio' => 1000]]],
                ['neto' => '1000', 'iva' => '190', 'exento' => '0', 'total' => '1190'],
            ],
            '61 nota de crédito' => [
                $base + ['tipo' => 61, 'ind_no_rebaja' => true, 'receptor' => self::RECEPTOR, 'referencias' => [$ref],
                    'detalle' => [['nombre' => 'Anulación', 'cantidad' => 1, 'precio' => 5000]]],
                ['neto' => '5000', 'iva' => '950', 'exento' => '0', 'total' => '5950'],
            ],
            '61 nota de crédito que corrige texto (sin montos)' => [
                $base + ['tipo' => 61, 'receptor' => self::RECEPTOR,
                    'referencias' => [['codigo' => 2, 'razon' => 'Corrige giro del receptor'] + $ref],
                    'detalle' => [['nombre' => 'Corrige giro del receptor']]],
                ['neto' => '0', 'iva' => '0', 'exento' => '0', 'total' => '0'],
            ],
            '56 nota de débito que anula una nota de corrección' => [
                $base + ['tipo' => 56, 'receptor' => self::RECEPTOR,
                    'referencias' => [['tipo_doc' => '61', 'codigo' => 1, 'razon' => 'Anula nota de crédito'] + $ref],
                    'detalle' => [['nombre' => 'Corrige giro del receptor']]],
                ['neto' => '0', 'iva' => '0', 'exento' => '0', 'total' => '0'],
            ],
            '39 boleta del set de pruebas con CodRef SET' => [
                $base + ['tipo' => 39, 'referencias' => [['codigo' => 'SET', 'razon' => 'CASO-5']],
                    'detalle' => [['nombre' => 'Arroz', 'cantidad' => 5, 'precio' => 700, 'unidad' => 'Kg']]],
                ['neto' => '2941', 'iva' => '559', 'exento' => '0', 'total' => '3500'],
            ],
            '110 factura de exportación' => [
                $base + ['tipo' => 110, 'receptor' => ['rut' => '55555555-5', 'razon_social' => 'Foreign Buyer Inc', 'direccion' => 'Main St 1', 'comuna' => 'Miami'],
                    'exportacion' => ['moneda' => 'DOLAR USA', 'decimales' => 2,
                        'aduana' => ['CodModVenta' => 1, 'CodClauVenta' => 1, 'CodViaTransp' => 1, 'CodPaisRecep' => 225, 'CodPaisDestin' => 225]],
                    'detalle' => [['nombre' => 'Vino', 'cantidad' => '100.5', 'precio' => '12.35']]],
                ['neto' => '0', 'iva' => '0', 'exento' => '1241.18', 'total' => '1241.18'],
            ],
            '111 nota débito exportación' => [
                $base + ['tipo' => 111, 'receptor' => ['rut' => '55555555-5', 'razon_social' => 'Foreign Buyer Inc'],
                    'exportacion' => ['moneda' => 'DOLAR USA', 'decimales' => 2],
                    'referencias' => [['tipo_doc' => '110', 'folio' => '3', 'fecha' => '2026-11-10', 'codigo' => 3]],
                    'detalle' => [['nombre' => 'Flete adicional', 'cantidad' => 1, 'precio' => '50.5']]],
                ['neto' => '0', 'iva' => '0', 'exento' => '50.5', 'total' => '50.5'],
            ],
            '112 nota crédito exportación' => [
                $base + ['tipo' => 112, 'receptor' => ['rut' => '55555555-5', 'razon_social' => 'Foreign Buyer Inc'],
                    'exportacion' => ['moneda' => 'DOLAR USA', 'decimales' => 2],
                    'referencias' => [['tipo_doc' => '110', 'folio' => '3', 'fecha' => '2026-11-10', 'codigo' => 1]],
                    'detalle' => [['nombre' => 'Devolución', 'cantidad' => 1, 'precio' => '10']]],
                ['neto' => '0', 'iva' => '0', 'exento' => '10', 'total' => '10'],
            ],
        ];
    }

    /**
     * @dataProvider documentos
     * @param array<string, mixed> $doc
     * @param array<string, string> $esperado
     */
    public function testCalculaMontosYPasaTimbreFirmaSobreYXsd(array $doc, array $esperado): void
    {
        $totales = $this->builder()->calcular($doc);
        foreach ($esperado as $campo => $valor) {
            self::assertSame($valor, $totales[$campo], "Monto {$campo}");
        }

        $xml = $this->builder()->build($doc);
        $resultado = SyntheticSii::get()->emitir($xml, $doc['tipo'], $doc['folio']);
        self::assertStringContainsString('<TED version="1.0">', $resultado['dte']);
        self::assertStringContainsString('<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">', $resultado['sobre']);

        // El SII elige el XSD por xsi:schemaLocation (sin él: "SCH-00001: Invalid Schema Name").
        $esquema = in_array($doc['tipo'], [39, 41], true) ? 'EnvioBOLETA_v11.xsd' : 'EnvioDTE_v10.xsd';
        self::assertStringContainsString('xsi:schemaLocation="http://www.sii.cl/SiiDte ' . $esquema . '"', $resultado['sobre']);
        // El SII rechaza líneas de más de 4090 caracteres ("CHR-00002: Line too long").
        $lineaMasLarga = max(array_map('strlen', explode("\n", $resultado['sobre'])));
        self::assertLessThan(4090, $lineaMasLarga, 'El sobre tiene una línea demasiado larga para el SII.');
        // El DTE declara xmlns:xsi antes de firmarse para que su firma siga válida dentro del sobre.
        self::assertStringContainsString('xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"', $resultado['dte']);

        $verificacion = new TedBuilder(new CafInspector())->verify($resultado['dte']);
        self::assertTrue($verificacion['frmt_verificada']);
        self::assertTrue($verificacion['datos_coinciden']);
    }

    public function testLineaSinPrecioDeNotaNoEmitePrcItemNiCantidad(): void
    {
        $xml = $this->builder()->build([
            'tipo' => 61, 'folio' => 1, 'fecha' => '2026-11-20', 'receptor' => self::RECEPTOR,
            'referencias' => [['tipo_doc' => '33', 'folio' => '5', 'fecha' => '2026-11-10', 'codigo' => 2, 'razon' => 'Corrige giro']],
            'detalle' => [['nombre' => 'Corrige giro']],
        ]);
        self::assertStringNotContainsString('<PrcItem>', $xml);
        self::assertStringNotContainsString('<QtyItem>', $xml);
        self::assertStringContainsString('<MontoItem>0</MontoItem>', $xml);
        self::assertStringContainsString('<MntTotal>0</MntTotal>', $xml);
    }

    public function testBoletaAceptaCodRefTextoPeroValidaLargo(): void
    {
        $doc = ['tipo' => 39, 'folio' => 1, 'fecha' => '2026-11-20',
            'referencias' => [['codigo' => 'SET', 'razon' => 'CASO-1']],
            'detalle' => [['nombre' => 'Café', 'cantidad' => 1, 'precio' => 1000]]];
        self::assertStringContainsString('<CodRef>SET</CodRef>', $this->builder()->build($doc));

        $doc['referencias'][0]['codigo'] = str_repeat('X', 19);
        $this->expectException(SiiException::class);
        $this->expectExceptionMessageMatches('/referencias.codigo/');
        $this->builder()->build($doc);
    }

    public function testRedondeaHalfUpYNoUsaFloat(): void
    {
        $r = $this->builder()->calcular([
            'tipo' => 33, 'folio' => 1, 'fecha' => '2026-11-20', 'receptor' => self::RECEPTOR,
            'detalle' => [['nombre' => 'X', 'cantidad' => '0.5', 'precio' => '1']],
        ]);
        self::assertSame('1', $r['neto']);   // 0.5 → 1
        self::assertSame('0', $r['iva']);    // 0.19 → 0

        $r = $this->builder()->calcular([
            'tipo' => 33, 'folio' => 1, 'fecha' => '2026-11-20', 'receptor' => self::RECEPTOR,
            'detalle' => [['nombre' => 'X', 'cantidad' => 1, 'precio' => 2632]],
        ]);
        self::assertSame('500', $r['iva']);  // 2632 * 0.19 = 500.08
        self::assertSame('3132', $r['total']);
    }

    public function testTextoConAcentosYEntidadesSeConservaEnLatin1(): void
    {
        $xml = $this->builder()->build([
            'tipo' => 33, 'folio' => 1, 'fecha' => '2026-11-20', 'receptor' => self::RECEPTOR,
            'detalle' => [['nombre' => 'Niño <b> & "ñandú"', 'cantidad' => 1, 'precio' => 100]],
        ]);
        self::assertStringContainsString("Cliente caf\xE9 &amp; t\xE9 SpA", $xml);
        self::assertStringContainsString("Ni\xF1o &lt;b&gt; &amp; &quot;\xF1and\xFA&quot;", $xml);
    }

    /** @dataProvider entradasInvalidas */
    public function testRechazaEntradasInvalidas(array $doc, string $mensaje): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessageMatches($mensaje);
        $this->builder()->build($doc);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function entradasInvalidas(): array
    {
        $ok = ['tipo' => 33, 'folio' => 1, 'fecha' => '2026-11-20', 'receptor' => self::RECEPTOR,
            'detalle' => [['nombre' => 'X', 'cantidad' => 1, 'precio' => 100]]];

        return [
            'tipo desconocido' => [['tipo' => 99] + $ok, '/tipo de DTE/'],
            'folio cero' => [['folio' => 0] + $ok, '/folio/'],
            'fecha inválida' => [['fecha' => '2026-02-30'] + $ok, '/fecha/'],
            'sin detalle' => [['detalle' => []] + $ok, '/detalle/'],
            'campo desconocido en línea' => [['detalle' => [['nombre' => 'X', 'cantidad' => 1, 'precio' => 1, 'foo' => 1]]] + $ok, '/no soportados/'],
            'cantidad negativa' => [['detalle' => [['nombre' => 'X', 'cantidad' => -1, 'precio' => 1]]] + $ok, '/positiva/'],
            'descuento mayor al monto' => [['detalle' => [['nombre' => 'X', 'cantidad' => 1, 'precio' => 10, 'descuento_monto' => 11]]] + $ok, '/negativo/'],
            'pct y monto a la vez' => [['detalle' => [['nombre' => 'X', 'cantidad' => 1, 'precio' => 10, 'descuento_pct' => 1, 'descuento_monto' => 1]]] + $ok, '/no ambos/'],
            'nota sin referencia' => [['tipo' => 61] + $ok, '/referencia/'],
            'guía sin ind_traslado' => [['tipo' => 52] + $ok, '/ind_traslado/'],
            'rut inválido' => [['receptor' => ['rut' => '12345678', 'dv' => '0'] + self::RECEPTOR] + $ok, '/RUT/'],
            'nombre demasiado largo' => [['detalle' => [['nombre' => str_repeat('a', 81), 'cantidad' => 1, 'precio' => 1]]] + $ok, '/80 caracteres/'],
            'exportación sin moneda' => [['tipo' => 110] + $ok, '/exportacion/'],
            'precio ausente fuera de guía' => [['detalle' => [['nombre' => 'X', 'cantidad' => 1]]] + $ok, '/precio/'],
            'precio ausente en boleta' => [['tipo' => 39, 'detalle' => [['nombre' => 'X', 'cantidad' => 1]]] + $ok, '/precio/'],
            'CodRef texto fuera de boleta' => [['tipo' => 61, 'referencias' => [['tipo_doc' => '33', 'folio' => '1', 'fecha' => '2026-11-10', 'codigo' => 'SET']]] + $ok, '/referencias.codigo/'],
            'impuesto no declarado' => [['detalle' => [['nombre' => 'X', 'cantidad' => 1, 'precio' => 1, 'impuesto' => 27]]] + $ok, '/impuestos/'],
        ];
    }
}
