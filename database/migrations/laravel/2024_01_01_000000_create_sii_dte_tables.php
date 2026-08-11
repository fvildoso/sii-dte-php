<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Migraciones para SII DTE PHP.
 *
 * Ubicación: database/migrations/2024_01_01_000000_create_sii_dte_tables.php
 *
 * Ejecutar con:
 *   php artisan migrate
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── CAF (Códigos de Autorización de Folios) ──────────────────────────
        Schema::create('sii_caf', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('tipo_dte')->comment('Tipo DTE: 33, 34, 39, 41, 46, 52, 56, 61, 110, 111, 112');
            $table->unsignedInteger('folio_desde');
            $table->unsignedInteger('folio_hasta');
            $table->unsignedInteger('siguiente_folio');
            $table->date('fecha_vencimiento')->nullable();
            $table->longText('caf_xml');
            $table->enum('estado', ['activo', 'agotado', 'vencido', 'anulado'])->default('activo');
            $table->timestamps();

            $table->unique(['tipo_dte', 'folio_desde', 'folio_hasta'], 'uq_caf_rango');
            $table->index(['tipo_dte', 'estado'], 'idx_caf_tipo_estado');
        });

        // ── DTE emitidos ──────────────────────────────────────────────────────
        Schema::create('sii_dte', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('tipo_dte');
            $table->unsignedInteger('folio');
            $table->string('rut_emisor', 12);
            $table->string('rut_receptor', 12);
            $table->string('razon_social_receptor', 100)->default('');
            $table->date('fecha_emision');
            $table->integer('monto_neto')->default(0);
            $table->integer('monto_iva')->default(0);
            $table->integer('monto_exento')->default(0);
            $table->integer('monto_total')->default(0);
            $table->string('track_id', 50)->nullable();
            $table->enum('estado', ['pendiente', 'aceptado', 'rechazado', 'anulado'])->default('pendiente');
            $table->string('glosa_sii', 500)->default('');
            $table->longText('xml_firmado');
            $table->binary('pdf_bytes')->nullable();
            $table->timestamps();

            $table->unique(['tipo_dte', 'folio', 'rut_emisor'], 'uq_dte_folio');
            $table->index('track_id');
            $table->index('estado');
            $table->index('rut_receptor');
            $table->index('fecha_emision');
        });

        // ── Folios anulados ───────────────────────────────────────────────────
        Schema::create('sii_folios_anulados', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('tipo_dte');
            $table->unsignedInteger('folio');
            $table->string('motivo', 255)->default('');
            $table->timestamp('anulado_at')->useCurrent();

            $table->unique(['tipo_dte', 'folio']);
        });

        // ── Log de envíos al SII ──────────────────────────────────────────────
        Schema::create('sii_log_envios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dte_id')->nullable()->constrained('sii_dte')->nullOnDelete();
            $table->string('accion', 50);
            $table->string('request_url', 255)->default('');
            $table->text('response_raw')->default('');
            $table->smallInteger('http_code')->default(0);
            $table->integer('duracion_ms')->default(0);
            $table->string('error', 500)->default('');
            $table->timestamp('created_at')->useCurrent();

            $table->index('dte_id');
            $table->index('accion');
            $table->index('created_at');
        });

        // ── Tokens de sesión SII ──────────────────────────────────────────────
        Schema::create('sii_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('rut_emisor', 12);
            $table->enum('ambiente', ['certificacion', 'produccion']);
            $table->string('token', 100);
            $table->timestamp('expira_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['rut_emisor', 'ambiente']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sii_log_envios');
        Schema::dropIfExists('sii_folios_anulados');
        Schema::dropIfExists('sii_dte');
        Schema::dropIfExists('sii_caf');
        Schema::dropIfExists('sii_tokens');
    }
};