<?php

declare(strict_types=1);

use SiiDte\Folio\PdoFolioProvider;
use SiiDte\SiiClient;
use SiiDte\Storage\PdoDteStore;

$pdo = new PDO(getenv('DB_DSN'), getenv('DB_USER') ?: null, getenv('DB_PASS') ?: null);

return SiiClient::fromEnv()
    ->usarFolios(new PdoFolioProvider($pdo))
    ->usarAlmacen(new PdoDteStore($pdo));
