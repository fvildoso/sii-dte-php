<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SiiDte\SiiClient;

/** @var SiiClient $client Con usarFolios() y usarAlmacen() ya configurados. */
$client = require __DIR__ . '/cliente.php';

// Nota de crédito que anula la factura 123
$nc = $client->emitir([
    'tipo' => 61,
    'fecha' => date('Y-m-d'),
    'receptor' => ['rut' => '8414240-9', 'razon_social' => 'Cliente SpA', 'giro' => 'Comercio',
        'direccion' => 'Calle 1', 'comuna' => 'Santiago'],
    'detalle' => [['nombre' => 'Servicio mensual', 'cantidad' => 1, 'precio' => 100000]],
    'referencias' => [['tipo_doc' => 33, 'folio' => 123, 'fecha' => '2026-11-01', 'codigo' => 1, 'razon' => 'Anula factura']],
]);

// Boleta electrónica (precios con IVA incluido), por la API REST de boletas
$boleta = $client->emitir([
    'tipo' => 39,
    'fecha' => date('Y-m-d'),
    'detalle' => [['nombre' => 'Café', 'cantidad' => 2, 'precio' => 2500]],
]);

$estado = $client->consultarEstado($boleta->trackId, boleta: true);
