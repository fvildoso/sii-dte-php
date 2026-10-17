# Guía de Contexto e Instrucciones para Agentes de IA (AGENTS.md)

Este documento contiene las reglas de arquitectura, patrones de diseño y restricciones que cualquier Agente de IA (Cursor, Windsurf, Copilot, Junie, etc.) debe seguir al integrar o extender esta librería en proyectos PHP.

---

## 1. Arquitectura del Paquete

- **Fachada principal:** `SiiDte\SiiClient` orquesta todo el flujo (token, XML, firma, sobre, web service y persistencia).
- **Inyección de dependencias opcionales:**
  - `FolioManager`: Asigna folios atómicamente desde la BD usando transacciones y `SELECT FOR UPDATE`.
  - `DteRepository`: Registra los DTE emitidos, XML final, Track ID y estado devuelto por el SII.
- **Tipos de documento:** Siempre referenciar mediante las constantes de `SiiDte\Document\DteTypes` (`DteTypes::FACTURA_ELECTRONICA`, `DteTypes::BOLETA_ELECTRONICA`, etc.).
- **Almacén de Certificados:** `SiiDte\Storage\CertificateStore` previene la lectura de certificados ubicados en rutas web públicas (`public/`, `www/`, `web/`).

---

## 2. Inicialización en Aplicaciones

### Opción A: Directa desde Variables de Entorno (Recomendada)
```php
use SiiDte\SiiClient;

$client = SiiClient::fromEnv();
```

### Opción B: Con Configuración Explícita
```php
use SiiDte\SiiClient;
use SiiDte\Storage\CertificateStore;

$certStore = CertificateStore::fromFile(
    $_ENV['SII_CERT_PATH'] ?? '/secure/cert.p12',
    $_ENV['SII_CERT_PASS'] ?? ''
);

$client = new SiiClient([
    'rut_emisor'        => $_ENV['SII_RUT_EMISOR'],
    'dv_emisor'         => $_ENV['SII_DV_EMISOR'],
    'razon_social'      => $_ENV['SII_RAZON_SOCIAL'],
    'giro'              => $_ENV['SII_GIRO'],
    'direccion'         => $_ENV['SII_DIRECCION'],
    'ciudad'            => $_ENV['SII_CIUDAD'],
    'codigo_actividad'  => $_ENV['SII_CODIGO_ACTIVIDAD'],
    'numero_resolucion' => (int) ($_ENV['SII_NUMERO_RESOLUCION'] ?? 0),
    'fecha_resolucion'  => $_ENV['SII_FECHA_RESOLUCION'] ?? '2019-10-18',
    'ambiente'          => $_ENV['SII_AMBIENTE'] ?? 'certificacion',
], $certStore);
```

---

## 3. Estructuras de Datos Requeridas por Tipo de DTE

### Factura Electrónica (33) / Factura Exenta (34)
```php
[
    'fecha'    => 'YYYY-MM-DD',
    'receptor' => [
        'rut'          => '12345678', // Sin puntos ni guión
        'dv'           => '9',
        'razon_social' => 'Razón Social Receptor',
        'giro'         => 'Giro del negocio',
        'direccion'    => 'Dirección receptor',
        'ciudad'       => 'Ciudad receptor',
    ],
    'detalle' => [
        [
            'codigo'          => 'SKU-001',
            'nombre'          => 'Nombre del producto o servicio',
            'cantidad'        => 1.0,
            'precio_unitario' => 10000, // Precio neto sin formato
            'exento'          => false, // true para ítems exentos de IVA
        ],
    ],
]
```

### Boleta Electrónica (39) / Boleta Exenta (41)
- No requiere giro ni dirección obligatoria del receptor si es consumidor final anónimo.
- Receptor genérico estándar: RUT `66666666-6` o los datos del comprador.

### Nota de Crédito (61) / Nota de Débito (56)
- Requiere array `referencias`:
```php
[
    // ... campos estándar ...
    'referencias' => [
        [
            'tipo_documento'   => 33,           // Tipo de DTE referenciado
            'folio'            => 1234,         // Folio del DTE original
            'fecha_documento'  => '2024-01-15', // Fecha del DTE original
            'codigo_referencia'=> 1,            // 1: Anula, 2: Corrige texto, 3: Corrige montos
            'razon_referencia' => 'Anula Factura por error en monto',
        ],
    ],
]
```

---

## 4. Buenas Prácticas y Manejo de Errores

1. **Excepciones de Dominio:** Todas las excepciones lanzadas por la librería extienden o son instancias de `SiiDte\Exceptions\SiiException`.
2. **Folios:** Usar siempre `FolioManager` para evitar condiciones de carrera en entornos concurrentes. Si un folio se agota, capturar `SiiDte\Exceptions\FolioAgotadoException`.
3. **Persistencia:** Si se usa Laravel, Symfony o Doctrine, usar las migraciones provistas en `database/migrations/` o el script unificado `database/migrations.sql`.
4. **Verificación de Entorno:** Nunca hardcodear credenciales ni rutas de certificados. Utilizar `.env` según los estándares definidos en `.env.example`.
