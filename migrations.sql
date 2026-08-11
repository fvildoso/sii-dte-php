-- =============================================================================
-- SII DTE PHP — Migraciones de Base de Datos
-- Compatibles con MySQL 8+ y PostgreSQL 14+
-- =============================================================================
-- Ejecutar en orden. Para PostgreSQL reemplaza:
--   - NOW() → CURRENT_TIMESTAMP
--   - CURDATE() → CURRENT_DATE
--   - AUTO_INCREMENT → SERIAL
--   - TEXT → TEXT (igual)
--   - FOR UPDATE → FOR UPDATE (igual)
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. CAF (Códigos de Autorización de Folios)
-- -----------------------------------------------------------------------------
-- Aquí se guardan los archivos CAF que descargás del SII.
-- Cada fila representa un rango de folios autorizado para un tipo de DTE.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_caf (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tipo_dte         TINYINT         NOT NULL COMMENT 'Tipo DTE: 33, 34, 39, 41, 46, 52, 56, 61, 110, 111, 112',
    folio_desde      INT UNSIGNED    NOT NULL COMMENT 'Primer folio del rango autorizado',
    folio_hasta      INT UNSIGNED    NOT NULL COMMENT 'Último folio del rango autorizado',
    siguiente_folio  INT UNSIGNED    NOT NULL COMMENT 'Próximo folio a usar (se incrementa en cada emisión)',
    fecha_vencimiento DATE           NULL     COMMENT 'Fecha de vencimiento del CAF (si aplica)',
    caf_xml          LONGTEXT        NOT NULL COMMENT 'Contenido completo del archivo CAF.xml del SII',
    estado           ENUM('activo','agotado','vencido','anulado') NOT NULL DEFAULT 'activo',
    created_at       DATETIME        NOT NULL,
    updated_at       DATETIME        NULL,

    PRIMARY KEY (id),
    -- Garantiza que no haya dos CAF activos con el mismo rango para el mismo tipo
    UNIQUE KEY uq_caf_rango (tipo_dte, folio_desde, folio_hasta),
    INDEX idx_caf_tipo_estado (tipo_dte, estado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Códigos de autorización de folios descargados del SII';


-- -----------------------------------------------------------------------------
-- 2. DTE emitidos
-- -----------------------------------------------------------------------------
-- Registro de todos los documentos emitidos, con su XML firmado y estado SII.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_dte (
    id                      INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    tipo_dte                TINYINT         NOT NULL,
    folio                   INT UNSIGNED    NOT NULL,
    rut_emisor              VARCHAR(12)     NOT NULL COMMENT 'Ej: 12345678-9',
    rut_receptor            VARCHAR(12)     NOT NULL,
    razon_social_receptor   VARCHAR(100)    NOT NULL DEFAULT '',
    fecha_emision           DATE            NOT NULL,

    -- Montos (en pesos chilenos)
    monto_neto              INT             NOT NULL DEFAULT 0,
    monto_iva               INT             NOT NULL DEFAULT 0,
    monto_exento            INT             NOT NULL DEFAULT 0,
    monto_total             INT             NOT NULL DEFAULT 0,

    -- SII
    track_id                VARCHAR(50)     NULL     COMMENT 'ID de seguimiento devuelto por el SII al enviar',
    estado                  ENUM('pendiente','aceptado','rechazado','anulado') NOT NULL DEFAULT 'pendiente',
    glosa_sii               VARCHAR(500)    NOT NULL DEFAULT '' COMMENT 'Mensaje del SII sobre el estado',

    -- Documentos
    xml_firmado             LONGTEXT        NOT NULL COMMENT 'XML del DTE con firma digital',
    pdf_bytes               LONGBLOB        NULL     COMMENT 'PDF generado (opcional; puede guardarse en S3/disco)',

    -- Auditoría
    created_at              DATETIME        NOT NULL,
    updated_at              DATETIME        NOT NULL,

    PRIMARY KEY (id),
    -- Un folio es único por tipo y emisor
    UNIQUE KEY uq_dte_folio (tipo_dte, folio, rut_emisor),
    INDEX idx_dte_track (track_id),
    INDEX idx_dte_estado (estado),
    INDEX idx_dte_receptor (rut_receptor),
    INDEX idx_dte_fecha (fecha_emision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='DTE emitidos con XML firmado y estado en el SII';


-- -----------------------------------------------------------------------------
-- 3. Folios anulados
-- -----------------------------------------------------------------------------
-- Cuando un DTE es rechazado o se detecta un error antes de enviar,
-- el folio se pierde (no se puede reutilizar). Esto lo registra para auditoría.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_folios_anulados (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo_dte   TINYINT      NOT NULL,
    folio      INT UNSIGNED NOT NULL,
    motivo     VARCHAR(255) NOT NULL DEFAULT '',
    anulado_at DATETIME     NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_folio_anulado (tipo_dte, folio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------------
-- 4. Log de envíos al SII
-- -----------------------------------------------------------------------------
-- Historial de comunicaciones con el SII (útil para debugging).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_log_envios (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    dte_id       INT UNSIGNED  NULL     REFERENCES sii_dte(id),
    accion       VARCHAR(50)   NOT NULL COMMENT 'getSeed, getToken, uploadDte, queryStatus, etc.',
    request_url  VARCHAR(255)  NOT NULL DEFAULT '',
    response_raw TEXT          NOT NULL DEFAULT '',
    http_code    SMALLINT      NOT NULL DEFAULT 0,
    duracion_ms  INT           NOT NULL DEFAULT 0,
    error        VARCHAR(500)  NOT NULL DEFAULT '',
    created_at   DATETIME      NOT NULL,

    PRIMARY KEY (id),
    INDEX idx_log_dte (dte_id),
    INDEX idx_log_accion (accion),
    INDEX idx_log_fecha (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Log de todas las comunicaciones con el SII';


-- -----------------------------------------------------------------------------
-- 5. Tokens de sesión SII (caché)
-- -----------------------------------------------------------------------------
-- Evita pedir un nuevo token en cada operación (el token dura ~1 hora).
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sii_tokens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rut_emisor   VARCHAR(12)  NOT NULL,
    ambiente     ENUM('certificacion', 'produccion') NOT NULL,
    token        VARCHAR(100) NOT NULL,
    expira_at    DATETIME     NOT NULL,
    created_at   DATETIME     NOT NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_token_emisor (rut_emisor, ambiente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Caché de tokens de sesión del SII';


-- =============================================================================
-- DATOS INICIALES DE EJEMPLO (opcionales, para desarrollo)
-- =============================================================================

-- Insertar un CAF de ejemplo para certificación (tipo 33, folios 1-50):
-- INSERT INTO sii_caf (tipo_dte, folio_desde, folio_hasta, siguiente_folio, caf_xml, estado, created_at)
-- VALUES (33, 1, 50, 1, '<AUTORIZACION>...contenido del CAF.xml...</AUTORIZACION>', 'activo', NOW());
