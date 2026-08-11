<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Utils\RutHelper;

/**
 * Pruebas para la utilidad de validación y formateo de RUT chileno.
 */
class RutHelperTest extends TestCase
{
    /**
     * Verifica la validación de RUTs correctos en diversos formatos.
     */
    public function testValidaRutCorrecto(): void
    {
        $this->assertTrue(RutHelper::validate('12345678-5'));
        $this->assertTrue(RutHelper::validate('76354771-K'));
        $this->assertTrue(RutHelper::validate('11.222.333-9'));
    }

    /**
     * Verifica que se rechacen RUTs con dígito verificador incorrecto.
     */
    public function testRechazaRutIncorrecto(): void
    {
        $this->assertFalse(RutHelper::validate('12345678-0'));
        $this->assertFalse(RutHelper::validate('11.222.333-0'));
    }

    /**
     * Verifica el cálculo correcto del dígito verificador.
     */
    public function testCalculaDv(): void
    {
        $this->assertSame('5', RutHelper::calcularDv(12345678));
        $this->assertSame('K', RutHelper::calcularDv(76354771));
    }

    /**
     * Verifica el formateo de RUT al estándar "XXXXXXXX-X".
     */
    public function testFormatea(): void
    {
        $this->assertSame('12345678-5', RutHelper::format('12345678', '5'));
    }

    /**
     * Verifica la correcta separación de un RUT en cuerpo y dígito verificador.
     */
    public function testParsea(): void
    {
        $parsed = RutHelper::parse('12345678-5');
        $this->assertSame('12345678', $parsed['rut']);
        $this->assertSame('5', $parsed['dv']);
    }
}
