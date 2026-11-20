<?php

declare(strict_types=1);

namespace SiiDte\Storage;

use SiiDte\Exceptions\SiiException;

/** Persistencia de DTE sobre PDO (MySQL, PostgreSQL, SQLite). */
final class PdoDteStore implements DteStore
{
    public function __construct(private readonly \PDO $pdo, private readonly string $table = 'sii_dte')
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table) !== 1) {
            throw new SiiException('Nombre de tabla inválido.');
        }
    }

    public function crearEsquema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                rut_emisor VARCHAR(12) NOT NULL,
                ambiente VARCHAR(13) NOT NULL,
                tipo_dte INTEGER NOT NULL,
                folio INTEGER NOT NULL,
                estado VARCHAR(20) NOT NULL,
                track_id VARCHAR(20) NULL,
                detalle TEXT NULL,
                dte_xml TEXT NOT NULL,
                sobre_xml TEXT NOT NULL,
                creado_en VARCHAR(19) NOT NULL,
                actualizado_en VARCHAR(19) NOT NULL,
                PRIMARY KEY (rut_emisor, ambiente, tipo_dte, folio)
            )"
        );
    }

    public function guardarIntento(string $rutEmisor, string $ambiente, int $tipoDte, int $folio, string $dteXml, string $sobreXml): void
    {
        $now = date('Y-m-d H:i:s');
        try {
            $this->pdo->prepare(
                "INSERT INTO {$this->table} (rut_emisor, ambiente, tipo_dte, folio, estado, dte_xml, sobre_xml, creado_en, actualizado_en)
                 VALUES (?, ?, ?, ?, 'preparado', ?, ?, ?, ?)"
            )->execute([strtoupper($rutEmisor), $ambiente, $tipoDte, $folio, $dteXml, $sobreXml, $now, $now]);
        } catch (\PDOException $e) {
            throw new SiiException('No se pudo registrar el intento de emisión (¿folio ya registrado?).', 0, $e);
        }
    }

    public function registrarResultado(string $rutEmisor, string $ambiente, int $tipoDte, int $folio, string $estado, ?string $trackId = null, ?string $detalle = null): void
    {
        $this->pdo->prepare(
            "UPDATE {$this->table} SET estado = ?, track_id = ?, detalle = ?, actualizado_en = ?
             WHERE rut_emisor = ? AND ambiente = ? AND tipo_dte = ? AND folio = ?"
        )->execute([$estado, $trackId, $detalle, date('Y-m-d H:i:s'), strtoupper($rutEmisor), $ambiente, $tipoDte, $folio]);
    }
}
