<?php

declare(strict_types=1);

namespace SiiDte\Utils;

/**
 * Utilidades para validación y formateo de RUT chileno.
 */
class RutHelper
{
    /**
     * Formatea RUT en la forma "12345678-9".
     */
    public static function format(string $rut, string $dv): string
    {
        $clean = preg_replace('/[^0-9kK]/', '', $rut);
        return $clean . '-' . strtoupper($dv);
    }

    /**
     * Valida un RUT chileno completo (con DV).
     * Acepta formatos: "12345678-9", "123456789", "12.345.678-9"
     */
    public static function validate(string $rut): bool
    {
        // Limpiar puntos, guiones y espacios
        $clean = preg_replace('/[\.\s]/', '', strtoupper(trim($rut)));

        if (!preg_match('/^(\d{1,8})-?([0-9K])$/', $clean, $m)) {
            return false;
        }

        $numero = (int) $m[1];
        $dvIngresado = $m[2];

        return strtoupper(self::calcularDv($numero)) === $dvIngresado;
    }

    /**
     * Calcula el dígito verificador de un RUT.
     */
    public static function calcularDv(int $rut): string
    {
        $suma    = 0;
        $factor  = 2;
        $numero  = $rut;

        while ($numero > 0) {
            $suma   += ($numero % 10) * $factor;
            $numero  = (int) ($numero / 10);
            $factor  = $factor === 7 ? 2 : $factor + 1;
        }

        $resto = $suma % 11;
        $dv    = 11 - $resto;

        return match ($dv) {
            11 => '0',
            10 => 'K',
            default => (string) $dv,
        };
    }

    /**
     * Separa un RUT completo en número y DV.
     * @return array{rut: string, dv: string}
     */
    public static function parse(string $rut): array
    {
        $clean = preg_replace('/[\.\s]/', '', strtoupper(trim($rut)));
        preg_match('/^(\d{1,8})-?([0-9K])$/', $clean, $m);

        return [
            'rut' => $m[1] ?? '',
            'dv'  => $m[2] ?? '',
        ];
    }
}
