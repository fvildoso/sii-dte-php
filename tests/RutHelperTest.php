<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Utils\RutHelper;

class RutHelperTest extends TestCase
{
    public function testValidaRutCorrecto(): void
    {
        $this->assertTrue(RutHelper::validate('12345678-9'));
        $this->assertTrue(RutHelper::validate('76354771-K'));
        $this->assertTrue(RutHelper::validate('11.222.333-4'));
    }

    public function testRechazaRutIncorrecto(): void
    {
        $this->assertFalse(RutHelper::validate('12345678-0'));
        $this->assertFalse(RutHelper::validate('11111111-1'));
    }

    public function testCalculaDv(): void
    {
        $this->assertSame('9', RutHelper::calcularDv(12345678));
        $this->assertSame('K', RutHelper::calcularDv(76354771));
    }

    public function testFormatea(): void
    {
        $this->assertSame('12345678-9', RutHelper::format('12345678', '9'));
    }

    public function testParsea(): void
    {
        $parsed = RutHelper::parse('12345678-9');
        $this->assertSame('12345678', $parsed['rut']);
        $this->assertSame('9', $parsed['dv']);
    }
}
