# SII DTE PHP

Librería PHP para integración con el **Sistema de Facturación Electrónica (DTE)** del Servicio de Impuestos Internos de Chile.

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)

---

## ¿Qué hace esta librería y qué no?

### ✅ Se hace cargo la librería

| Función | Clase responsable |
|---------|------------------|
| Autenticación con el SII (semilla → token, renovación automática) | `Auth/TokenManager` |
| Generación del XML DTE (tipos 33, 34, 39, 41, 46, 52, 56, 61, 110, 111, 112) | `Document/DteBuilder` |
| Cálculo automático de neto, IVA 19% y total | `Document/DteBuilder` |
| Firma digital XMLDSig del DTE y del sobre EnvioDTE | `Signature/XmlSigner` |
| Construcción del sobre EnvioDTE | `Envelope/EnvelopeBuilder` |
| Envío al SII y obtención del Track ID | `Utils/SiiWebService` |
| Consulta de estado por Track ID | `Utils/SiiWebService` |
| Asignación de folios CAF con bloqueo de concurrencia | `Folio/FolioManager` |
| Validación del certificado digital (vencimiento, ruta segura) | `Storage/CertificateStore` |
| Carga del certificado desde archivo o variable de entorno | `Storage/CertificateStore` |
| Persistencia del XML firmado, Track ID y estado en la BD | `Storage/DteRepository` |
| Generación de HTML renderizable a PDF | `Pdf/PdfGenerator` |
| Actualización masiva de estados pendientes (para cron) | `SiiClient::procesarPendientes()` |

### ❌ Debes implementar en tu aplicación

| Tarea | Cómo hacerlo |
|-------|-------------|
| Crear las tablas en la BD | Ejecutar `database/migrations.sql` |
| Importar el CAF al sistema | `$folioManager->importarCaf($xml, $tipo)` |
| Alertas de folios por agotarse | Consultar `$folioManager->foliosDisponibles($tipo)` |
| Solicitar nuevos CAF al SII | Manual en misiir.sii.cl cuando queden pocos folios |
| Renovar el certificado digital | Con tu proveedor antes del vencimiento |
| Cron de actualización de estados | `$client->procesarPendientes()` cada 15 minutos |
| Generar el PDF final | Instalar `dompdf/dompdf` y renderizar el HTML |
| Timbre electrónico PDF417 | Ver `docs/timbre.md` |
| Guardar/enviar el PDF al cliente | Tu lógica de negocio |

---

## Tipos de DTE soportados

| Código | Nombre | IVA | Observaciones |
|--------|--------|-----|---------------|
| **33** | Factura Electrónica | Afecta | Más común. Receptor es empresa |
| **34** | Factura No Afecta Electrónica | Exenta | Servicios médicos, educación |
| **39** | Boleta Electrónica | Afecta | Consumidor final. Obligatoria desde 2023 |
| **41** | Boleta No Afecta Electrónica | Exenta | Boleta a consumidor final sin IVA |
| **46** | Factura de Compra Electrónica | Afecta | La emite el COMPRADOR, retiene el IVA |
| **52** | Guía de Despacho Electrónica | Afecta | Requiere `ind_traslado` |
| **56** | Nota de Débito Electrónica | Afecta | Aumenta monto. Requiere referencia |
| **61** | Nota de Crédito Electrónica | Afecta | Anula o corrige. Requiere referencia |
| **110** | Factura de Exportación | Sin IVA | Moneda extranjera. Requiere país |
| **111** | Nota de Débito Exportación | Sin IVA | Requiere referencia |
| **112** | Nota de Crédito Exportación | Sin IVA | Requiere referencia |

---

## Instalación

```bash
composer require tuempresa/sii-dte-php
```

---

## Flujo completo paso a paso

### PASO 1 — Obtener el certificado digital

Proveedor acreditado: E-CERT Chile, E-Sign, Acepta.com. El certificado viene en formato `.p12`.

```bash
# Verificar que es válido:
openssl pkcs12 -info -in empresa.p12 -noout

# Ver vencimiento:
openssl pkcs12 -in empresa.p12 -nokeys -clcerts | openssl x509 -text | grep "Not After"
```

**Dónde guardarlo:**
```bash
# CORRECTO: fuera del webroot
/var/secure/certs/empresa.p12
chmod 640 /var/secure/certs/empresa.p12

# INCORRECTO: nunca en directorio público
# /var/www/html/public/cert.p12  ← cualquiera lo descarga
```

La contraseña del certificado debe venir de variable de entorno:
```bash
# En .env o en el servidor:
SII_CERT_PASS=mi_contraseña_segura

# Agregar al .gitignore:
echo "*.p12" >> .gitignore
```

