<?php

declare(strict_types=1);

namespace SiiDte\Storage;

use PDO;

/**
 * Persiste los DTE emitidos en la base de datos.
 *
 * ─── LO QUE HACE ESTA CLASE ────────────────────────────────────────────────
 *   ✅ Guarda el XML firmado de cada DTE emitido
 *   ✅ Registra el Track ID del SII
 *   ✅ Guarda el estado actual (pendiente / aceptado / rechazado)
 *   ✅ Permite consultar DTE por folio, tipo, RUT receptor, etc.
 *   ✅ Actualiza el estado cuando el SII procesa el documento
 *
 * ─── LO QUE DEBES HACER TÚ ─────────────────────────────────────────────────
 *   ❌ Crear las tablas (ver database/migrations.sql)
 *   ❌ Pasar una instancia PDO configurada con tu BD
 *   ❌ Implementar un job/cron que llame a actualizarEstados() periódicamente
 *   ❌ Guardar el PDF generado (esta clase guarda el XML, no el PDF)
 */
class DteRepository
{
    public function __construct(private PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Guarda un DTE recién emitido.
     *
     * @param array $data {
     *   tipo_dte, folio, rut_emisor, rut_receptor, razon_social_receptor,
     *   fecha_emision, monto_neto, monto_iva, monto_exento, monto_total,
     *   track_id, xml_firmado, estado (pendiente|aceptado|rechazado)
     * }
     * @return int ID del registro creado
     */
    public function guardar(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sii_dte
                (tipo_dte, folio, rut_emisor, rut_receptor, razon_social_receptor,
                 fecha_emision, monto_neto, monto_iva, monto_exento, monto_total,
                 track_id, xml_firmado, estado, created_at, updated_at)
             VALUES
                (:tipo_dte, :folio, :rut_emisor, :rut_receptor, :razon_social_receptor,
                 :fecha_emision, :monto_neto, :monto_iva, :monto_exento, :monto_total,
                 :track_id, :xml_firmado, :estado, NOW(), NOW())'
        );

        $stmt->execute([
            ':tipo_dte'               => $data['tipo_dte'],
            ':folio'                  => $data['folio'],
            ':rut_emisor'             => $data['rut_emisor'],
            ':rut_receptor'           => $data['rut_receptor'],
            ':razon_social_receptor'  => $data['razon_social_receptor'] ?? '',
            ':fecha_emision'          => $data['fecha_emision'],
            ':monto_neto'             => $data['monto_neto'] ?? 0,
            ':monto_iva'              => $data['monto_iva'] ?? 0,
            ':monto_exento'           => $data['monto_exento'] ?? 0,
            ':monto_total'            => $data['monto_total'],
            ':track_id'               => $data['track_id'] ?? null,
            ':xml_firmado'            => $data['xml_firmado'],
            ':estado'                 => $data['estado'] ?? 'pendiente',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Actualiza el estado de un DTE después de consultar al SII.
     *
     * @param string $trackId    Track ID del envío
     * @param string $estado     'aceptado' | 'rechazado' | 'pendiente'
     * @param string $glosa      Mensaje del SII
     */
    public function actualizarEstado(string $trackId, string $estado, string $glosa = ''): void
    {
        $this->pdo->prepare(
            'UPDATE sii_dte
             SET estado = :estado, glosa_sii = :glosa, updated_at = NOW()
             WHERE track_id = :track_id'
        )->execute([':estado' => $estado, ':glosa' => $glosa, ':track_id' => $trackId]);
    }

    /**
     * Guarda el PDF generado como bytes en la BD (opcional).
     * Si prefieres guardar en disco/S3, no uses este método.
     */
    public function guardarPdf(int $id, string $pdfBytes): void
    {
        $this->pdo->prepare(
            'UPDATE sii_dte SET pdf_bytes = :pdf, updated_at = NOW() WHERE id = :id'
        )->execute([':pdf' => $pdfBytes, ':id' => $id]);
    }

    /**
     * Busca DTE por Track ID.
     */
    public function porTrackId(string $trackId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sii_dte WHERE track_id = :track_id LIMIT 1'
        );
        $stmt->execute([':track_id' => $trackId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Busca DTE por tipo y folio.
     */
    public function porFolio(int $tipoDte, int $folio, string $rutEmisor): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM sii_dte
             WHERE tipo_dte = :tipo AND folio = :folio AND rut_emisor = :rut
             LIMIT 1'
        );
        $stmt->execute([':tipo' => $tipoDte, ':folio' => $folio, ':rut' => $rutEmisor]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Retorna DTE pendientes de confirmación (para el cron de actualización).
     */
    public function pendientes(int $limite = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM sii_dte
             WHERE estado = 'pendiente'
               AND track_id IS NOT NULL
               AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY created_at ASC
             LIMIT :limite"
        );
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
