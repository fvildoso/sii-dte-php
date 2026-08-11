<?php

/**
 * Ejemplo: Enviar una Factura Electrónica (tipo 33) al SII.
 *
 * IMPORTANTE: Ejecutar primero en ambiente de certificación.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SiiDte\SiiClient;
use SiiDte\Document\DteTypes;
use SiiDte\Exceptions\SiiException;

// ============================================================
// 1. Configurar el cliente con los datos del emisor
// ============================================================
$client = new SiiClient([
    'rut_emisor'         => '12345678',          // RUT sin DV
    'dv_emisor'          => '9',                 // Dígito verificador
    'razon_social'       => 'Mi Empresa SpA',
    'giro'               => 'Venta de Software',
    'direccion'          => 'Av. Providencia 1234',
    'ciudad'             => 'Santiago',
    'numero_resolucion'  => 0,                   // 0 para certificación
    'fecha_resolucion'   => '2019-10-18',        // Fecha resolución SII
    'cert_path'          => __DIR__ . '/certs/mi_certificado.p12',
    'cert_pass'          => 'mi_contraseña',
    'ambiente'           => 'certificacion',     // Cambiar a 'produccion' cuando esté listo
]);

// ============================================================
// 2. Definir los datos de la factura
// ============================================================
$datos = [
    'folio'    => 1,                    // Número de folio del CAF
    'fecha'    => date('Y-m-d'),        // Fecha de emisión

    // Datos del receptor (cliente)
    'receptor' => [
        'rut'          => '98765432',
        'dv'           => '1',
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
            'precio_unitario'  => 119000,   // Precio con IVA incluido
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
// 3. Enviar la factura
// ============================================================
try {
    echo "Enviando Factura Electrónica al SII...\n";

    $resultado = $client->enviarDte(DteTypes::FACTURA_ELECTRONICA, $datos);

    echo "✅ Factura enviada exitosamente!\n";
    echo "   Track ID: {$resultado['trackId']}\n";
    echo "   Guarda este Track ID para consultar el estado.\n\n";

    // Guardar el XML generado (opcional, para tus registros)
    file_put_contents(__DIR__ . '/output/factura_' . $datos['folio'] . '.xml', $resultado['xml']);
    echo "   XML guardado en: output/factura_{$datos['folio']}.xml\n";

} catch (SiiException $e) {
    echo "❌ Error SII: " . $e->getMessage() . "\n";
    exit(1);
}

// ============================================================
// 4. Consultar estado del envío (después de unos minutos)
// ============================================================
echo "\nConsultando estado del envío...\n";
try {
    $estado = $client->consultarEstado($resultado['trackId']);
    echo "Estado: {$estado['estado']}\n";
    echo "Glosa:  {$estado['glosa']}\n";

    if (!empty($estado['detalle'])) {
        echo "\nDetalle de documentos:\n";
        foreach ($estado['detalle'] as $doc) {
            echo "  Folio {$doc['folio']} (Tipo {$doc['tipo']}): {$doc['estado']}\n";
        }
    }
} catch (SiiException $e) {
    echo "No se pudo consultar el estado (es normal esperar unos minutos): " . $e->getMessage() . "\n";
}
