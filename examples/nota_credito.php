<?php

/**
 * Ejemplo: Emitir una Nota de Crédito Electrónica (tipo 61)
 * para anular o corregir una Factura Electrónica.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SiiDte\SiiClient;
use SiiDte\Document\DteTypes;
use SiiDte\Exceptions\SiiException;

$client = new SiiClient([
    'rut_emisor'        => '12345678',
    'dv_emisor'         => '9',
    'razon_social'      => 'Mi Empresa SpA',
    'giro'              => 'Venta de Software',
    'direccion'         => 'Av. Providencia 1234',
    'ciudad'            => 'Santiago',
    'numero_resolucion' => 0,
    'fecha_resolucion'  => '2019-10-18',
    'cert_path'         => __DIR__ . '/certs/mi_certificado.p12',
    'cert_pass'         => 'mi_contraseña',
    'ambiente'          => 'certificacion',
]);

$datos = [
    'folio' => 1,
    'fecha' => date('Y-m-d'),

    'receptor' => [
        'rut'          => '98765432',
        'dv'           => '1',
        'razon_social' => 'Cliente Ejemplo Ltda',
        'giro'         => 'Servicios de TI',
        'direccion'    => 'Calle Falsa 123',
        'ciudad'       => 'Santiago',
    ],

    'detalle' => [
        [
            'nombre'          => 'Anulación Licencia Software',
            'cantidad'        => 1,
            'precio_unitario' => 238000,
        ],
    ],

    // Referencia a la factura que se anula/corrige (REQUERIDA en NC)
    'referencias' => [
        [
            'tipo_doc'   => 33,            // Factura Electrónica
            'folio'      => 150,           // Folio de la factura original
            'fecha'      => '2024-01-15',
            'codigo_ref' => 1,             // 1=Anula documento, 2=Corrige texto, 3=Corrige montos
            'razon'      => 'Se anula factura por duplicidad',
        ],
    ],
];

try {
    $resultado = $client->enviarDte(DteTypes::NOTA_CREDITO_ELECTRONICA, $datos);
    echo "✅ Nota de Crédito enviada. Track ID: {$resultado['trackId']}\n";
} catch (SiiException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
