<?php

declare(strict_types=1);

namespace SiiDte\Storage;

/** Almacén en memoria para pruebas y scripts. */
final class InMemoryDteStore implements DteStore
{
    /** @var array<string, array<string, mixed>> */
    public array $registros = [];

    public function guardarIntento(string $rutEmisor, string $ambiente, int $tipoDte, int $folio, string $dteXml, string $sobreXml): void
    {
        $key = "{$rutEmisor}|{$ambiente}|{$tipoDte}|{$folio}";
        if (isset($this->registros[$key])) {
            throw new \RuntimeException('El folio ya tiene un intento registrado.');
        }
        $this->registros[$key] = ['estado' => 'preparado', 'track_id' => null, 'detalle' => null, 'dte_xml' => $dteXml, 'sobre_xml' => $sobreXml];
    }

    public function registrarResultado(string $rutEmisor, string $ambiente, int $tipoDte, int $folio, string $estado, ?string $trackId = null, ?string $detalle = null): void
    {
        $key = "{$rutEmisor}|{$ambiente}|{$tipoDte}|{$folio}";
        $this->registros[$key] = ['estado' => $estado, 'track_id' => $trackId, 'detalle' => $detalle] + ($this->registros[$key] ?? []);
    }
}
