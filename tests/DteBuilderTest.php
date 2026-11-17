<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Document\DteBuilder;
use SiiDte\Document\DteTypes;
use SiiDte\Exceptions\SiiException;

/**
 * Pruebas para el constructor de XML de DTE.
 */
class DteBuilderTest extends TestCase
{
    private array $config;

    /**
     * Configura el entorno de pruebas.
     */
    protected function setUp(): void
    {
        $this->config = [
            'rut_emisor'        => '76123456',
            'dv_emisor'         => '0',
            'razon_social'      => 'Empresa Test SpA',
            'giro'              => 'Servicios de TI',
            'direccion'         => 'Av. Ejemplo 100',
            'ciudad'            => 'Santiago',
            'numero_resolucion' => 0,
            'fecha_resolucion'  => '2019-10-18',
            'cert_path'         => '/tmp/fake.p12',
            'cert_pass'         => 'pass',
            'ambiente'          => 'certificacion',
        ];
    }

    /**
     * Verifica que el XML generado contenga los datos básicos y la estructura correcta.
     */
    public function testBuildGeneraXmlValido(): void
    {
        $builder = new DteBuilder($this->config);

        $datos = [
            'folio' => 1,
            'fecha' => '2024-01-15',
            'receptor' => [
                'rut'          => '98765432',
                'dv'           => '5',
                'razon_social' => 'Cliente Test',
                'giro'         => 'Comercio',
                'direccion'    => 'Calle Test 1',
                'ciudad'       => 'Valparaíso',
            ],
            'detalle' => [
                [
                    'nombre'          => 'Producto A',
                    'cantidad'        => 2,
                    'precio_unitario' => 59500,
                ],
            ],
        ];

        $xml = $builder->build(DteTypes::FACTURA_ELECTRONICA, $datos);

        $this->assertStringContainsString('<DTE', $xml);
        $this->assertStringContainsString('<TipoDTE>33</TipoDTE>', $xml);
        $this->assertStringContainsString('<Folio>1</Folio>', $xml);
        $this->assertStringContainsString('76123456-0', $xml);
        $this->assertStringContainsString('98765432-5', $xml);
        $this->assertStringContainsString('Producto A', $xml);
    }

    public function testXmlDeclaradoLatin1CoincideConLosBytesYPreservaTildesYEscapes(): void
    {
        $builder = new DteBuilder($this->config);
        $datos = [
            'folio' => 2,
            'fecha' => '2024-01-15',
            'receptor' => [
                'rut' => '98765432',
                'dv' => '5',
                'razon_social' => 'Cliente SpA',
                'giro' => 'Comercio',
                'direccion' => 'Calle Uno 1',
                'ciudad' => 'Valparaíso',
            ],
            'detalle' => [[
                'nombre' => 'Té & <café>',
                'cantidad' => 1,
                'precio_unitario' => 100,
            ]],
        ];

        $xml = $builder->build(DteTypes::FACTURA_ELECTRONICA, $datos);
        $this->assertStringContainsString('encoding="ISO-8859-1"', $xml);
        $this->assertStringContainsString(iconv('UTF-8', 'ISO-8859-1', '<CmnaRecep>Valparaíso</CmnaRecep>'), $xml);

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml));
        $itemName = $document->getElementsByTagNameNS('http://www.sii.cl/SiiDte', 'NmbItem')->item(0);
        $this->assertNotNull($itemName);
        $this->assertSame('Té & <café>', $itemName->textContent);
    }

    public function testRechazaCaracteresNoRepresentablesSinPerdidaSilenciosa(): void
    {
        $this->expectException(SiiException::class);
        (new DteBuilder($this->config))->build(DteTypes::FACTURA_ELECTRONICA, [
            'folio' => 3,
            'fecha' => '2024-01-15',
            'receptor' => ['rut' => '98765432', 'dv' => '5', 'razon_social' => 'Cliente'],
            'detalle' => [['nombre' => 'Producto 😀', 'precio_unitario' => 100]],
        ]);
    }

    public function testRechazaRutfuenteInvalidoYFechaInvalida(): void
    {
        $builder = new DteBuilder($this->config);
        $data = [
            'folio' => 4,
            'fecha' => '2024-02-30',
            'receptor' => ['rut' => '98765432', 'dv' => '5', 'razon_social' => 'Cliente'],
            'detalle' => [['nombre' => 'Producto', 'precio_unitario' => 100]],
        ];

        try {
            $builder->build(DteTypes::FACTURA_ELECTRONICA, $data);
            $this->fail('Se esperaba rechazo de una fecha inexistente.');
        } catch (SiiException $exception) {
            $this->assertStringContainsString('fecha', $exception->getMessage());
        }

        $data['fecha'] = '2024-02-29';
        $data['receptor']['dv'] = '1';
        $this->expectException(SiiException::class);
        $builder->build(DteTypes::FACTURA_ELECTRONICA, $data);
    }

    /**
     * Verifica que los montos totales e impuestos se calculen correctamente.
     */
    public function testCalculaTotalesConIva(): void
    {
        $builder = new DteBuilder($this->config);

        $datos = [
            'folio' => 1,
            'fecha' => '2024-01-15',
            'receptor' => [
                'rut' => '98765432', 'dv' => '5',
                'razon_social' => 'Test',
            ],
            'detalle' => [
                [
                    'nombre'          => 'Item 1',
                    'cantidad'        => 1,
                    'precio_unitario' => 119000, // 100.000 neto + 19.000 IVA
                ],
            ],
        ];

        $xml = $builder->build(DteTypes::FACTURA_ELECTRONICA, $datos);

        $this->assertStringContainsString('<TasaIVA>19</TasaIVA>', $xml);
        $this->assertStringContainsString('<MntTotal>119000</MntTotal>', $xml);
    }

    /**
     * Verifica que se lance una excepción si no hay líneas de detalle.
     */
    public function testLanzaExcepcionSinDetalle(): void
    {
        $builder = new DteBuilder($this->config);

        $this->expectException(\SiiDte\Exceptions\SiiException::class);

        $builder->build(DteTypes::FACTURA_ELECTRONICA, [
            'folio'    => 1,
            'fecha'    => '2024-01-15',
            'receptor' => ['rut' => '1', 'dv' => '9', 'razon_social' => 'Test'],
            'detalle'  => [],
        ]);
    }

    /**
     * Verifica el comportamiento de los tipos de documentos no afectos a IVA.
     */
    public function testTiposNoAfectos(): void
    {
        $this->assertFalse(DteTypes::aplicaIva(DteTypes::FACTURA_NO_AFECTA_ELECTRONICA));
        $this->assertTrue(DteTypes::aplicaIva(DteTypes::FACTURA_ELECTRONICA));
        $this->assertSame('Factura Electrónica', DteTypes::getName(33));
    }
}