---

### PASO 2 — Registrarse en el SII

1. Ir a **misiir.sii.cl** con el RUT de tu empresa
2. **Servicios online → Factura Electrónica → Sistema de Facturación Propio**
3. Subir tu certificado digital para registro
4. Anotar el **número de resolución** y **fecha de resolución**
   - En certificación: número = `0`, fecha = `2019-10-18`

---

### PASO 3 — Crear las tablas en la base de datos

```bash
mysql -u usuario -p mi_base_de_datos < database/migrations.sql
```

Las tablas creadas:

| Tabla | Contenido |
|-------|-----------|
| `sii_caf` | CAF importados con rango de folios y estado |
| `sii_dte` | DTE emitidos con XML firmado, Track ID y estado |
| `sii_folios_anulados` | Folios perdidos por rechazo |
| `sii_log_envios` | Log de comunicaciones con el SII |
| `sii_tokens` | Caché de tokens de sesión |

---

### PASO 4 — Obtener y cargar el CAF

El CAF (Código de Autorización de Folios) es el permiso del SII para emitir documentos en un rango de números.

**Obtenerlo:**
1. Portal SII: **Factura Electrónica → Solicitar Folios**
2. Seleccionar tipo (ej: 33 = Factura) y cantidad (ej: 100)
3. Descargar el `.xml` del CAF

**Importarlo:**
```php
$pdo = new PDO('mysql:host=localhost;dbname=mi_bd', 'user', 'pass');
$folioManager = new SiiDte\Folio\FolioManager($pdo);

$cafXml = file_get_contents('/ruta/caf_tipo33.xml');
$folioManager->importarCaf($cafXml, tipoDte: 33);

echo "Disponibles: " . $folioManager->foliosDisponibles(33); // → 100
```

**Alerta de agotamiento:**
```php
if ($folioManager->foliosDisponibles(33) < 10) {
    enviarAlerta("⚠️ Pocos folios. Solicita CAF en el SII.");
}
```

---

### PASO 5 — Configurar el cliente

```php
use SiiDte\SiiClient;
use SiiDte\Folio\FolioManager;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteRepository;

// Opción A: desde archivo
$cert = CertificateStore::fromFile(
    '/var/secure/certs/empresa.p12',
    getenv('SII_CERT_PASS')
);

// Opción B: desde variable de entorno (base64 del .p12)
// $cert = CertificateStore::fromEnv('SII_CERT_B64', 'SII_CERT_PASS');

$pdo          = new PDO('mysql:host=localhost;dbname=mi_bd', 'user', 'pass');
$folioManager = new FolioManager($pdo);
$dteRepo      = new DteRepository($pdo);

$client = new SiiClient(
    config: [
        'rut_emisor'        => '12345678',
        'dv_emisor'         => '9',
        'razon_social'      => 'Mi Empresa SpA',
        'giro'              => 'Desarrollo de Software',
        'direccion'         => 'Av. Providencia 1234',
        'ciudad'            => 'Santiago',
        'codigo_actividad'  => '620200',       // Código CIIU de tu empresa
        'telefono'          => '+56212345678', // opcional
        'email'             => 'dte@empresa.cl', // opcional
        'numero_resolucion' => 0,              // 0 en certificación
        'fecha_resolucion'  => '2019-10-18',
        'ambiente'          => 'certificacion', // ← 'produccion' cuando esté aprobado
    ],
    certStore: $cert
);

$client
    ->usarFolioManager($folioManager)  // asignación automática de folios
    ->usarRepositorio($dteRepo);       // persistencia en BD
```

---

### PASO 6 — Emitir documentos

```php
use SiiDte\Document\DteTypes;

// Factura Electrónica
$resultado = $client->enviarDte(DteTypes::FACTURA_ELECTRONICA, [
    'fecha'    => date('Y-m-d'),
    // folio: se asigna automáticamente si usas FolioManager
    'receptor' => [
        'rut' => '98765432', 'dv' => '1',
        'razon_social' => 'Empresa Compradora Ltda',
        'giro'         => 'Comercio',
        'direccion'    => 'Calle Ejemplo 123',
        'ciudad'       => 'Santiago',
    ],
    'detalle' => [
        [
            'codigo'          => 'PROD-001',
            'nombre'          => 'Producto A',
            'cantidad'        => 2,
            'precio_unitario' => 119000, // precio con IVA incluido
        ],
    ],
]);

echo "Track ID: " . $resultado['trackId']; // → guardar esto para consultar después
echo "Folio:    " . $resultado['folio'];
```

