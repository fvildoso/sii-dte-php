<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SiiDte\Folio\InMemoryFolioProvider;
use SiiDte\SiiClient;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\InMemoryDteStore;

$folios = new InMemoryFolioProvider();
$folios->agregarCaf(file_get_contents(getenv('SII_CAF_33')), '76123456-0', 'certificacion', 33);

$client = SiiClient::fromEnv()->usarFolios($folios)->usarAlmacen(new InMemoryDteStore());

$r = $client->emitir([
    'tipo' => 33,
    'fecha' => date('Y-m-d'),
    'receptor' => ['rut' => '8414240-9', 'razon_social' => 'Cliente SpA', 'giro' => 'Comercio',
        'direccion' => 'Calle 1', 'comuna' => 'Santiago'],
    'detalle' => [['nombre' => 'Servicio mensual', 'cantidad' => 1, 'precio' => 100000]],
]);

echo "Folio {$r->folio}, TrackID {$r->trackId}\n";
