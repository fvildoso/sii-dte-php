<?php

/**
 * Ejemplo seguro: generar localmente un borrador de factura (tipo 33).
 *
 * No envía documentos. El XML no tiene TED, firma, sobre ni validación XSD.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SiiDte\Document\DteTypes;
use SiiDte\Document\DteBuilder;

// ============================================================
// 1. Configurar el borrador con los datos del emisor
// ============================================================
$builder = new DteBuilder([
    'rut_emisor'         => '76123456',
    'dv_emisor'          => '0',
    'razon_social'       => 'Mi Empresa SpA',
    'giro'               => 'Venta de Software',
    'direccion'          => 'Av. Providencia 1234',
    'ciudad'             => 'Santiago',
]);

// ============================================================
// 2. Definir los datos de la factura
// ============================================================
$datos = [
    'folio'    => 1,                    // Valor de ejemplo para el borrador; no se reserva
    'fecha'    => date('Y-m-d'),        // Fecha de emisión

    // Datos del receptor (cliente)
    'receptor' => [
        'rut'          => '98765432',
        'dv'           => '5',
        'razon_social' => 'Cliente Ejemplo Ltda',
        'giro'         => 'Servicios de TI',
        'direccion'    => 'Calle Falsa 123',
        'ciudad'       => 'Santiago',
    ],

    // Líneas de detalle
    'detalle' => [
        [
            'nombre'           => 'Licencia Software Anual',
            'descripcion'      => 'Licencia Pro 2024 - 1 usuario',
            'cantidad'         => 2,
            'unidad'           => 'UN',
            'precio_unitario'  => 119000,   // Solo dato del borrador; cálculo tributario no validado
        ],
        [
            'nombre'           => 'Soporte Técnico',
            'cantidad'         => 1,
            'precio_unitario'  => 59500,
        ],
    ],

    // Referencias opcionales (ej: a una guía de despacho)
    'referencias' => [
        // [
        //     'tipo_doc'   => 52,           // Guía de Despacho
        //     'folio'      => 100,
        //     'fecha'      => '2024-01-10',
        //     'razon'      => 'Según guía de despacho',
        // ],
    ],
];

// ============================================================
// 3. Generar vista previa local: no reserva folios ni contacta al SII.
// ============================================================
$borrador = $builder->build(DteTypes::FACTURA_ELECTRONICA, $datos);
echo 'Borrador local generado (' . strlen($borrador) . " bytes ISO-8859-1). No se envió al SII.\n";
