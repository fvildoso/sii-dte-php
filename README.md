# SII DTE PHP

Librería PHP moderna y tipada para la emisión e integración de Documentos Tributarios Electrónicos (DTE) con el Servicio de Impuestos Internos (SII) de Chile.

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/fvildoso/sii-dte-php)](https://packagist.org/packages/fvildoso/sii-dte-php)

---

## Características

- **Autenticación SII**: Obtención de semilla, firma y token de sesión con renovación y caché automática.
- **Generación y Firma XML**: Construcción conforme al estándar XMLDSig y esquema EnvioDTE del SII.
- **Cálculo de Totales**: Desglose automático de montos netos, IVA (19%), montos exentos y totales.
- **Gestión de CAF y Folios**: Asignación atómica y control de concurrencia con `FolioManager` (SELECT FOR UPDATE).
- **Seguridad de Certificados**: Carga desde archivo `.p12`/`.pfx` o base64 (`CertificateStore`), con validación de fechas de vigencia y bloqueo de rutas públicas.
- **Persistencia y Trazabilidad**: Repositorio PDO opcional (`DteRepository`) para registrar XML emitidos, Track IDs y estados.
- **Plantillas HTML para PDF**: Generación de plantillas listas para renderizar mediante librerías como Dompdf.

---

## Tipos de DTE soportados

| Código | Nombre | IVA | Consideraciones |
| :---: | :--- | :---: | :--- |
| **33** | Factura Electrónica | Afecta | Requiere datos completos del receptor y giro. |
| **34** | Factura No Afecta o Exenta | Exenta | No genera débito fiscal. |
| **39** | Boleta Electrónica | Afecta | Venta a consumidor final. |
| **41** | Boleta No Afecta o Exenta | Exenta | Boleta para operaciones exentas de IVA. |
| **46** | Factura de Compra | Afecta | Emitida por el comprador cuando retiene IVA. |
| **52** | Guía de Despacho | Afecta | Requiere indicador de traslado (`ind_traslado`). |
| **56** | Nota de Débito | Afecta | Requiere nodo de referencia al documento original. |
| **61** | Nota de Crédito | Afecta | Requiere nodo de referencia y código de corrección. |
| **110** | Factura de Exportación | Sin IVA | Operaciones internacionales en moneda extranjera. |
| **111** | Nota de Débito de Exportación | Sin IVA | Ajustes a facturas de exportación. |
| **112** | Nota de Crédito de Exportación | Sin IVA | Correcciones a facturas de exportación. |

---

## Requisitos

- PHP **8.4** o superior.
- Extensiones PHP requeridas:
  - `ext-soap`
  - `ext-openssl`
  - `ext-dom`
  - `ext-libxml`
  - `ext-curl`
  - `ext-pdo` (opcional, si se utiliza `FolioManager` o `DteRepository`)

---

## Instalación

Instala el paquete vía Composer:

```bash
composer require fvildoso/sii-dte-php
```

---

## Guía de uso

### 1. Base de datos (Opcional)

Si utilizas persistencia y gestión automática de folios, ejecuta las migraciones SQL disponibles en `database/migrations.sql` (compatible con MySQL 8+ y PostgreSQL 14+):

```bash
# MySQL / MariaDB
mysql -u usuario -p mi_base_de_datos < database/migrations.sql

# PostgreSQL
psql -U usuario -d mi_base_de_datos -f database/migrations.sql
```

> También se incluyen adaptadores para frameworks en el directorio `database/migrations/` (Laravel, Doctrine/Symfony, CakePHP, Phinx y Phalcon).

---

### 2. Carga del Certificado Digital

El certificado `.p12` o `.pfx` debe almacenarse fuera de directorios accesibles vía web.

```php
use SiiDte\Storage\CertificateStore;

// Desde archivo local
$certStore = CertificateStore::fromFile(
    '/var/secure/certs/empresa.p12',
    getenv('SII_CERT_PASS')
);

// O desde variable de entorno en Base64 (ideal para contenedores y CI/CD)
$certStore = CertificateStore::fromEnv('SII_CERT_B64', 'SII_CERT_PASS');
```

---

### 3. Cargar CAF (Código de Autorización de Folios)

Para que la librería asigne folios correlativos automáticamente:

```php
use SiiDte\Folio\FolioManager;

$pdo = new PDO('mysql:host=localhost;dbname=mi_bd;charset=utf8mb4', 'user', 'pass');
$folioManager = new FolioManager($pdo);

// Importar el XML del CAF descargado desde el portal del SII
$cafXml = file_get_contents('/ruta/segura/caf_tipo33.xml');
$folioManager->importarCaf($cafXml, tipoDte: 33);

// Consultar folios disponibles
$disponibles = $folioManager->foliosDisponibles(33);
```

---

### 4. Inicializar `SiiClient`

```php
use SiiDte\SiiClient;
use SiiDte\Folio\FolioManager;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteRepository;

$certStore    = CertificateStore::fromFile('/var/secure/certs/empresa.p12', getenv('SII_CERT_PASS'));
$pdo          = new PDO('mysql:host=localhost;dbname=mi_bd;charset=utf8mb4', 'user', 'pass');
$folioManager = new FolioManager($pdo);
$dteRepo      = new DteRepository($pdo);

$client = new SiiClient(
    config: [
        'rut_emisor'        => '76123456',
        'dv_emisor'         => '0',
        'razon_social'      => 'Mi Empresa SpA',
        'giro'              => 'Servicios Informáticos',
        'direccion'         => 'Av. Providencia 1234, Of. 501',
        'ciudad'            => 'Santiago',
        'codigo_actividad'  => '620200',
        'numero_resolucion' => 0,              // 0 durante certificación
        'fecha_resolucion'  => '2019-10-18',   // Fecha de resolución
        'ambiente'          => 'certificacion', // 'certificacion' o 'produccion'
    ],
    certStore: $certStore
);

// Vincular componentes opcionales
$client
    ->usarFolioManager($folioManager)
    ->usarRepositorio($dteRepo);
```

---

### 5. Emitir un DTE

```php
use SiiDte\Document\DteTypes;

$resultado = $client->enviarDte(DteTypes::FACTURA_ELECTRONICA, [
    'fecha'    => date('Y-m-d'),
    'receptor' => [
        'rut'          => '77987654',
        'dv'           => '3',
        'razon_social' => 'Cliente SpA',
        'giro'         => 'Comercio General',
        'direccion'    => 'Av. Las Condes 567',
        'ciudad'       => 'Santiago',
    ],
    'detalle' => [
        [
            'codigo'          => 'SERV-01',
            'nombre'          => 'Consultoría Técnica',
            'cantidad'        => 1,
            'precio_unitario' => 150000, // Precio neto
        ],
    ],
]);

echo "DTE emitido exitosamente.\n";
echo "Folio asignado: " . $resultado['folio'] . "\n";
echo "Track ID SII:   " . $resultado['trackId'] . "\n";
```

Para especificaciones de estructuras y campos soportados por tipo de documento, consulta [`estructura_datos.md`](estructura_datos.md).

---

### 6. Consultar Estado en el SII

El SII procesa los envíos de forma asíncrona. Puedes consultar el estado del envío mediante el Track ID:

```php
$estado = $client->consultarEstado($resultado['trackId']);

// Códigos de estado habituales:
// EPR: Envejecimiento / Aceptado
// RSC: Rechazado
// SOK: En proceso
echo "Estado: " . $estado['estado'] . " - " . $estado['glosa'] . "\n";
```

Para procesar en segundo plano todos los DTE que estén pendientes en la base de datos (por ejemplo, mediante una tarea Cron):

```php
// Tarea cron programada periódicamente:
$resultados = $client->procesarPendientes();
```

---

### 7. Renderizar a PDF

La librería provee un generador de plantilla HTML estructurada:

```php
$html = $client->generarHtmlPdf($resultado['xml'], [
    'logo_url'       => 'https://miempresa.cl/assets/logo.png',
    'color_primario' => '#1e3a8a',
]);

// Renderizado con Dompdf (requiere dompdf/dompdf):
$dompdf = new \Dompdf\Dompdf();
$dompdf->loadHtml($html);
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

file_put_contents("dte_{$resultado['folio']}.pdf", $dompdf->output());
```

---

## Estructura del repositorio

```text
sii-dte-php/
├── src/
│   ├── SiiClient.php               # Fachada y orquestador principal
│   ├── Auth/TokenManager.php       # Autenticación y tokens del SII
│   ├── Document/
│   │   ├── DteBuilder.php          # Generador de XML DTE
│   │   └── DteTypes.php            # Tipos de DTE y constantes
│   ├── Envelope/EnvelopeBuilder.php# Generador de sobre EnvioDTE
│   ├── Exceptions/                 # Excepciones de dominio
│   ├── Folio/FolioManager.php      # Gestión de CAF y folios
│   ├── Pdf/PdfGenerator.php        # Generador de HTML para representación impresa
│   ├── Signature/XmlSigner.php     # Firma digital XMLDSig
│   ├── Storage/
│   │   ├── CertificateStore.php    # Manejo de certificados (.p12/.pfx)
│   │   └── DteRepository.php       # Persistencia en BD
│   └── Utils/
│       ├── RutHelper.php           # Utilidades de validación y formateo de RUT
│       └── SiiWebService.php       # Cliente HTTP SOAP/REST hacia el SII
├── database/                       # Migraciones SQL y esquemas por framework
├── examples/                       # Scripts de ejemplo de integración
├── tests/                          # Suite de pruebas unitarias y de integración
├── estructura_datos.md             # Diccionario de datos y payloads por DTE
├── composer.json
└── LICENSE
```

---

## Entornos del SII

| Parámetro | Ambiente | URL Web Service |
| :--- | :--- | :--- |
| `'certificacion'` | Maullin (Pruebas) | `https://maullin.sii.cl/` |
| `'produccion'` | Producción | `https://palena.sii.cl/` |

---

## Enlaces de Referencia

- [Portal Facturación Electrónica SII](https://www.sii.cl/factura_electronica/)
- [Formatos y Esquemas Técnicos DTE](https://www.sii.cl/factura_electronica/factura_mercado/formatos_dte.htm)
- [Ambiente de Certificación SII (Maullín)](https://maullin.sii.cl/DTEWS/)

---

## Licencia

Este proyecto está licenciado bajo la licencia MIT. Consulta el archivo [LICENSE](LICENSE) para más detalles.
