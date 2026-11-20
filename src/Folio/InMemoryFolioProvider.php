<?php

declare(strict_types=1);

namespace SiiDte\Folio;

use SiiDte\Exceptions\FolioAgotadoException;
use SiiDte\Exceptions\SiiException;

/**
 * Proveedor de folios en memoria: útil para pruebas, scripts de certificación y demos.
 * No persiste nada ni es seguro entre procesos; en producción use PdoFolioProvider u otro persistente.
 */
final class InMemoryFolioProvider implements FolioProvider
{
    /** @var array<string, list<array{desde: int, hasta: int, proximo: int, caf: string}>> */
    private array $cafs = [];

    public function __construct(private readonly CafInspector $inspector = new CafInspector()) {}

    public function agregarCaf(string $cafXml, string $rutEmisor, string $ambiente, int $tipoDte): void
    {
        $info = $this->inspector->inspect($cafXml, $rutEmisor, $tipoDte);
        $this->cafs[self::key($rutEmisor, $ambiente, $tipoDte)][] = [
            'desde' => $info['folio_desde'],
            'hasta' => $info['folio_hasta'],
            'proximo' => $info['folio_desde'],
            'caf' => $cafXml,
        ];
    }

    public function reservar(string $rutEmisor, string $ambiente, int $tipoDte): FolioReservado
    {
        $key = self::key($rutEmisor, $ambiente, $tipoDte);
        foreach ($this->cafs[$key] ?? [] as $i => $caf) {
            if ($caf['proximo'] <= $caf['hasta']) {
                $this->cafs[$key][$i]['proximo']++;

                return new FolioReservado($caf['proximo'], $caf['caf']);
            }
        }

        throw new FolioAgotadoException("No quedan folios para el tipo {$tipoDte} ({$ambiente}). Solicite un nuevo CAF al SII.");
    }

    private static function key(string $rut, string $ambiente, int $tipo): string
    {
        if (!in_array($ambiente, ['certificacion', 'produccion'], true)) {
            throw new SiiException("Ambiente no reconocido: {$ambiente}");
        }

        return strtoupper($rut) . '|' . $ambiente . '|' . $tipo;
    }
}
