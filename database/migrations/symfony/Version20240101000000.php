<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migraciones para SII DTE PHP.
 *
 * Ubicación: migrations/Version20240101000000.php
 *
 * Ejecutar con:
 *   php bin/console doctrine:migrations:migrate
 *
 * O generar desde aquí:
 *   php bin/console doctrine:migrations:diff
 */
final class Version20240101000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crea las tablas para SII DTE PHP (CAF, DTE, folios anulados, log, tokens)';
    }

    public function up(Schema $schema): void
    {
        // ── CAF ───────────────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE sii_caf (
                id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                tipo_dte         TINYINT         NOT NULL,
                folio_desde      INT UNSIGNED    NOT NULL,
                folio_hasta      INT UNSIGNED    NOT NULL,
                siguiente_folio  INT UNSIGNED    NOT NULL,
                fecha_vencimiento DATE           NULL,
                caf_xml          LONGTEXT        NOT NULL,
                estado           ENUM("activo","agotado","vencido","anulado") NOT NULL DEFAULT "activo",
                created_at       DATETIME        NOT NULL,
                updated_at       DATETIME        NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_caf_rango (tipo_dte, folio_desde, folio_hasta),
                INDEX idx_caf_tipo_estado (tipo_dte, estado)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        // ── DTE emitidos ──────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE sii_dte (
                id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                tipo_dte                TINYINT         NOT NULL,
                folio                   INT UNSIGNED    NOT NULL,
                rut_emisor              VARCHAR(12)     NOT NULL,
                rut_receptor            VARCHAR(12)     NOT NULL,
                razon_social_receptor   VARCHAR(100)    NOT NULL DEFAULT "",
                fecha_emision           DATE            NOT NULL,
                monto_neto              INT             NOT NULL DEFAULT 0,
                monto_iva               INT             NOT NULL DEFAULT 0,
                monto_exento            INT             NOT NULL DEFAULT 0,
                monto_total             INT             NOT NULL DEFAULT 0,
                track_id                VARCHAR(50)     NULL,
                estado                  ENUM("pendiente","aceptado","rechazado","anulado") NOT NULL DEFAULT "pendiente",
                glosa_sii               VARCHAR(500)    NOT NULL DEFAULT "",
                xml_firmado             LONGTEXT        NOT NULL,
                pdf_bytes               LONGBLOB        NULL,
                created_at              DATETIME        NOT NULL,
                updated_at              DATETIME        NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_dte_folio (tipo_dte, folio, rut_emisor),
                INDEX idx_dte_track (track_id),
                INDEX idx_dte_estado (estado),
                INDEX idx_dte_receptor (rut_receptor),
                INDEX idx_dte_fecha (fecha_emision)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        // ── Folios anulados ───────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE sii_folios_anulados (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tipo_dte   TINYINT      NOT NULL,
                folio      INT UNSIGNED NOT NULL,
                motivo     VARCHAR(255) NOT NULL DEFAULT "",
                anulado_at DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_folio_anulado (tipo_dte, folio)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        // ── Log de envíos ─────────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE sii_log_envios (
                id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                dte_id       INT UNSIGNED  NULL,
                accion       VARCHAR(50)   NOT NULL,
                request_url  VARCHAR(255)  NOT NULL DEFAULT "",
                response_raw TEXT          NOT NULL DEFAULT "",
                http_code    SMALLINT      NOT NULL DEFAULT 0,
                duracion_ms  INT           NOT NULL DEFAULT 0,
                error        VARCHAR(500)  NOT NULL DEFAULT "",
                created_at   DATETIME      NOT NULL,
                PRIMARY KEY (id),
                INDEX idx_log_dte (dte_id),
                INDEX idx_log_accion (accion),
                INDEX idx_log_fecha (created_at),
                CONSTRAINT fk_log_dte FOREIGN KEY (dte_id) REFERENCES sii_dte (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');

        // ── Tokens de sesión ──────────────────────────────────────────────────
        $this->addSql('
            CREATE TABLE sii_tokens (
                id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
                rut_emisor   VARCHAR(12)  NOT NULL,
                ambiente     ENUM("certificacion","produccion") NOT NULL,
                token        VARCHAR(100) NOT NULL,
                expira_at    DATETIME     NOT NULL,
                created_at   DATETIME     NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_token_emisor (rut_emisor, ambiente)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sii_log_envios DROP FOREIGN KEY fk_log_dte');
        $this->addSql('DROP TABLE IF EXISTS sii_log_envios');
        $this->addSql('DROP TABLE IF EXISTS sii_folios_anulados');
        $this->addSql('DROP TABLE IF EXISTS sii_dte');
        $this->addSql('DROP TABLE IF EXISTS sii_caf');
        $this->addSql('DROP TABLE IF EXISTS sii_tokens');
    }
}
