<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\SiiException;
use SiiDte\Utils\Decimal;

final class DecimalTest extends TestCase
{
    /** @dataProvider redondeos */
    public function testRedondeaHalfAwayFromZero(string $in, int $scale, string $out): void
    {
        self::assertSame($out, Decimal::round($in, $scale));
    }

    /** @return array<string, array{string, int, string}> */
    public static function redondeos(): array
    {
        return [
            '0.5 → 1' => ['0.5', 0, '1'], '0.49 → 0' => ['0.49', 0, '0'], '2.5 → 3' => ['2.5', 0, '3'],
            '-2.5 → -3' => ['-2.5', 0, '-3'], '1.005 a 2 dec' => ['1.005', 2, '1.01'], '1.0049 a 2 dec' => ['1.0049', 2, '1'],
            '1234.56 a 0' => ['1234.56', 0, '1235'], '-0.4 → 0' => ['-0.4', 0, '0'],
        ];
    }

    public function testAritmeticaExactaSinFloat(): void
    {
        self::assertSame('0.3', Decimal::add('0.1', '0.2'));
        self::assertSame('1190', Decimal::add('1000', Decimal::round(Decimal::div(Decimal::mul('1000', '19'), '100'))));
        self::assertSame('33.333333333333', Decimal::div('100', '3'));
        self::assertSame('100000000000000000', Decimal::mul('1000000000', '100000000'));
    }

    /** @dataProvider invalidos */
    public function testRechazaNumerosInvalidos(mixed $value): void
    {
        $this->expectException(SiiException::class);
        Decimal::parse($value, 'campo');
    }

    /** @return array<string, array{mixed}> */
    public static function invalidos(): array
    {
        return ['letras' => ['abc'], 'vacío' => [''], 'coma' => ['1,5'], 'exponencial' => ['1e3'], 'nan' => [NAN],
            'null' => [null], 'bool' => [true], '7 decimales' => ['0.1234567'], 'demasiado largo' => [str_repeat('9', 19)]];
    }

    public function testParseNormalizaYAceptaFloatEnteroYString(): void
    {
        self::assertSame('10.5', Decimal::parse('010.500', 'x'));
        self::assertSame('0', Decimal::parse('-0.0', 'x'));
        self::assertSame('2.5', Decimal::parse(2.5, 'x'));
        self::assertSame('42', Decimal::parse(42, 'x'));
    }
}
