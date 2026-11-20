<?php

declare(strict_types=1);

namespace SiiDte\Utils;

use SiiDte\Exceptions\SiiException;

/**
 * Aritmética decimal exacta sobre cadenas (bcmath). Evita errores de coma flotante
 * en montos tributarios. El redondeo es "half away from zero", el usual del SII.
 */
final class Decimal
{
    private const WORK_SCALE = 12;

    /**
     * Normaliza un valor numérico (int, string decimal o float) a una cadena canónica.
     *
     * @throws SiiException Si el valor no es numérico o excede los decimales permitidos
     */
    public static function parse(mixed $value, string $field, int $maxFraction = 6): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) {
                throw new SiiException("{$field} no es un número finito.");
            }
            $value = rtrim(rtrim(number_format($value, $maxFraction, '.', ''), '0'), '.');
        }
        if (!is_string($value) || preg_match('/^-?[0-9]{1,18}(?:\.[0-9]+)?$/D', $value) !== 1) {
            throw new SiiException("{$field} debe ser un número decimal.");
        }
        $negative = $value[0] === '-';
        $unsigned = $negative ? substr($value, 1) : $value;
        [$integer, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        if (strlen($fraction) > $maxFraction) {
            throw new SiiException("{$field} admite como máximo {$maxFraction} decimales.");
        }
        $integer = ltrim($integer, '0') ?: '0';
        $result = $integer . ($fraction !== '' ? '.' . $fraction : '');

        return ($negative && $result !== '0' ? '-' : '') . $result;
    }

    public static function add(string $left, string $right): string
    {
        return self::trim(bcadd($left, $right, self::WORK_SCALE));
    }

    public static function sub(string $left, string $right): string
    {
        return self::trim(bcsub($left, $right, self::WORK_SCALE));
    }

    public static function mul(string $left, string $right): string
    {
        return self::trim(bcmul($left, $right, self::WORK_SCALE));
    }

    public static function div(string $left, string $right): string
    {
        if (self::isZero($right)) {
            throw new SiiException('División por cero.');
        }

        return self::trim(bcdiv($left, $right, self::WORK_SCALE));
    }

    public static function cmp(string $left, string $right): int
    {
        return bccomp($left, $right, self::WORK_SCALE);
    }

    public static function isZero(string $value): bool
    {
        return self::cmp($value, '0') === 0;
    }

    public static function isNegative(string $value): bool
    {
        return self::cmp($value, '0') < 0;
    }

    /** Redondea half away from zero a $scale decimales. */
    public static function round(string $value, int $scale = 0): string
    {
        $negative = self::isNegative($value);
        $absolute = $negative ? substr($value, 1) : $value;
        $half = '0.' . str_repeat('0', $scale) . '5';
        $rounded = bcadd($absolute, $half, $scale);

        return self::trim(($negative && !self::isZero($rounded) ? '-' : '') . $rounded);
    }

    /** Elimina ceros sobrantes: "10.500000" => "10.5", "-0" => "0". */
    public static function trim(string $value): string
    {
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }
        if ($value === '' || $value === '-' || $value === '-0') {
            return '0';
        }

        return $value;
    }
}
