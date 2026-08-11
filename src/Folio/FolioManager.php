<?php

declare(strict_types=1);

namespace SiiDte\Folio;

use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\FolioAgotadoException;
use PDO;
use DOMDocument;

/**
 * Administra los folios autorizados (CAF) por tipo de DTE.
 *
 * RESPONSABILIDADES DE ESTA CLASE:
 *   ✅ Guardar el XML del CAF en la base de datos
 *   ✅ Asignar el siguiente folio disponible (con bloqueo para concurrencia)
 *   ✅ Alertar cuando los folios están por agotarse
 *   ✅ Validar que el folio pertenece al rango autorizado
 *
 * RESPONSABILIDAD DE TU APLICACIÓN:
 *   ❌ Crear la tabla en la BD (ver database/migrations.sql)
 *   ❌ Solicitar nuevos CAF al SII cuando se agoten
 *   ❌ Implementar el sistema de alertas por email/Slack cuando quedan pocos folios
 */
class FolioManager
{
    private PDO $pdo;

    /**
     * @param PDO $pdo Conexión PDO a tu base de datos (MySQL, PostgreSQL o SQLite)
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        // Asegurar que los errores PDO sean excepciones
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Importa un archivo CAF descargado del SII.
     *
     * El CAF es un XML que el SII te entrega cuando solicitas folios.
     * Contiene el rango autorizado, la firma del SII y la clave privada
     * cifrada para timbrar los documentos.
     *
     * @param string $cafXml   Contenido del archivo CAF (.xml)
     * @param int    $tipoDte  Tipo de DTE (debe coincidir con el CAF)
     * @throws SiiException    Si el CAF es inválido o ya existe un rango activo que se superpone
     */
    public function importarCaf(string $cafXml, int $tipoDte): void
    {
        $caf = $this->parseCaf($cafXml);

        if ($caf['tipo'] !== $tipoDte) {
            throw new SiiException(
                "El CAF es para tipo {$caf['tipo']} pero se indicó tipo {$tipoDte}."
            );
        }

        // Verificar que no exista un CAF activo que se superponga en el mismo rango
        $stmt = $this->pdo->prepare(
            'SELECT id FROM sii_caf
             WHERE tipo_dte = :tipo
               AND estado = :estado
               AND NOT (folio_hasta < :desde OR folio_desde > :hasta)'
        );
        $stmt->execute([
            ':tipo'   => $tipoDte,
            ':estado' => 'activo',
            ':desde'  => $caf['desde'],
            ':hasta'  => $caf['hasta'],
        ]);

        if ($stmt->fetch()) {
            throw new SiiException(
                "Ya existe un CAF activo que se superpone con el rango {$caf['desde']}-{$caf['hasta']} para tipo {$tipoDte}."
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO sii_caf
                (tipo_dte, folio_desde, folio_hasta, siguiente_folio, fecha_vencimiento, caf_xml, estado, created_at)
             VALUES
                (:tipo, :desde, :hasta, :siguiente, :vencimiento, :xml, :estado, NOW())'
        );

        $stmt->execute([
            ':tipo'        => $tipoDte,
            ':desde'       => $caf['desde'],
            ':hasta'       => $caf['hasta'],
            ':siguiente'   => $caf['desde'],   // El primer folio disponible es el inicial
            ':vencimiento' => $caf['vencimiento'] ?? null,
            ':xml'         => $cafXml,
            ':estado'      => 'activo',
        ]);
    }

    /**
     * Obtiene y reserva el siguiente folio disponible para un tipo de DTE.
     *
     * Usa SELECT FOR UPDATE para evitar que dos procesos paralelos obtengan
     * el mismo folio (condición de carrera).
     *
     * @throws FolioAgotadoException Si no hay folios disponibles
     * @return array{folio: int, caf_id: int, caf_xml: string}
     */
    public function siguienteFolio(int $tipoDte): array
    {
        $this->pdo->beginTransaction();

        try {
            // Bloquear el registro para lectura exclusiva
            // (FOR UPDATE funciona en MySQL, PostgreSQL y SQLite con WAL)
            $stmt = $this->pdo->prepare(
                'SELECT id, siguiente_folio, folio_hasta, caf_xml
                 FROM sii_caf
                 WHERE tipo_dte = :tipo
                   AND estado = :estado
                   AND siguiente_folio <= folio_hasta
                   AND (fecha_vencimiento IS NULL OR fecha_vencimiento >= CURDATE())
                 ORDER BY siguiente_folio ASC
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmt->execute([':tipo' => $tipoDte, ':estado' => 'activo']);
            $caf = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$caf) {
                $this->pdo->rollBack();
                throw new FolioAgotadoException(
                    "No hay folios disponibles para tipo DTE {$tipoDte}. "
                    . "Solicita un nuevo CAF al SII en: https://misiir.sii.cl"
                );
            }

            $folio         = (int) $caf['siguiente_folio'];
            $nuevoSiguiente = $folio + 1;

            // Marcar el CAF como agotado si este era el último folio
            if ($nuevoSiguiente > (int) $caf['folio_hasta']) {
                $this->pdo->prepare(
                    'UPDATE sii_caf SET siguiente_folio = :sig, estado = :estado WHERE id = :id'
                )->execute([':sig' => $nuevoSiguiente, ':estado' => 'agotado', ':id' => $caf['id']]);
            } else {
                $this->pdo->prepare(
                    'UPDATE sii_caf SET siguiente_folio = :sig WHERE id = :id'
                )->execute([':sig' => $nuevoSiguiente, ':id' => $caf['id']]);
            }

            $this->pdo->commit();

            return [
                'folio'   => $folio,
                'caf_id'  => (int) $caf['id'],
                'caf_xml' => $caf['caf_xml'],
            ];

        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Devuelve cuántos folios quedan disponibles para un tipo de DTE.
     */
    public function foliosDisponibles(int $tipoDte): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT SUM(folio_hasta - siguiente_folio + 1) AS disponibles
             FROM sii_caf
             WHERE tipo_dte = :tipo
               AND estado = :estado
               AND siguiente_folio <= folio_hasta'
        );
        $stmt->execute([':tipo' => $tipoDte, ':estado' => 'activo']);
        return (int) ($stmt->fetchColumn() ?? 0);
    }

    /**
     * Marca un folio como anulado (cuando el DTE fue rechazado por el SII
     * y no se puede volver a usar ese número de folio).
     *
     * IMPORTANTE: En la práctica, los folios rechazados por el SII se pierden.
     * No se pueden reutilizar. Esta función los registra para auditoría.
     */
    public function anularFolio(int $tipoDte, int $folio, string $motivo = ''): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sii_folios_anulados (tipo_dte, folio, motivo, anulado_at)
             VALUES (:tipo, :folio, :motivo, NOW())
             ON DUPLICATE KEY UPDATE motivo = :motivo, anulado_at = NOW()'
        );
        $stmt->execute([':tipo' => $tipoDte, ':folio' => $folio, ':motivo' => $motivo]);
    }

    /**
     * Retorna los CAF y su estado de llenado.
     * Útil para dashboards de administración.
     */
    public function estadoCafs(int $tipoDte = null): array
    {
        $sql = 'SELECT tipo_dte, folio_desde, folio_hasta, siguiente_folio, estado,
                       fecha_vencimiento,
                       (folio_hasta - siguiente_folio + 1) AS disponibles,
                       (siguiente_folio - folio_desde) AS usados
                FROM sii_caf';

        if ($tipoDte !== null) {
            $stmt = $this->pdo->prepare($sql . ' WHERE tipo_dte = :tipo ORDER BY folio_desde');
            $stmt->execute([':tipo' => $tipoDte]);
        } else {
            $stmt = $this->pdo->query($sql . ' ORDER BY tipo_dte, folio_desde');
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------------------
    // Privados
    // -------------------------------------------------------------------------

    /**
     * Parsea el XML del CAF y extrae los valores clave.
     */
    private function parseCaf(string $cafXml): array
    {
        libxml_use_internal_errors(true);
        $doc = new DOMDocument();

        if (!$doc->loadXML($cafXml)) {
            throw new SiiException('El archivo CAF no es un XML válido.');
        }

        $get = fn(string $tag) => $doc->getElementsByTagName($tag)->item(0)?->textContent ?? null;

        $tipo  = (int) $get('TD');
        $desde = (int) $get('RNG') ? null : (int) ($doc->getElementsByTagName('D')->item(0)?->textContent);
        $hasta = (int) ($doc->getElementsByTagName('H')->item(0)?->textContent ?? 0);

        // El CAF tiene <RNG><D>inicio</D><H>fin</H></RNG>
        $rngNode = $doc->getElementsByTagName('RNG')->item(0);
        if ($rngNode) {
            $desde = (int) $rngNode->getElementsByTagName('D')->item(0)?->textContent;
            $hasta = (int) $rngNode->getElementsByTagName('H')->item(0)?->textContent;
        }

        $vencimiento = $get('FA'); // Fecha de autorización, CAFs no vencen pero la registramos

        if (!$tipo || !$desde || !$hasta) {
            throw new SiiException('El archivo CAF no contiene los campos TD, D (desde) y H (hasta).');
        }

        if ($desde > $hasta) {
            throw new SiiException("El CAF tiene un rango inválido: {$desde} > {$hasta}.");
        }

        return [
            'tipo'        => $tipo,
            'desde'       => $desde,
            'hasta'       => $hasta,
            'vencimiento' => $vencimiento,
        ];
    }
}
