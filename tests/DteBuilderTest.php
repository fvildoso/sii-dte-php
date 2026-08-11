<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Document\DteBuilder;
use SiiDte\Document\DteTypes;

/**
 * Pruebas para el constructor de XML de DTE.
 */
class DteBuilderTest extends TestCase
{
    private array $config;

    /**
     * Configura el entorno de pruebas.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->config = [
            'rut_emisor'        => '12345678',
            'dv_emisor'         => '9',
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
     *
     * @return void
     */
    public function testBuildGeneraXmlValido(): void
    {
        $builder = new DteBuilder($this->config);

        $datos = [
            'folio' => 1,
            'fecha' => '2024-01-15',
            'receptor' => [
                'rut'          => '98765432',
                'dv'           => '1',
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
        $this->assertStringContainsString('12345678-9', $xml);
        $this->assertStringContainsString('98765432-1', $xml);
        $this->assertStringContainsString('Producto A', $xml);
    }

    /**
     * Verifica que los montos totales e impuestos se calculen correctamente.
     *
     * @return void
     */
    public function testCalculaTotalesConIva(): void
    {
        $builder = new DteBuilder($this->config);

        $datos = [
            'folio' => 1,
            'fecha' => '2024-01-15',
            'receptor' => [
                'rut' => '98765432', 'dv' => '1',
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
     *
     * @return void
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
     *
     * @return void
     */
    public function testTiposNoAfectos(): void
    {
        $this->assertFalse(DteTypes::aplicaIva(DteTypes::FACTURA_NO_AFECTA_ELECTRONICA));
        $this->assertTrue(DteTypes::aplicaIva(DteTypes::FACTURA_ELECTRONICA));
        $this->assertSame('Factura Electrónica', DteTypes::getName(33));
    }
}
