<?php

use Phalcon\Db\Column;
use Phalcon\Db\Index;
use Phalcon\Db\Reference;
use Phalcon\Migrations\Mvc\Model\Migration;

/**
 * Migraciones para SII DTE PHP.
 *
 * Ubicación: app/migrations/1.0.0/SiiDteMigration.php
 *            (o el path que tengas configurado en phalcon-migrations.php)
 *
 * Ejecutar con:
 *   vendor/bin/phalcon-migrations run
 *
 * Generar desde cero:
 *   vendor/bin/phalcon-migrations generate
 *
 * Requiere:
 *   composer require phalcon/migrations
 */
class SiiDteMigration extends Migration
{
    public function up(): void
    {
        $this->morphTable('sii_caf', [
            'columns' => [
                new Column('id',               ['type' => Column::TYPE_INTEGER,  'unsigned' => true, 'notNull' => true, 'autoIncrement' => true, 'primary' => true]),
                new Column('tipo_dte',         ['type' => Column::TYPE_TINYINTEGER, 'notNull' => true]),
                new Column('folio_desde',      ['type' => Column::TYPE_INTEGER,  'unsigned' => true, 'notNull' => true]),
                new Column('folio_hasta',      ['type' => Column::TYPE_INTEGER,  'unsigned' => true, 'notNull' => true]),
                new Column('siguiente_folio',  ['type' => Column::TYPE_INTEGER,  'unsigned' => true, 'notNull' => true]),
                new Column('fecha_vencimiento',['type' => Column::TYPE_DATE,     'notNull' => false]),
                new Column('caf_xml',          ['type' => Column::TYPE_LONGTEXT, 'notNull' => true]),
                new Column('estado',           ['type' => Column::TYPE_ENUM,     'notNull' => true, 'default' => 'activo',
                    'typeValues' => ['activo', 'agotado', 'vencido', 'anulado']]),
                new Column('created_at',       ['type' => Column::TYPE_DATETIME, 'notNull' => true]),
                new Column('updated_at',       ['type' => Column::TYPE_DATETIME, 'notNull' => false]),
            ],
            'indexes' => [
                new Index('PRIMARY',        ['id'],                                    'PRIMARY'),
                new Index('uq_caf_rango',   ['tipo_dte', 'folio_desde', 'folio_hasta'], 'UNIQUE'),
                new Index('idx_caf_tipo',   ['tipo_dte', 'estado']),
            ],
            'options' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
        ]);

        $this->morphTable('sii_dte', [
            'columns' => [
                new Column('id',                     ['type' => Column::TYPE_INTEGER,     'unsigned' => true, 'notNull' => true, 'autoIncrement' => true, 'primary' => true]),
                new Column('tipo_dte',               ['type' => Column::TYPE_TINYINTEGER, 'notNull' => true]),
                new Column('folio',                  ['type' => Column::TYPE_INTEGER,     'unsigned' => true, 'notNull' => true]),
                new Column('rut_emisor',             ['type' => Column::TYPE_VARCHAR,     'notNull' => true, 'size' => 12]),
                new Column('rut_receptor',           ['type' => Column::TYPE_VARCHAR,     'notNull' => true, 'size' => 12]),
                new Column('razon_social_receptor',  ['type' => Column::TYPE_VARCHAR,     'notNull' => true, 'size' => 100, 'default' => '']),
                new Column('fecha_emision',          ['type' => Column::TYPE_DATE,        'notNull' => true]),
                new Column('monto_neto',             ['type' => Column::TYPE_INTEGER,     'notNull' => true, 'default' => '0']),
                new Column('monto_iva',              ['type' => Column::TYPE_INTEGER,     'notNull' => true, 'default' => '0']),
                new Column('monto_exento',           ['type' => Column::TYPE_INTEGER,     'notNull' => true, 'default' => '0']),
                new Column('monto_total',            ['type' => Column::TYPE_INTEGER,     'notNull' => true, 'default' => '0']),
                new Column('track_id',               ['type' => Column::TYPE_VARCHAR,     'notNull' => false, 'size' => 50]),
                new Column('estado',                 ['type' => Column::TYPE_ENUM,        'notNull' => true, 'default' => 'pendiente',
                    'typeValues' => ['pendiente', 'aceptado', 'rechazado', 'anulado']]),
                new Column('glosa_sii',              ['type' => Column::TYPE_VARCHAR,     'notNull' => true, 'size' => 500, 'default' => '']),
                new Column('xml_firmado',            ['type' => Column::TYPE_LONGTEXT,    'notNull' => true]),
                new Column('pdf_bytes',              ['type' => Column::TYPE_BLOB,        'notNull' => false]),
                new Column('created_at',             ['type' => Column::TYPE_DATETIME,    'notNull' => true]),
                new Column('updated_at',             ['type' => Column::TYPE_DATETIME,    'notNull' => true]),
            ],
            'indexes' => [
                new Index('PRIMARY',       ['id'],                             'PRIMARY'),
                new Index('uq_dte_folio',  ['tipo_dte', 'folio', 'rut_emisor'], 'UNIQUE'),
                new Index('idx_track',     ['track_id']),
                new Index('idx_estado',    ['estado']),
                new Index('idx_receptor',  ['rut_receptor']),
                new Index('idx_fecha',     ['fecha_emision']),
            ],
            'options' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
        ]);

        $this->morphTable('sii_folios_anulados', [
            'columns' => [
                new Column('id',        ['type' => Column::TYPE_INTEGER,     'unsigned' => true, 'notNull' => true, 'autoIncrement' => true, 'primary' => true]),
                new Column('tipo_dte',  ['type' => Column::TYPE_TINYINTEGER, 'notNull' => true]),
                new Column('folio',     ['type' => Column::TYPE_INTEGER,     'unsigned' => true, 'notNull' => true]),
                new Column('motivo',    ['type' => Column::TYPE_VARCHAR,     'notNull' => true, 'size' => 255, 'default' => '']),
                new Column('anulado_at',['type' => Column::TYPE_DATETIME,    'notNull' => true]),
            ],
            'indexes' => [
                new Index('PRIMARY',           ['id'],                'PRIMARY'),
                new Index('uq_folio_anulado',  ['tipo_dte', 'folio'], 'UNIQUE'),
            ],
            'options' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
        ]);

        $this->morphTable('sii_log_envios', [
            'columns' => [
                new Column('id',           ['type' => Column::TYPE_INTEGER,  'unsigned' => true, 'notNull' => true, 'autoIncrement' => true, 'primary' => true]),
                new Column('dte_id',       ['type' => Column::TYPE_INTEGER,  'unsigned' => true, 'notNull' => false]),
                new Column('accion',       ['type' => Column::TYPE_VARCHAR,  'notNull' => true, 'size' => 50]),
                new Column('request_url',  ['type' => Column::TYPE_VARCHAR,  'notNull' => true, 'size' => 255, 'default' => '']),
                new Column('response_raw', ['type' => Column::TYPE_TEXT,     'notNull' => true]),
                new Column('http_code',    ['type' => Column::TYPE_SMALLINT, 'notNull' => true, 'default' => '0']),
                new Column('duracion_ms',  ['type' => Column::TYPE_INTEGER,  'notNull' => true, 'default' => '0']),
                new Column('error',        ['type' => Column::TYPE_VARCHAR,  'notNull' => true, 'size' => 500, 'default' => '']),
                new Column('created_at',   ['type' => Column::TYPE_DATETIME, 'notNull' => true]),
            ],
            'indexes' => [
                new Index('PRIMARY',      ['id'],         'PRIMARY'),
                new Index('idx_log_dte',  ['dte_id']),
                new Index('idx_log_accion',['accion']),
                new Index('idx_log_fecha', ['created_at']),
            ],
            'references' => [
                new Reference('fk_log_dte', [
                    'referencedTable'   => 'sii_dte',
                    'columns'           => ['dte_id'],
                    'referencedColumns' => ['id'],
                    'onDelete'          => 'SET NULL',
                ]),
            ],
            'options' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
        ]);

        $this->morphTable('sii_tokens', [
            'columns' => [
                new Column('id',         ['type' => Column::TYPE_INTEGER,  'unsigned' => true, 'notNull' => true, 'autoIncrement' => true, 'primary' => true]),
                new Column('rut_emisor', ['type' => Column::TYPE_VARCHAR,  'notNull' => true, 'size' => 12]),
                new Column('ambiente',   ['type' => Column::TYPE_ENUM,     'notNull' => true, 'typeValues' => ['certificacion', 'produccion']]),
                new Column('token',      ['type' => Column::TYPE_VARCHAR,  'notNull' => true, 'size' => 100]),
                new Column('expira_at',  ['type' => Column::TYPE_DATETIME, 'notNull' => true]),
                new Column('created_at', ['type' => Column::TYPE_DATETIME, 'notNull' => true]),
            ],
            'indexes' => [
                new Index('PRIMARY',           ['id'],                    'PRIMARY'),
                new Index('uq_token_emisor',   ['rut_emisor', 'ambiente'], 'UNIQUE'),
            ],
            'options' => ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci'],
        ]);
    }

    public function down(): void
    {
        $this->getConnection()->dropTable('sii_log_envios');
        $this->getConnection()->dropTable('sii_folios_anulados');
        $this->getConnection()->dropTable('sii_dte');
        $this->getConnection()->dropTable('sii_caf');
        $this->getConnection()->dropTable('sii_tokens');
    }
}