Ver más ejemplos para cada tipo de DTE en **`docs/estructura_datos.md`** y **`examples/integracion_completa.php`**.

---

### PASO 7 — Generar el PDF

```bash
# Instalar dompdf:
composer require dompdf/dompdf
```

```php
$html = $client->generarHtmlPdf($resultado['xml'], [
    'logo_url'       => 'https://tuempresa.cl/logo.png',
    'color_primario' => '#2c5f8a',
]);

$pdf = new \Dompdf\Dompdf();
$pdf->loadHtml($html);
$pdf->setPaper('letter', 'portrait');
$pdf->render();
file_put_contents("factura_{$resultado['folio']}.pdf", $pdf->output());
```

> **Timbre Electrónico:** El PDF oficial del SII incluye un código de barras 2D (PDF417) generado con los datos del CAF y firma del SII. Ver `docs/timbre.md` para implementarlo.

---

### PASO 8 — Consultar estado

```php
// Espera al menos 5-15 minutos después de enviar
$estado = $client->consultarEstado($resultado['trackId']);
// $estado['estado']: EPR=Aceptado, RSC=Rechazado, SOK=En proceso, DNK=No encontrado
// $estado['glosa']:  Mensaje descriptivo del SII
```

**Cron de actualización automática:**
```bash
# crontab -e
*/15 * * * * php /ruta/proyecto/bin/procesar_pendientes.php >> /var/log/sii.log 2>&1
```

```php
// bin/procesar_pendientes.php
$resultados = $client->procesarPendientes();
// Actualiza automáticamente la BD con el estado final de cada DTE pendiente
```

---

### PASO 9 — Pasar a producción

1. Completar el **Proceso de Certificación** en el portal SII
2. Enviar los sets de prueba requeridos (usando ambiente `certificacion`)
3. Esperar aprobación del SII
4. Cambiar en la configuración:
   ```php
   'ambiente'          => 'produccion',
   'numero_resolucion' => 80,           // tu número real
   'fecha_resolucion'  => '2024-01-15', // tu fecha real
   ```
5. Solicitar CAF de **producción** (los de certificación no son válidos)

---

## Estructura del proyecto

```
sii-dte-php/
├── src/
│   ├── SiiClient.php                  ← Punto de entrada principal
│   ├── Auth/TokenManager.php          ← Autenticación semilla/token SII
│   ├── Document/
│   │   ├── DteBuilder.php             ← XML para todos los tipos de DTE
│   │   └── DteTypes.php               ← Constantes y metadatos
│   ├── Envelope/EnvelopeBuilder.php   ← Sobre EnvioDTE
│   ├── Exceptions/
│   │   ├── SiiException.php
│   │   └── FolioAgotadoException.php
│   ├── Folio/FolioManager.php         ← CAF y folios con bloqueo de concurrencia
│   ├── Pdf/PdfGenerator.php           ← HTML → PDF
│   ├── Signature/XmlSigner.php        ← Firma XMLDSig
│   ├── Storage/
│   │   ├── CertificateStore.php       ← Carga segura del certificado
│   │   └── DteRepository.php          ← Persistencia en BD
│   └── Utils/
│       ├── RutHelper.php              ← Validación y formato RUT
│       └── SiiWebService.php          ← HTTP al SII
├── database/migrations.sql            ← Tablas necesarias
├── docs/estructura_datos.md           ← Campos por tipo de DTE
├── examples/
│   ├── integracion_completa.php       ← Todos los tipos integrados
│   ├── enviar_factura.php
│   └── nota_credito.php
├── tests/
├── composer.json
└── LICENSE (MIT)
```

---

## Seguridad del certificado

```php
// ✅ CORRECTO
$cert = CertificateStore::fromFile('/var/secure/certs/empresa.p12', getenv('SII_CERT_PASS'));
$cert = CertificateStore::fromEnv('SII_CERT_B64', 'SII_CERT_PASS');

// ❌ INCORRECTO (la librería lanza excepción si detecta rutas públicas)
$cert = CertificateStore::fromFile('/public/cert.p12', 'password_hardcodeado');
```

---

## Referencias SII

- [Portal Facturación Electrónica](https://www.sii.cl/factura_electronica/)
- [Documentación técnica DTE](https://www.sii.cl/factura_electronica/factura_mercado/formatos_dte.htm)
- [Ambiente de certificación](https://maullin.sii.cl/DTEWS/)
- [Portal emisor / CAF](https://misiir.sii.cl)

---

## Licencia

MIT © 2024. Ver [LICENSE](LICENSE).
