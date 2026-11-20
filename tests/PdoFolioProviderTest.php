<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\FolioAgotadoException;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\PdoFolioProvider;
use SiiDte\Storage\PdoDteStore;
use SiiDte\Tests\Support\SyntheticSii;

final class PdoFolioProviderTest extends TestCase
{
    private const RUT = SyntheticSii::RUT_EMISOR;

    private function pdo(): \PDO
    {
        return new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function provider(\PDO $pdo, ?\Closure $encrypt = null, ?\Closure $decrypt = null): PdoFolioProvider
    {
        $provider = new PdoFolioProvider(
            $pdo,
            encrypt: $encrypt,
            decrypt: $decrypt,
            clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-20', new \DateTimeZone('America/Santiago')),
        );
        $provider->crearEsquema();

        return $provider;
    }

    public function testReservaFoliosConsecutivosEntreCafYLosAgota(): void
    {
        $synth = SyntheticSii::get();
        $provider = $this->provider($this->pdo());
        $provider->importarCaf($synth->caf(33, self::RUT, 100, 101), self::RUT, 'certificacion', 33);
        $provider->importarCaf($synth->caf(33, self::RUT, 200, 200), self::RUT, 'certificacion', 33);

        self::assertSame(['total' => 3, 'usados' => 0, 'disponibles' => 3], $provider->disponibles(self::RUT, 'certificacion', 33));
        $folios = [];
        for ($i = 0; $i < 3; ++$i) {
            $reserva = $provider->reservar(self::RUT, 'certificacion', 33);
            $folios[] = $reserva->folio;
            self::assertStringContainsString('<AUTORIZACION>', $reserva->cafXml);
        }
        self::assertSame([100, 101, 200], $folios);
        self::assertSame(0, $provider->disponibles(self::RUT, 'certificacion', 33)['disponibles']);

        $this->expectException(FolioAgotadoException::class);
        $provider->reservar(self::RUT, 'certificacion', 33);
    }

    public function testAislaPorAmbienteTipoYRut(): void
    {
        $synth = SyntheticSii::get();
        $provider = $this->provider($this->pdo());
        $provider->importarCaf($synth->caf(33, self::RUT, 1, 5), self::RUT, 'certificacion', 33);

        foreach ([['produccion', 33], ['certificacion', 34]] as [$ambiente, $tipo]) {
            try {
                $provider->reservar(self::RUT, $ambiente, $tipo);
                self::fail("No debía reservar {$ambiente}/{$tipo}");
            } catch (FolioAgotadoException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(FolioAgotadoException::class);
        $provider->reservar('76123456-0', 'certificacion', 33);
    }

    public function testRechazaCafDuplicadoVencidoOConRutDistinto(): void
    {
        $synth = SyntheticSii::get();
        $provider = $this->provider($this->pdo());
        $provider->importarCaf($synth->caf(33, self::RUT, 1, 5), self::RUT, 'certificacion', 33);

        foreach ([
            [$synth->caf(33, self::RUT, 1, 5), self::RUT, 33],                       // duplicado
            [$synth->caf(33, self::RUT, 6, 9, '2025-01-01'), self::RUT, 33],         // vencido (más de 6 meses)
            [$synth->caf(33, self::RUT, 6, 9), '76123456-0', 33],                    // RUT distinto
            [$synth->caf(34, self::RUT, 6, 9), self::RUT, 33],                       // tipo distinto
        ] as [$caf, $rut, $tipo]) {
            try {
                $provider->importarCaf($caf, $rut, 'certificacion', $tipo);
                self::fail('Debía rechazar el CAF.');
            } catch (SiiException) {
                self::assertTrue(true);
            }
        }
    }

    public function testOmiteCafVencidoAlReservar(): void
    {
        $synth = SyntheticSii::get();
        $pdo = $this->pdo();
        $viejo = $this->provider($pdo);
        $viejo->importarCaf($synth->caf(33, self::RUT, 1, 5, '2026-06-01'), self::RUT, 'certificacion', 33);
        $viejo->importarCaf($synth->caf(33, self::RUT, 10, 12, '2026-11-01'), self::RUT, 'certificacion', 33);

        // Con fecha de 2026-12-15 el primer CAF (autorizado 2026-06-01) ya venció, el segundo no.
        $futuro = new PdoFolioProvider($pdo, clock: static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-12-15'));
        self::assertSame(10, $futuro->reservar(self::RUT, 'certificacion', 33)->folio);
    }

    public function testCifraElCafEnReposo(): void
    {
        $synth = SyntheticSii::get();
        $pdo = $this->pdo();
        $provider = $this->provider($pdo, fn(string $s): string => strrev($s), fn(string $s): string => strrev($s));
        $caf = $synth->caf(33, self::RUT, 1, 5);
        $provider->importarCaf($caf, self::RUT, 'certificacion', 33);

        $almacenado = (string) $pdo->query('SELECT caf_xml FROM sii_caf')->fetchColumn();
        self::assertStringNotContainsString('RSASK', $almacenado);
        self::assertSame($caf, $provider->reservar(self::RUT, 'certificacion', 33)->cafXml);
    }

    public function testDteStoreRegistraIntentoYRechazaFolioDuplicado(): void
    {
        $pdo = $this->pdo();
        $store = new PdoDteStore($pdo);
        $store->crearEsquema();
        $store->guardarIntento(self::RUT, 'certificacion', 33, 10, '<DTE/>', '<EnvioDTE/>');
        $store->registrarResultado(self::RUT, 'certificacion', 33, 10, 'enviado', '123');

        $row = $pdo->query('SELECT estado, track_id FROM sii_dte')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['estado' => 'enviado', 'track_id' => '123'], $row);

        $this->expectException(SiiException::class);
        $store->guardarIntento(self::RUT, 'certificacion', 33, 10, '<DTE/>', '<EnvioDTE/>');
    }

    public function testNombreDeTablaInvalidoSeRechaza(): void
    {
        $this->expectException(SiiException::class);
        new PdoFolioProvider($this->pdo(), table: 'sii_caf; DROP TABLE x');
    }
}
