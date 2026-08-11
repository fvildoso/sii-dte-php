<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Migraciones para SII DTE PHP.
 *
 * Ubicación: db/migrations/ (o la que tengas en phinx.php / phinx.yml)
 *
 * Ejecutar con:
 *   vendor/bin/phinx migrate
 *
 * Revertir con:
 *   vendor/bin/phinx rollback
 *
 * Requiere:
 *   composer require robmorgan/phinx
 */
final class CreateSiiDteTables extends AbstractMigration
{
    public function up(): void
    {
        // ── CAF (Códigos de Autorización de Folios) ──────────────────────────
        $this->table('sii_caf', ['id' => true, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('tipo_dte',          'integer',  ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,  'null' => false, 'comment' => 'Tipo DTE: 33,34,39,41,46,52,56,61,110,111,112'])
            ->addColumn('folio_desde',       'integer',  ['signed' => false, 'null' => false])
            ->addColumn('folio_hasta',       'integer',  ['signed' => false, 'null' => false])
            ->addColumn('siguiente_folio',   'integer',  ['signed' => false, 'null' => false])
            ->addColumn('fecha_vencimiento', 'date',     ['null' => true])
            ->addColumn('caf_xml',           'text',     ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('estado',            'enum',     ['values' => ['activo', 'agotado', 'vencido', 'anulado'], 'default' => 'activo', 'null' => false])
            ->addColumn('created_at',        'datetime', ['null' => false])
            ->addColumn('updated_at',        'datetime', ['null' => true])
            ->addIndex(['tipo_dte', 'folio_desde', 'folio_hasta'], ['unique' => true, 'name' => 'uq_caf_rango'])
            ->addIndex(['tipo_dte', 'estado'],                     ['name' => 'idx_caf_tipo_estado'])
            ->create();

        // ── DTE emitidos ──────────────────────────────────────────────────────
        $this->table('sii_dte', ['id' => true, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('tipo_dte',               'integer',  ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'null' => false])
            ->addColumn('folio',                  'integer',  ['signed' => false, 'null' => false])
            ->addColumn('rut_emisor',             'string',   ['limit' => 12, 'null' => false])
            ->addColumn('rut_receptor',           'string',   ['limit' => 12, 'null' => false])
            ->addColumn('razon_social_receptor',  'string',   ['limit' => 100, 'null' => false, 'default' => ''])
            ->addColumn('fecha_emision',          'date',     ['null' => false])
            ->addColumn('monto_neto',             'integer',  ['null' => false, 'default' => 0])
            ->addColumn('monto_iva',              'integer',  ['null' => false, 'default' => 0])
            ->addColumn('monto_exento',           'integer',  ['null' => false, 'default' => 0])
            ->addColumn('monto_total',            'integer',  ['null' => false, 'default' => 0])
            ->addColumn('track_id',               'string',   ['limit' => 50, 'null' => true])
            ->addColumn('estado',                 'enum',     ['values' => ['pendiente', 'aceptado', 'rechazado', 'anulado'], 'default' => 'pendiente', 'null' => false])
            ->addColumn('glosa_sii',              'string',   ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('xml_firmado',            'text',     ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_LONG, 'null' => false])
            ->addColumn('pdf_bytes',              'blob',     ['limit' => \Phinx\Db\Adapter\MysqlAdapter::BLOB_LONG, 'null' => true])
            ->addColumn('created_at',             'datetime', ['null' => false])
            ->addColumn('updated_at',             'datetime', ['null' => false])
            ->addIndex(['tipo_dte', 'folio', 'rut_emisor'], ['unique' => true, 'name' => 'uq_dte_folio'])
            ->addIndex(['track_id'],                         ['name' => 'idx_dte_track'])
            ->addIndex(['estado'],                           ['name' => 'idx_dte_estado'])
            ->addIndex(['rut_receptor'],                     ['name' => 'idx_dte_receptor'])
            ->addIndex(['fecha_emision'],                    ['name' => 'idx_dte_fecha'])
            ->create();

        // ── Folios anulados ───────────────────────────────────────────────────
        $this->table('sii_folios_anulados', ['id' => true, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('tipo_dte',   'integer',  ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'null' => false])
            ->addColumn('folio',      'integer',  ['signed' => false, 'null' => false])
            ->addColumn('motivo',     'string',   ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('anulado_at', 'datetime', ['null' => false])
            ->addIndex(['tipo_dte', 'folio'], ['unique' => true, 'name' => 'uq_folio_anulado'])
            ->create();

        // ── Log de envíos al SII ──────────────────────────────────────────────
        $this->table('sii_log_envios', ['id' => true, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('dte_id',       'integer',  ['signed' => false, 'null' => true])
            ->addColumn('accion',       'string',   ['limit' => 50,  'null' => false])
            ->addColumn('request_url',  'string',   ['limit' => 255, 'null' => false, 'default' => ''])
            ->addColumn('response_raw', 'text',     ['null' => false, 'default' => ''])
            ->addColumn('http_code',    'integer',  ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_SMALL, 'null' => false, 'default' => 0])
            ->addColumn('duracion_ms',  'integer',  ['null' => false, 'default' => 0])
            ->addColumn('error',        'string',   ['limit' => 500, 'null' => false, 'default' => ''])
            ->addColumn('created_at',   'datetime', ['null' => false])
            ->addIndex(['dte_id'],     ['name' => 'idx_log_dte'])
            ->addIndex(['accion'],     ['name' => 'idx_log_accion'])
            ->addIndex(['created_at'], ['name' => 'idx_log_fecha'])
            ->addForeignKey('dte_id', 'sii_dte', 'id', ['delete' => 'SET_NULL', 'update' => 'NO_ACTION'])
            ->create();

        // ── Tokens de sesión SII ──────────────────────────────────────────────
        $this->table('sii_tokens', ['id' => true, 'primary_key' => 'id', 'engine' => 'InnoDB', 'collation' => 'utf8mb4_unicode_ci'])
            ->addColumn('rut_emisor', 'string',   ['limit' => 12,  'null' => false])
            ->addColumn('ambiente',   'enum',     ['values' => ['certificacion', 'produccion'], 'null' => false])
            ->addColumn('token',      'string',   ['limit' => 100, 'null' => false])
            ->addColumn('expira_at',  'datetime', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['rut_emisor', 'ambiente'], ['unique' => true, 'name' => 'uq_token_emisor'])
            ->create();
    }

    public function down(): void
    {
        $this->table('sii_log_envios')->drop()->save();
        $this->table('sii_folios_anulados')->drop()->save();
        $this->table('sii_dte')->drop()->save();
        $this->table('sii_caf')->drop()->save();
        $this->table('sii_tokens')->drop()->save();
    }
}