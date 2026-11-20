<?php

declare(strict_types=1);

namespace SiiDte\Folio;

use SiiDte\Exceptions\FolioAgotadoException;
use SiiDte\Exceptions\SiiException;

/**
 * Reserva de folios sobre PDO (MySQL, PostgreSQL, SQLite). La reserva es una única sentencia
 * UPDATE condicionada al valor leído, por lo que es atómica sin bloqueos explícitos: si dos
 * procesos compiten, solo uno la gana y el otro reintenta con el siguiente folio.
 *
 * El CAF se guarda completo (incluye la llave privada de timbrado). Para cifrarlo en reposo pase
 * `$encrypt`/`$decrypt` (por ejemplo, sobre sodium o su gestor de secretos).
 */
final class PdoFolioProvider implements FolioProvider
{
    private const MAX_INTENTOS = 10;

    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly CafInspector $inspector = new CafInspector(),
        private readonly ?\Closure $encrypt = null,
        private readonly ?\Closure $decrypt = null,
        ?\Closure $clock = null,
        private readonly string $table = 'sii_caf',
    ) {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,62}$/D', $table) !== 1) {
            throw new SiiException('Nombre de tabla inválido.');
        }
        if (($encrypt === null) !== ($decrypt === null)) {
            throw new SiiException('Debe indicar ambas funciones de cifrado y descifrado, o ninguna.');
        }
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago'));
    }

    /** Crea la tabla si no existe (DDL portable). */
    public function crearEsquema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS {$this->table} (
                rut_emisor VARCHAR(12) NOT NULL,
                ambiente VARCHAR(13) NOT NULL,
                tipo_dte INTEGER NOT NULL,
                folio_desde INTEGER NOT NULL,
                folio_hasta INTEGER NOT NULL,
                proximo_folio INTEGER NOT NULL,
                fecha_autorizacion VARCHAR(10) NOT NULL,
                caf_xml TEXT NOT NULL,
                PRIMARY KEY (rut_emisor, ambiente, tipo_dte, folio_desde)
            )"
        );
    }

    /**
     * Valida e importa un CAF. Falla si el RUT/tipo no coinciden, el CAF está vencido o ya existe.
     *
     * @return array{folio_desde: int, folio_hasta: int}
     */
    public function importarCaf(string $cafXml, string $rutEmisor, string $ambiente, int $tipoDte): array
    {
        $this->assertAmbiente($ambiente);
        $info = $this->inspector->inspect($cafXml, $rutEmisor, $tipoDte, $this->clock->__invoke()->format('Y-m-d'));
        if ($info['vigencia'] === 'vencido') {
            throw new SiiException('El CAF está vencido.');
        }
        $stored = $this->encrypt !== null ? ($this->encrypt)($cafXml) : $cafXml;

        try {
            $statement = $this->pdo->prepare(
                "INSERT INTO {$this->table}
                 (rut_emisor, ambiente, tipo_dte, folio_desde, folio_hasta, proximo_folio, fecha_autorizacion, caf_xml)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $statement->execute([
                strtoupper($rutEmisor), $ambiente, $tipoDte, $info['folio_desde'], $info['folio_hasta'],
                $info['folio_desde'], $info['fecha_autorizacion'], $stored,
            ]);
        } catch (\PDOException $e) {
            throw new SiiException('No se pudo guardar el CAF (¿ya fue importado?).', 0, $e);
        }

        return ['folio_desde' => $info['folio_desde'], 'folio_hasta' => $info['folio_hasta']];
    }

    /**
     * @return array{total: int, usados: int, disponibles: int} Folios del tipo (todos los CAF)
     */
    public function disponibles(string $rutEmisor, string $ambiente, int $tipoDte): array
    {
        $this->assertAmbiente($ambiente);
        $statement = $this->pdo->prepare(
            "SELECT folio_desde, folio_hasta, proximo_folio FROM {$this->table}
             WHERE rut_emisor = ? AND ambiente = ? AND tipo_dte = ?"
        );
        $statement->execute([strtoupper($rutEmisor), $ambiente, $tipoDte]);
        $total = 0;
        $usados = 0;
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $total += (int) $row['folio_hasta'] - (int) $row['folio_desde'] + 1;
            $usados += (int) $row['proximo_folio'] - (int) $row['folio_desde'];
        }

        return ['total' => $total, 'usados' => $usados, 'disponibles' => $total - $usados];
    }

    public function reservar(string $rutEmisor, string $ambiente, int $tipoDte): FolioReservado
    {
        $this->assertAmbiente($ambiente);
        $rut = strtoupper($rutEmisor);
        $hoy = $this->clock->__invoke()->format('Y-m-d');

        for ($attempt = 0; $attempt < self::MAX_INTENTOS; ++$attempt) {
            $select = $this->pdo->prepare(
                "SELECT folio_desde, proximo_folio, caf_xml FROM {$this->table}
                 WHERE rut_emisor = ? AND ambiente = ? AND tipo_dte = ? AND proximo_folio <= folio_hasta
                 ORDER BY folio_desde ASC"
            );
            $select->execute([$rut, $ambiente, $tipoDte]);

            $chosen = null;
            foreach ($select->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $caf = $this->decrypt !== null ? ($this->decrypt)((string) $row['caf_xml']) : (string) $row['caf_xml'];
                $info = $this->inspector->inspect($caf, $rut, $tipoDte, $hoy);
                if ($info['vigencia'] === 'vencido') {
                    continue;
                }
                $chosen = ['desde' => (int) $row['folio_desde'], 'proximo' => (int) $row['proximo_folio'], 'caf' => $caf];
                break;
            }
            if ($chosen === null) {
                throw new FolioAgotadoException("No quedan folios vigentes para el tipo {$tipoDte} ({$ambiente}). Solicite un nuevo CAF al SII.");
            }

            $update = $this->pdo->prepare(
                "UPDATE {$this->table} SET proximo_folio = proximo_folio + 1
                 WHERE rut_emisor = ? AND ambiente = ? AND tipo_dte = ? AND folio_desde = ? AND proximo_folio = ?"
            );
            $update->execute([$rut, $ambiente, $tipoDte, $chosen['desde'], $chosen['proximo']]);
            if ($update->rowCount() === 1) {
                return new FolioReservado($chosen['proximo'], $chosen['caf']);
            }
        }

        throw new SiiException('No se pudo reservar un folio por contención excesiva; reintente.');
    }

    private function assertAmbiente(string $ambiente): void
    {
        if (!in_array($ambiente, ['certificacion', 'produccion'], true)) {
            throw new SiiException("Ambiente no reconocido: {$ambiente}");
        }
    }
}
