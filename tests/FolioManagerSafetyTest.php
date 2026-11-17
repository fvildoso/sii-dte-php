<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\FolioManager;

class FolioManagerSafetyTest extends TestCase
{
    public function testImportarCafFallaCerradoSinEscribirloEnElEsquemaHeredado(): void
    {
        $manager = new FolioManager($this->pdoWithoutStorage());

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('FRMA no se verifica');
        $manager->importarCaf('<AUTORIZACION/>', 33);
    }

    public function testSiguienteFolioNoReservaDesdeUnContadorNoAislado(): void
    {
        $manager = new FolioManager($this->pdoWithoutStorage());

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('RUT, ambiente y tipo');
        $manager->siguienteFolio(33);
    }

    private function pdoWithoutStorage(): \PDO
    {
        return new class () extends \PDO {
            public function __construct()
            {
            }

            public function setAttribute(int $attribute, mixed $value): bool
            {
                return true;
            }
        };
    }
}
