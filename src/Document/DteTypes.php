<?php

declare(strict_types=1);

namespace SiiDte\Document;

/**
 * Tipos de Documentos Tributarios Electrónicos (DTE) del SII de Chile.
 * Referencia: Resolución Ex. SII N°45 de 2003 y sus modificaciones.
 */
class DteTypes
{
    // Documentos de Venta (Emisor vende / presta servicio)
    public const FACTURA_ELECTRONICA              = 33;  // Afecta a IVA
    public const FACTURA_NO_AFECTA_ELECTRONICA    = 34;  // Exenta de IVA
    public const LIQUIDACION_FACTURA_ELECTRONICA  = 43;  // Para mandatarios/comisionistas
    public const BOLETA_ELECTRONICA               = 39;  // Consumidor final, afecta IVA
    public const BOLETA_NO_AFECTA_ELECTRONICA     = 41;  // Consumidor final, exenta IVA

    // Documentos de Compra (Emisor compra / receptor es vendedor)
    public const FACTURA_COMPRA_ELECTRONICA       = 46;  // Retención IVA por comprador

    // Documentos de Traslado
    public const GUIA_DESPACHO_ELECTRONICA        = 52;  // Acompañar mercadería en traslado

    // Documentos de Ajuste
    public const NOTA_DEBITO_ELECTRONICA          = 56;  // Aumenta monto de factura original
    public const NOTA_CREDITO_ELECTRONICA         = 61;  // Disminuye monto / anula factura

    // Documentos de Exportación
    public const FACTURA_EXPORTACION_ELECTRONICA  = 110; // Exportación definitiva
    public const NOTA_DEBITO_EXPORTACION          = 111; // Ajuste exportación (aumento)
    public const NOTA_CREDITO_EXPORTACION         = 112; // Ajuste exportación (disminución)

    // Metadatos por tipo

    private const META = [
        33  => ['nombre' => 'Factura Electrónica',              'iva' => true,  'exportacion' => false, 'traslado' => false, 'boleta' => false],
        34  => ['nombre' => 'Factura No Afecta Electrónica',    'iva' => false, 'exportacion' => false, 'traslado' => false, 'boleta' => false],
        39  => ['nombre' => 'Boleta Electrónica',               'iva' => true,  'exportacion' => false, 'traslado' => false, 'boleta' => true],
        41  => ['nombre' => 'Boleta No Afecta Electrónica',     'iva' => false, 'exportacion' => false, 'traslado' => false, 'boleta' => true],
        43  => ['nombre' => 'Liquidación Factura Electrónica',  'iva' => true,  'exportacion' => false, 'traslado' => false, 'boleta' => false],
        46  => ['nombre' => 'Factura de Compra Electrónica',    'iva' => true,  'exportacion' => false, 'traslado' => false, 'boleta' => false],
        52  => ['nombre' => 'Guía de Despacho Electrónica',     'iva' => true,  'exportacion' => false, 'traslado' => true,  'boleta' => false],
        56  => ['nombre' => 'Nota de Débito Electrónica',       'iva' => true,  'exportacion' => false, 'traslado' => false, 'boleta' => false],
        61  => ['nombre' => 'Nota de Crédito Electrónica',      'iva' => true,  'exportacion' => false, 'traslado' => false, 'boleta' => false],
        110 => ['nombre' => 'Factura de Exportación Electrónica','iva' => false, 'exportacion' => true,  'traslado' => false, 'boleta' => false],
        111 => ['nombre' => 'Nota de Débito de Exportación',    'iva' => false, 'exportacion' => true,  'traslado' => false, 'boleta' => false],
        112 => ['nombre' => 'Nota de Crédito de Exportación',   'iva' => false, 'exportacion' => true,  'traslado' => false, 'boleta' => false],
    ];

    public static function getName(int $tipo): string
    {
        return self::META[$tipo]['nombre'] ?? "DTE Tipo {$tipo}";
    }

    public static function aplicaIva(int $tipo): bool
    {
        return self::META[$tipo]['iva'] ?? false;
    }

    public static function esExportacion(int $tipo): bool
    {
        return self::META[$tipo]['exportacion'] ?? false;
    }

    public static function esTraslado(int $tipo): bool
    {
        return self::META[$tipo]['traslado'] ?? false;
    }

    public static function esBoleta(int $tipo): bool
    {
        return self::META[$tipo]['boleta'] ?? false;
    }

    /** Indica si el tipo requiere datos de referencia a otro DTE */
    public static function requiereReferencia(int $tipo): bool
    {
        return in_array($tipo, [56, 61, 111, 112]);
    }

    public static function todos(): array
    {
        return array_keys(self::META);
    }
}
