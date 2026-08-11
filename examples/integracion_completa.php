<?php

/**
 * Ejemplo completo de integración SII DTE PHP
 * Muestra cómo conectar todos los componentes: certificado, folios, BD, envío y PDF.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use SiiDte\SiiClient;
use SiiDte\Document\DteTypes;
use SiiDte\Folio\FolioManager;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteRepository;
use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\FolioAgotadoException;

// =============================================================================
// PASO 1 — Cargar el certificado digital de forma segura
// =============================================================================
// OPCIÓN A: desde variable de entorno (recomendado para producción)
// Requiere en .env: SII_CERT_B64=<base64 del .p12>  SII_CERT_PASS=<contraseña>
//
//   $cert = CertificateStore::fromEnv('SII_CERT_B64', 'SII_CERT_PASS');

// OPCIÓN B: desde archivo en ruta segura fuera del webroot
$cert = CertificateStore::fromFile(
    certPath: '/var/secure/certs/empresa.p12',  // ← fuera de /public/ o /www/
    certPassword: getenv('SII_CERT_PASS') ?: 'mi_contraseña'
);

// Ver info del certificado (útil para panel de administración)
$certInfo = $cert->getInfo();
echo "Certificado válido hasta: {$certInfo['valid_to']}\n";
echo "Días de vigencia: {$certInfo['dias_vigencia']}\n";
if ($cert->venceProximo(30)) {
    echo "⚠️  ADVERTENCIA: El certificado vence en menos de 30 días. Renuévalo.\n";
}

// =============================================================================
// PASO 2 — Conectar la base de datos y preparar los managers
// =============================================================================
$pdo = new PDO(
    dsn: 'mysql:host=localhost;dbname=mi_empresa;charset=utf8mb4',
    username: 'usuario_bd',
    password: getenv('DB_PASS') ?: 'contraseña_bd',
    options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// FolioManager: asigna folios del CAF con bloqueo de concurrencia
$folioManager = new FolioManager($pdo);

// DteRepository: persiste los DTE emitidos en la BD
$dteRepo = new DteRepository($pdo);

// Verificar folios disponibles antes de operar
$disponibles = $folioManager->foliosDisponibles(DteTypes::FACTURA_ELECTRONICA);
echo "Folios disponibles (Factura tipo 33): {$disponibles}\n";
if ($disponibles < 10) {
    echo "⚠️  ALERTA: Quedan pocos folios. Solicita un nuevo CAF en https://misiir.sii.cl\n";
    // En producción: enviar email/Slack al administrador
}

// =============================================================================
// PASO 3 — Construir el cliente SII
// =============================================================================
$client = new SiiClient(
    config: [
        'rut_emisor'         => '12345678',
        'dv_emisor'          => '9',
        'razon_social'       => 'Mi Empresa SpA',
        'giro'               => 'Desarrollo y Venta de Software',
        'direccion'          => 'Av. Providencia 1234, Of. 501',
        'ciudad'             => 'Santiago',
        'codigo_actividad'   => '620200',        // Código CIIU de tu actividad económica
        'telefono'           => '+56212345678',  // Opcional
        'email'              => 'facturacion@miempresa.cl', // Opcional
        'numero_resolucion'  => 0,               // 0 en certificación; el real en producción
        'fecha_resolucion'   => '2019-10-18',    // Fecha de tu resolución SII
        'ambiente'           => 'certificacion', // ← cambiar a 'produccion' cuando esté listo
    ],
    certStore: $cert
);

// Conectar el FolioManager y el repositorio
$client
    ->usarFolioManager($folioManager)
    ->usarRepositorio($dteRepo);

// =============================================================================
// CASO 1: Factura Electrónica (tipo 33)
// =============================================================================
echo "\n=== Emitiendo Factura Electrónica ===\n";
try {
    $resultado = $client->enviarDte(DteTypes::FACTURA_ELECTRONICA, [
        // 'folio' NO es necesario: FolioManager lo asigna automáticamente
        'fecha' => date('Y-m-d'),
        'forma_pago' => 1,            // 1=Contado, 2=Crédito

        'receptor' => [
            'rut'          => '98765432',
            'dv'           => '1',
            'razon_social' => 'Empresa Compradora Ltda',
            'giro'         => 'Comercio al por Mayor',
            'direccion'    => 'Calle San Martín 456',
            'ciudad'       => 'Valparaíso',
            'email'        => 'compras@empresacompradora.cl', // Para envío electrónico
        ],

        'detalle' => [
            [
                'codigo'          => 'SW-2024-001',   // Código interno (recomendado)
                'nombre'          => 'Licencia Software ERP',
                'descripcion'     => 'Licencia anual módulo ventas - 5 usuarios',
                'cantidad'        => 1,
                'unidad'          => 'UN',
                'precio_unitario' => 595238,    // Precio con IVA incluido
            ],
            [
                'codigo'          => 'SOPORTE-HH',
                'nombre'          => 'Horas de Soporte Técnico',
                'cantidad'        => 10,
                'unidad'          => 'HR',
                'precio_unitario' => 89286,
                'descuento_pct'   => 10,        // 10% de descuento
            ],
            [
                'nombre'          => 'Capacitación Remota',
                'cantidad'        => 1,
                'precio_unitario' => 50000,
                'exento'          => true,       // Ítem exento de IVA en el mismo documento
            ],
        ],
    ]);

    echo "✅ Factura enviada exitosamente\n";
    echo "   Folio asignado: {$resultado['folio']}\n";
    echo "   Track ID SII:   {$resultado['trackId']}\n";
    echo "   ID en BD:       {$resultado['dte_id']}\n";

    // Guardar Track ID en tu sistema para consultar después
    $trackId = $resultado['trackId'];
    $xmlFirmado = $resultado['xml'];

    // Opcional: guardar el XML en disco
    @mkdir(__DIR__ . '/output', 0755, true);
    file_put_contents(__DIR__ . "/output/factura_{$resultado['folio']}.xml", $xmlFirmado);

    // Generar HTML para PDF
    $html = $client->generarHtmlPdf($xmlFirmado, [
        'logo_url'        => 'https://miempresa.cl/logo.png', // o base64
        'color_primario'  => '#1e3a5f',
    ]);
    file_put_contents(__DIR__ . "/output/factura_{$resultado['folio']}.html", $html);
    echo "   HTML generado: output/factura_{$resultado['folio']}.html\n";
    echo "   Renderiza con dompdf: composer require dompdf/dompdf\n";

} catch (FolioAgotadoException $e) {
    echo "❌ Sin folios: " . $e->getMessage() . "\n";
    exit(1);
} catch (SiiException $e) {
    echo "❌ Error SII: " . $e->getMessage() . "\n";
    exit(1);
}

// =============================================================================
// CASO 2: Boleta Electrónica (tipo 39) — consumidor final
// =============================================================================
echo "\n=== Emitiendo Boleta Electrónica ===\n";
try {
    $res = $client->enviarDte(DteTypes::BOLETA_ELECTRONICA, [
        'fecha' => date('Y-m-d'),
        // Receptor: consumidor final (RUT genérico del SII para boletas)
        'receptor' => [
            'rut'          => '66666666',
            'dv'           => '6',
            'razon_social' => 'Consumidor Final',
        ],
        'detalle' => [
            [
                'nombre'          => 'Producto de venta',
                'cantidad'        => 3,
                'precio_unitario' => 11900,
            ],
        ],
    ]);
    echo "✅ Boleta enviada. Folio: {$res['folio']} | Track: {$res['trackId']}\n";
} catch (SiiException $e) {
    echo "❌ Error boleta: " . $e->getMessage() . "\n";
}

// =============================================================================
// CASO 3: Guía de Despacho (tipo 52) — requiere ind_traslado y transporte
// =============================================================================
echo "\n=== Emitiendo Guía de Despacho ===\n";
try {
    $res = $client->enviarDte(DteTypes::GUIA_DESPACHO_ELECTRONICA, [
        'fecha'         => date('Y-m-d'),
        'ind_traslado'  => 1,   // 1=Venta, 2=Ventas por efectuar, 5=Traslado interno...
        'receptor' => [
            'rut'          => '98765432',
            'dv'           => '1',
            'razon_social' => 'Empresa Compradora Ltda',
            'direccion'    => 'Bodega Central, Km 5 Ruta 68',
            'ciudad'       => 'Valparaíso',
        ],
        'transporte' => [
            'patente'           => 'ABCD12',
            'rut_transportista' => '11111111-1',
            'direccion_destino' => 'Bodega Central, Km 5 Ruta 68',
            'ciudad_destino'    => 'Valparaíso',
        ],
        'detalle' => [
            ['nombre' => 'Cajas de producto A', 'cantidad' => 50, 'unidad' => 'UN', 'precio_unitario' => 11900],
            ['nombre' => 'Pallets de producto B', 'cantidad' => 5, 'unidad' => 'PAL', 'precio_unitario' => 119000],
        ],
    ]);
    echo "✅ Guía de Despacho enviada. Folio: {$res['folio']} | Track: {$res['trackId']}\n";
} catch (SiiException $e) {
    echo "❌ Error guía: " . $e->getMessage() . "\n";
}

// =============================================================================
// CASO 4: Nota de Crédito (tipo 61) — anula o corrige una factura
// =============================================================================
echo "\n=== Emitiendo Nota de Crédito ===\n";
try {
    $res = $client->enviarDte(DteTypes::NOTA_CREDITO_ELECTRONICA, [
        'fecha' => date('Y-m-d'),
        'receptor' => [
            'rut' => '98765432', 'dv' => '1',
            'razon_social' => 'Empresa Compradora Ltda',
        ],
        'detalle' => [
            ['nombre' => 'Devolución mercadería', 'cantidad' => 1, 'precio_unitario' => 119000],
        ],
        // REQUERIDO en NC: referencia al documento que se corrige/anula
        'referencias' => [
            [
                'tipo_doc'   => 33,           // Tipo del doc referenciado (Factura)
                'folio'      => 100,           // Folio de la factura original
                'fecha'      => '2024-01-15',
                'codigo_ref' => 1,             // 1=Anula, 2=Corrige texto, 3=Corrige montos
                'razon'      => 'Se anula por devolución de mercadería defectuosa',
            ],
        ],
    ]);
    echo "✅ Nota de Crédito enviada. Folio: {$res['folio']} | Track: {$res['trackId']}\n";
} catch (SiiException $e) {
    echo "❌ Error NC: " . $e->getMessage() . "\n";
}

// =============================================================================
// CASO 5: Factura de Exportación (tipo 110)
// =============================================================================
echo "\n=== Emitiendo Factura de Exportación ===\n";
try {
    $res = $client->enviarDte(DteTypes::FACTURA_EXPORTACION_ELECTRONICA, [
        'fecha'         => date('Y-m-d'),
        'moneda'        => 'USD',    // Moneda del documento
        'ind_servicio'  => 4,        // 4=Exportación de servicios
        'receptor' => [
            'rut'          => '55555555',  // RUT genérico para extranjeros
            'dv'           => '5',
            'razon_social' => 'Acme Corp Ltd',
            'pais'         => 'US',        // REQUERIDO: código ISO del país
            'direccion'    => '123 Main St, New York',
        ],
        'detalle' => [
            ['nombre' => 'Software Development Services', 'cantidad' => 1, 'precio_unitario' => 5000],
        ],
    ]);
    echo "✅ Factura Exportación enviada. Folio: {$res['folio']} | Track: {$res['trackId']}\n";
} catch (SiiException $e) {
    echo "❌ Error exportación: " . $e->getMessage() . "\n";
}

// =============================================================================
// PASO 4 — Consultar estado (esperar al menos 5-15 minutos)
// =============================================================================
echo "\n=== Consultando estado del primer envío ===\n";
try {
    sleep(2); // En producción espera varios minutos; esto es solo demo
    $estado = $client->consultarEstado($trackId);
    echo "Estado SII: {$estado['estado']}\n";
    echo "Glosa:      {$estado['glosa']}\n";
} catch (SiiException $e) {
    echo "Estado no disponible aún (normal si se acaba de enviar): " . $e->getMessage() . "\n";
}

// =============================================================================
// PASO 5 — Procesar pendientes (para correr como cron job)
// =============================================================================
// Agrega esto a tu cron:
// */15 * * * * php /ruta/a/tu/proyecto/bin/procesar_pendientes.php
//
// El script bin/procesar_pendientes.php haría:
//   $resultados = $client->procesarPendientes();
//   foreach ($resultados as $r) {
//       echo "DTE Tipo {$r['tipo']} Folio {$r['folio']}: {$r['estado']}\n";
//   }

echo "\n✅ Demo completado. Revisa output/ para los XML y HTML generados.\n";
