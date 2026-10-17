<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Utils\RutHelper;

class RutHelperTest extends TestCase
{
    public function testValidaRutCorrectoEnDiversosFormatos(): void
    {
        $this->assertTrue(RutHelper::validate('12345678-5'));
        $this->assertTrue(RutHelper::validate('123456785'));
        $this->assertTrue(RutHelper::validate('11.222.333-9'));
        $this->assertTrue(RutHelper::validate('76354771-k'));
        $this->assertTrue(RutHelper::validate(' 12.345.678-5 '));
    }

    public function testRechazaRutIncorrecto(): void
    {
        $this->assertFalse(RutHelper::validate('12345678-0'));
        $this->assertFalse(RutHelper::validate('11.222.333-0'));
        $this->assertFalse(RutHelper::validate('12345678-'));
        $this->assertFalse(RutHelper::validate('abcdefgh'));
        $this->assertFalse(RutHelper::validate('12345678901'));
        $this->assertFalse(RutHelper::validate(''));
    }

    public function testCalculaDv(): void
    {
        $this->assertSame('5', RutHelper::calcularDv(12345678));
        $this->assertSame('K', RutHelper::calcularDv(76354771));
        $this->assertSame('0', RutHelper::calcularDv(0));
        $this->assertSame('6', RutHelper::calcularDv(11));
    }

    public function testFormatea(): void
    {
        $this->assertSame('12345678-5', RutHelper::format('12345678', '5'));
        $this->assertSame('12345678-K', RutHelper::format('12.345.678', 'k'));
        $this->assertSame('12345678-9', RutHelper::format('12.345.678', '9'));
    }

    public function testParseaRutConYSinFormato(): void
    {
        $this->assertSame(
            ['rut' => '12345678', 'dv' => '5'],
            RutHelper::parse('12345678-5')
        );

        $this->assertSame(
            ['rut' => '12345678', 'dv' => '5'],
            RutHelper::parse('12.345.678-5')
        );

        $this->assertSame(
            ['rut' => '76354771', 'dv' => 'K'],
            RutHelper::parse('76354771-k')
        );
    }

    public function testParseaEntradaInvalidaComoPartesVacias(): void
    {
        $this->assertSame(
            ['rut' => '', 'dv' => ''],
            RutHelper::parse('rut-invalido')
        );
    }
}
