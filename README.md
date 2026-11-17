# SII DTE PHP

Prototipo PHP para preparar e integrar Documentos Tributarios Electrónicos (DTE) con el Servicio de Impuestos Internos (SII) de Chile. La emisión real permanece bloqueada.

[![PHP](https://img.shields.io/badge/PHP-8.4%2B-blue.svg)](https://www.php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/fvildoso/sii-dte-php)](https://packagist.org/packages/fvildoso/sii-dte-php)

---

## Estado real y límites

**Esta biblioteca no está habilitada ni demostrada para emitir DTE reales.** `SiiClient::enviarDte()` falla intencionalmente antes de reservar folios, obtener tokens o hacer solicitudes de red. `FRMA` es parte del CAF firmado por el SII: el emisor la conserva y no necesita generar ni verificar esa firma localmente. El bloqueo actual se debe a que los constructores/cálculos tributarios son parciales y el flujo integral no está certificado.

- `SiiClient::previsualizarXml()` produce un borrador no firmado, sin TED y sin validación XSD; no es enviable.
- `SiiSchemaValidator` valida la estructura XML contra los XSD oficiales incluidos bajo `schemas/sii/`, con selección de perfil, límite de tamaño, rechazo de DTD y comprobación de SHA-256 de los esquemas y dependencias. Esto no verifica firmas, timbres, autenticidad del CAF ni reglas tributarias.
- `CafInspector` valida estructura y metadatos CAF, compara la llave privada de timbrado con la pública del CAF y deja `FRMA` explícitamente sin verificar; no busca llaves SII por `IDK`.
- `TedBuilder` agrega `TED`/`TmstFirma`, conserva el fragmento `<CAF>` original y verifica localmente `FRMT` solo para DTE 33. `FRMA` se copia sin verificar y no es una barrera artificial para preparar el timbre.
- `Invoice33Builder` construye un XML parcial de factura 33 con folio externo para un subconjunto de montos netos e IVA exactamente enteros; no incluye TED ni firma y no determina si el tratamiento tributario corresponde.
- Todas las operaciones de `FolioManager` están deshabilitadas: el esquema heredado no aísla CAF por RUT/ambiente ni valida `FRMA`.
- Las constantes de `DteTypes` identifican códigos, no acreditan soporte tributario integral.
- `XmlSigner` genera y firma XMLDSig para DTE, sobres y solicitudes de semilla. Las pruebas contrastan firmas y referencias con `xmlseclibs` 4.0.2 usando una clave pública sintética fijada fuera del XML; `TokenManager` y `SiiWebService` tienen pruebas de transporte/respuestas simuladas y `DOM`, pero no se probaron en vivo con el SII. Esto no establece confianza de certificados reales ni constituye validación en certificación.
- `EnvelopeBuilder` arma localmente la carátula de un DTE 33 firmado, acepta el RUT de quien transmite y conserva la firma anidada; la prueba verifica ambas firmas y el XSD. No admite lotes ni integra el proceso bloqueado de `SiiClient::enviarDte()`.
- `DteRepository` es opcional, pero no constituye una reserva compartida ni persistencia previa al envío; la aplicación consumidora conserva esa responsabilidad.
- El cálculo tributario heredado usa `float`, tasa fija del 19% y trata los precios como IVA incluido; no es una base para determinar montos tributarios.

---

## Matriz de capacidades por tipo

Los tipos enumerados no están implementados de manera conforme para emisión. El tipo 33 suma generación experimental local de `TED`/`FRMT`, pero no reglas tributarias completas ni un flujo de producción. “Prueba local” no equivale a certificación SII.

| Código | Tipo | Implementación actual | Prueba local específica | Certificación SII |
| :---: | :--- | :--- | :---: | :---: |
| 33 | Factura electrónica | Constructor parcial de líneas afectas; `FRMA` se copia sin verificación local; redondeo y reglas tributarias incompletas | Construcción, `TED`/`FRMT`, XSD y XMLDSig sintéticos integrados | Pendiente |
| 34 | Factura exenta/no afecta | Borrador genérico; reglas de exención incompletas | No | Pendiente |
| 39 | Boleta electrónica | Borrador genérico; flujo y API de boletas ausentes | No | Pendiente |
| 41 | Boleta exenta/no afecta | Borrador genérico; flujo y API de boletas ausentes | No | Pendiente |
| 43 | Liquidación-factura | Borrador genérico; requisitos específicos ausentes | No | Pendiente |
| 46 | Factura de compra | Borrador genérico; retenciones/reglas ausentes | No | Pendiente |
| 52 | Guía de despacho | Borrador genérico; traslado y referencias incompletos | No | Pendiente |
| 56 | Nota de débito | Borrador genérico; reglas de referencias/impuestos ausentes | No | Pendiente |
| 61 | Nota de crédito | Borrador genérico; reglas de referencias/impuestos ausentes | No | Pendiente |
| 110 | Factura de exportación | Borrador genérico; campos de exportación ausentes | No | Pendiente |
| 111 | Nota de débito de exportación | Borrador genérico; campos/referencias ausentes | No | Pendiente |
| 112 | Nota de crédito de exportación | Borrador genérico; campos/referencias ausentes | No | Pendiente |

---

## Requisitos

- PHP **8.4** o superior.
- Extensiones PHP requeridas:
  - `ext-soap`
  - `ext-openssl`
  - `ext-dom`
  - `ext-libxml`
  - `ext-curl`
  - `ext-iconv` (conversión estricta del borrador UTF-8 a ISO-8859-1)
  - `ext-pdo` (opcional para el `DteRepository` heredado; el `FolioManager` no asigna folios)

---

## Instalación

Instala el paquete vía Composer:

```bash
composer require fvildoso/sii-dte-php
```

---

## Guía de uso

### 1. Almacenamiento y reserva de folios

La biblioteca no requiere base de datos. No uses las migraciones heredadas ni `FolioManager` para una reserva compartida: su esquema no particiona CAF por RUT y ambiente, y sus operaciones están deshabilitadas. La aplicación debe implementar su propio bloqueo transaccional, unicidad y autorización por `(RUT emisor, ambiente, tipo DTE, folio)`.

---

### 2. Carga del Certificado Digital

El certificado `.p12` o `.pfx` debe almacenarse fuera de directorios accesibles vía web. `CertificateStore` acepta archivos privados o bytes/Base64 en memoria; la carga en memoria no crea archivos temporales y `getPath()` falla explícitamente en ese modo.

```php
use SiiDte\Storage\CertificateStore;

// Desde archivo local
$certStore = CertificateStore::fromFile(
    '/var/secure/certs/empresa.p12',
    getenv('SII_CERT_PASS')
);

// O desde variable de entorno en Base64 (ideal para contenedores y CI/CD)
$certStore = CertificateStore::fromEnv('SII_CERT_B64', 'SII_CERT_PASS');

// O desde bytes obtenidos de un gestor de secretos, sin persistirlos en archivos
$certStore = CertificateStore::fromBytes($pkcs12Bytes, $password);
```

En ambas modalidades se comprueba que PKCS#12 contenga certificado y clave privada correspondientes y que el certificado esté dentro de su período `notBefore`/`notAfter`; `SiiClient` entrega al firmador la misma copia PKCS#12 que se validó. Esto no comprueba la cadena de confianza, revocación ni autorización efectiva del firmante ante el SII. `getPath()` continúa disponible para cargas desde archivo y devuelve su ruta resuelta; para cargas en memoria debe usarse `getPkcs12Data()` únicamente para la integración interna del firmador y tratar sus bytes como secreto.

---

### 3. Inspeccionar CAF

`CafInspector` no persiste el CAF y nunca devuelve la llave privada. `inspect()` recibe el RUT/tipo esperados; `validateFolio()` además comprueba que el folio que la aplicación ya reservó pertenece a ese emisor, tipo y rango. Ninguna de las dos funciones asigna folios ni autentica `FRMA`.

```php
use SiiDte\Document\DteTypes;
use SiiDte\Folio\CafInspector;

$inspeccion = (new CafInspector())->validateFolio(
    $cafXml, // bytes leídos desde almacenamiento privado de la aplicación
    '76123456-0',
    DteTypes::FACTURA_ELECTRONICA,
    1234, // folio elegido por la aplicación
    '2026-04-15'
);

// firma_sii = no_verificada; IDK se expone como metadato y no se resuelve localmente.
// La inspección sola no autentica el CAF ni habilita una emisión integral.
```

La vigencia se calcula únicamente para los CAF de los DTE 33, 43, 46, 56 y 61, que el SII identifica como documentos que otorgan derecho a crédito fiscal. Para ellos, la regla publicada es seis meses desde `FA` para autorizaciones desde el 01-07-2018, o dieciocho meses para las anteriores con tope al 31-12-2018. Para los demás tipos, `fecha_vencimiento_calculada` es `null` y `vigencia` es `indeterminada`; no se extrapola la regla. La FAQ no precisa si el propio día calculado se incluye: en esa fecha la inspección también devuelve `indeterminada`, el día anterior devuelve `vigente` y el día posterior `vencido`. Esto es una evaluación local de la regla publicada, no una confirmación del SII para el CAF concreto. La inspección tampoco demuestra el ambiente del CAF, la autorización efectiva del firmante ni revocación. No se entrega ni solicita CAF mediante una API inventada; su obtención y baja siguen el procedimiento manual del SII.

### Validación estructural XSD

Los esquemas oficiales fijados por sus hashes están incluidos en `schemas/sii/dte/` y `schemas/sii/envio_bol/`. El validador los carga sin descargar archivos y rechaza el XML si no coincide con el perfil seleccionado:

```php
use SiiDte\Document\SiiSchemaValidator;

$validator = new SiiSchemaValidator();
$validator->validate($xmlDte, 'dte');
$validator->validate($xmlSobreFactura, 'envio_dte');
$validator->validate($xmlSobreBoleta, 'envio_boleta');
```

La aprobación solo indica que el XML cumple la estructura XSD fijada localmente. No demuestra que `TED`, `FRMA` o `XMLDSig` sean criptográficamente válidos ni que el documento cumpla reglas tributarias o sea aceptado por el SII; por ello no habilita `enviarDte()`.

### Constructor parcial de factura 33

`Invoice33Builder` acepta únicamente líneas afectas con precio neto, cantidades y precios expresados como cadenas decimales e IVA explícito `'19'`. Rechaza descuentos, exenciones, referencias, otros impuestos, campos desconocidos y montos o IVA que requieran redondeo. La aplicación debe determinar si el tratamiento es aplicable. El folio es externo y no se reserva ni verifica contra el CAF en este paso.

```php
use SiiDte\Document\Invoice33Builder;

$xmlParcial = (new Invoice33Builder([
    'rut_emisor' => '97975000', 'dv_emisor' => '5',
    'razon_social' => 'Empresa de prueba', 'giro' => 'Servicios',
    'codigo_actividad' => '620900', 'direccion' => 'Calle 1', 'ciudad' => 'Santiago',
]))->build(27, [
    'fecha' => '2024-01-15', 'tasa_iva' => '19',
    'receptor' => [
        'rut' => '8414240', 'dv' => '9', 'razon_social' => 'Cliente',
        'giro' => 'Comercio', 'direccion' => 'Calle 2', 'ciudad' => 'Santiago',
    ],
    'detalle' => [['nombre' => 'Producto', 'cantidad' => '2.5', 'precio_unitario' => '400']],
]);
```

`ciudad` representa la comuna. El resultado en ISO-8859-1 aún no incluye TED, fecha de firma ni XMLDSig. Las pruebas lo combinan con un CAF sintético, `TedBuilder`, firma, sobre y XSD, pero no verifican `FRMA` ni lo hacen enviable.

### TED local experimental para factura 33

`TedBuilder` toma un DTE 33 todavía no timbrado, el CAF completo en memoria y el folio que la aplicación ya reservó. Agrega `TED` y `TmstFirma`, copiando el elemento `<CAF>` literal del archivo de autorización (incluidos espacios, entidades y `FRMA`). `verify()` contrasta los campos del `DD` con el DTE y verifica `FRMT` usando la llave pública `RSAPK` incluida en el propio CAF:

```php
use SiiDte\Document\TedBuilder;
use SiiDte\Folio\CafInspector;

$tedBuilder = new TedBuilder(new CafInspector());
$dteConTed = $tedBuilder->addTedToDte(
    $dteSinTed,
    $autorizacionCafXml, // contenido sensible; leer desde un gestor privado
    $folioReservadoPorLaAplicacion,
    '2026-11-16T12:00:00', // TSTED
    '2026-11-16T12:00:01'  // TmstFirma; XMLDSig debe firmarse después
);
$resultado = $tedBuilder->verify($dteConTed);
```

El componente rechaza folios que no coincidan con el DTE o el rango, llaves privadas distintas de `RSAPK`, CAF cuya vigencia local no esté confirmada, entradas XML con DTD y caracteres que no puedan representarse en ISO-8859-1. No copia `RSASK` al DTE. La representación firmada compacta usa RSA-SHA1, algoritmo heredado del protocolo SII; el `<CAF>` se conserva byte por byte y la verificación de `FRMT` incluye exactamente ese fragmento.

**Esto no acredita autenticidad ni procedencia del CAF:** `FRMA` es la firma del SII que el emisor copia como parte del CAF; este flujo no la genera ni la verifica y no requiere buscar una llave pública por `IDK`. `verify()` informa `frma_verificada = false` (significa «no comprobada localmente», no «inválida») y mantiene `emision_habilitada = false` porque no certifica el flujo integral ni la autorización del contribuyente. Las pruebas usan CAF/llaves sintéticos con `FRMA` dummy y la muestra XML pública oficial de `FRMT`; comprueban FRMT, firmas locales y XSD, pero no la autenticidad del CAF ni la aceptación de Maullín. `SiiClient::enviarDte()` permanece bloqueado; el ejemplo de certificación siguiente es el único flujo de transmisión explícita y solo apunta a Maullín.

### Firma XMLDSig parcial

`XmlSigner::signDte()` agrega la firma como nodo hermano posterior a `Documento`; `signEnvelope()` firma el `SetDTE` de `EnvioDTE` o `EnvioBOLETA` y agrega `Signature` después del conjunto, conforme a la ubicación definida por los XSD incluidos. El firmador rechaza DTD, raíces/targets inesperados, firmas duplicadas e identificadores ambiguos; canonicaliza `SignedInfo` después de insertarlo en el árbol DOM final, publica `Modulus`, `Exponent` y certificado en `KeyInfo` y limita la entrada a 5 MiB.

Este flujo usa RSA-SHA1 y SHA-1 porque son los algoritmos heredados admitidos por el XSD del SII y descritos por el instructivo de emisión consultado; no deben reutilizarse como recomendación para otros protocolos. Las pruebas de desarrollo usan certificados efímeros y contrastan DTE, `EnvioDTE`, `EnvioBOLETA`, firmas anidadas y la solicitud de semilla con `xmlseclibs` 4.0.2 mediante `verifyDocument()`: la clave pública se fija desde el certificado de prueba, fuera del XML, y se comprueba que las referencias validen el `ID` esperado. La prueba de `EnvelopeBuilder` detecta cambios de canonicalización al envolver el DTE. `xmlseclibs` es una dependencia de desarrollo, no un verificador de producción. Los TED/CAF de los documentos sintéticos son ficticios; estas pruebas solo confirman estructura, digest y firma local. Para repetir la prueba dirigida en Windows: `.\vendor\bin\phpunit.bat --no-coverage tests\XmlSignerTest.php`. No se verificó la confianza de certificados reales, autenticidad de `FRMA`, conformidad tributaria, certificación SII ni aceptación del documento. `enviarDte()` permanece bloqueado.

### Construcción local de un sobre individual

`EnvelopeBuilder` comprueba el emisor y el receptor de la factura; el destinatario del sobre se indica por separado. El ejemplo del [manual de certificación del SII](https://www.sii.cl/servicios_online/docs/manual_certificacion.pdf) usa `60803000-K` como destinatario del sobre. Pasa también el RUT del titular que transmite/firma: si se omite, se usa el RUT del emisor sin que ello demuestre la identidad del firmante. El método solo arma un sobre individual: después se firma `SetDTE` con `XmlSigner::signEnvelope()`.

```php
use SiiDte\Envelope\EnvelopeBuilder;

$envelope = EnvelopeBuilder::build(
    $dteFirmado,
    $configEmisor,
    $datosReceptor,
    rutEnvia: $rutDelFirmante,
    rutReceptorSobre: '60803000-K'
);
$sobreFirmado = $xmlSigner->signEnvelope($envelope);
```

La construcción usa XML seguro y conserva el nodo firmado en una representación separada, reconvirtiéndolo a la codificación declarada sin pérdida; el CAF literal se restaura antes de devolver el sobre. La prueba local compara la canonicalización anterior y posterior y valida ambas firmas independientemente, además del XSD. Esto no sustituye reglas tributarias completas, pruebas en certificación ni la evaluación de autenticidad/autorización del CAF y del firmante.

### Autenticación y transporte inyectable

`TokenManager` solicita semilla/token por SOAP, coloca el XML firmado como el parámetro string `pszXml` y analiza la respuesta anidada con `DOM`; `SiiWebService` analiza respuestas SOAP/XML con namespaces y mantiene el Track ID separado de `EPR` y de los estados por DTE. `QueryEstUp` consulta el envío y `QueryEstDte` el documento individual; este último permite indicar un RUT consultante distinto del emisor. El multipart de `DTEUpload` separa RUT y DV del firmante y de la empresa; si se omite el firmante, usa el RUT de la empresa. Las solicitudes se basan en los manuales oficiales de [envío](https://www.sii.cl/factura_electronica/factura_mercado/envio.pdf), [estado de envío](https://www.sii.cl/factura_electronica/factura_mercado/estado_envio.pdf) y [estado de DTE](https://www.sii.cl/factura_electronica/factura_mercado/estado_dte.pdf), no en una validación con el servicio real. Ambos componentes reciben el mismo `HttpTransport` opcional y timeout desde `SiiClient`; los transportes simulados permiten probar estos contratos sin credenciales ni red:

```php
$client = new SiiClient($config, $certStore, $httpTransport, timeoutSeconds: 30);
// o, manteniendo certificados/configuración desde el entorno:
$client = SiiClient::fromEnv([], $httpTransport, timeoutSeconds: 30);
```

`TransportException::$outcomeMayBeUnknown` es verdadero cuando falla el transporte durante una operación de envío o la respuesta de recepción no se puede interpretar. No hay reintento automático; el consumidor debe conciliar antes de decidir una recuperación. Las pruebas son locales y simuladas, no demuestran interoperabilidad en certificación. Estos componentes no se invocan desde `enviarDte()`, que permanece bloqueado.

### Preparar y probar una factura 33 en Maullín

`examples/certificacion_factura33.php` es un ejemplo de dos fases, bloqueado al ambiente `certificacion`:

1. `prepare` recibe un DTE 33 completo sin `TED`/`TmstFirma`/XMLDSig, el CAF y el folio ya reservado por la aplicación; construye el TED, firma, arma y firma el sobre, y valida DTE/sobre con los XSD locales. Escribe el sobre en una ruta privada que no exista y muestra su SHA-256. No contacta al SII.
2. Persiste en tu aplicación el intento, el folio, el XML exacto y el hash mostrado; luego fija el hash y la confirmación explícita de Maullín en el entorno y ejecuta `send`. Esa fase valida el hash y XSD, obtiene token y realiza un único upload; si el resultado queda ambiguo, no reintentes: concilia primero.

El proceso necesita las variables de entorno `SII_CERTIFICACION_XML_FIRMADO_PATH`, `SII_DTE33_SIN_TED_PATH`, `SII_CAF_PATH`, `SII_FOLIO_RESERVADO`, `SII_CERTIFICADO_PATH`, `SII_CERTIFICADO_PASS`, `SII_RUT_EMISOR`, `SII_DV_EMISOR`, `SII_RUT_ENVIADOR`, `SII_RUT_RECEPTOR_SOBRE`, `SII_RAZON_SOCIAL`, `SII_GIRO`, `SII_CODIGO_ACTIVIDAD`, `SII_DIRECCION`, `SII_CIUDAD`, `SII_FECHA_RESOLUCION` y `SII_NUMERO_RESOLUCION`. Para enviar, configura además `SII_CERTIFICACION_CONFIRMAR_ENVIO=MAULLIN`, `SII_CERTIFICACION_INTENTO_PERSISTIDO=SI`, `SII_CERTIFICACION_XML_SHA256` con el hash guardado y opcionalmente `SII_TIMEOUT_SECONDS` (1–120). Proporciona los secretos por tu gestor de secretos/entorno, nunca como argumentos de consola. Los archivos de CAF, certificado, DTE y sobre deben permanecer en almacenamiento privado; el ejemplo rechaza rutas con segmentos `public/`, `www/` o `web/`.

```powershell
php examples\certificacion_factura33.php prepare
php examples\certificacion_factura33.php send
```

El DTE de entrada debe ser completo conforme al tipo 33 y a los datos del CAF; `Invoice33Builder` es parcial y no basta como fuente. Usa CAF/certificado/folio habilitados para certificación Maullín; los fixtures de prueba contienen `FRMA` dummy y no sirven para enviar. El ejemplo copia `<CAF>` literalmente y no verifica `FRMA` ni certifica que el CAF corresponda al ambiente; la aceptación de Maullín, la autorización del firmante y los resultados individuales siguen dependiendo de la respuesta real del SII. El script no realiza la persistencia transaccional de tu aplicación ni consulta automáticamente el Track ID: registra el Track ID recibido y consulta por separado el estado del sobre y el DTE. No lo uses en producción; `SiiClient::enviarDte()` permanece bloqueado.

---

### 4. Inicializar `SiiClient` y generar una vista previa

```php
use SiiDte\SiiClient;
use SiiDte\Document\DteTypes;
use SiiDte\Storage\CertificateStore;

$certStore    = CertificateStore::fromFile('/var/secure/certs/empresa.p12', getenv('SII_CERT_PASS'));

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

$borrador = $client->previsualizarXml(DteTypes::FACTURA_ELECTRONICA, [
    // Debe ser un folio elegido por la aplicación para la vista previa.
    // La biblioteca no lo reserva ni afirma disponibilidad ante el SII.
    'folio'    => 1234,
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
]); // Borrador ISO-8859-1: no tiene TED, firma, sobre ni validación de esquema.
```

`enviarDte()` permanece bloqueado y lanza `SiiException`. `generarXml()` cambió de forma incompatible: es un alias de `previsualizarXml()` y ya no retorna un sobre firmado.

La aplicación consumidora debe reservar en una transacción compartida con clave única `(RUT emisor, ambiente, tipo DTE, folio)`, validar el rango CAF y persistir la intención/documento antes de cualquier envío. Debe ser responsable de permisos entre instancias, auditoría y conciliación. No reintente automáticamente una operación de envío cuyo resultado sea ambiguo; concilie primero el estado con el SII. `SiiClient` no integra este flujo; el ejemplo posterior usa directamente las piezas de bajo nivel y solo para Maullín.

---

### 5. Servicios SOAP y consultas

Los métodos de consulta analizan respuestas SOAP/XML localmente, pero no están validados aquí contra el servicio actual del SII. Conservan códigos y glosas originales; un estado `EPR` describe procesamiento del sobre, no aceptación de cada DTE. No uses una respuesta simulada como evidencia de aceptación real.

```php
$estado = $client->consultarEstado($trackId);

// EPR informa procesamiento del sobre, no aceptación de cada DTE.
echo "Estado: " . $estado['estado'] . " - " . $estado['glosa'] . "\n";
```

---

### 6. Representación PDF

Existe una plantilla HTML heredada, pero no se certifica como representación tributaria válida. No uses un borrador de `previsualizarXml()` como documento emitido ni como fuente de un PDF legal.

---

## Estructura del repositorio

```text
sii-dte-php/
├── src/
│   ├── SiiClient.php               # Fachada; emisión bloqueada
│   ├── Auth/TokenManager.php       # Autenticación y tokens del SII
│   ├── Document/
│   │   ├── DteBuilder.php          # Borrador XML no enviable
│   │   ├── DteTypes.php            # Tipos de DTE y constantes
│   │   ├── Invoice33Builder.php    # Factura 33 parcial con montos exactos
│   │   ├── SiiSchemaValidator.php  # Validación estructural contra XSD fijados por hash
│   │   └── TedBuilder.php          # TED/FRMT experimental; conserva CAF literal para factura 33
│   ├── Envelope/EnvelopeBuilder.php # Sobre individual; preserva contexto de firma DTE
│   ├── Exceptions/                 # Excepciones de dominio y resultado HTTP ambiguo
│   ├── Folio/CafInspector.php      # Inspección local, FRMA no verificada
│   ├── Folio/FolioManager.php      # API heredada deshabilitada
│   ├── Pdf/PdfGenerator.php        # Generador de HTML para representación impresa
│   ├── Signature/XmlSigner.php     # Firma digital XMLDSig
│   ├── Storage/
│   │   ├── CertificateStore.php    # Manejo de certificados (.p12/.pfx)
│   │   └── DteRepository.php       # Persistencia en BD
│   └── Utils/
│       ├── RutHelper.php           # Utilidades de validación y formateo de RUT
│       ├── HttpTransport.php       # Contrato para transporte HTTP inyectable
│       ├── CurlHttpTransport.php   # Transporte HTTP TLS por defecto
│       └── SiiWebService.php       # Envío/consultas no probados en vivo
├── database/                       # Migraciones SQL y esquemas por framework
├── examples/                       # Scripts de ejemplo de integración
├── tests/                          # Suite de pruebas unitarias y de integración
├── schemas/                        # XSD oficiales versionados por hash y notas de procedencia
├── estructura_datos.md             # Referencia heredada, no normativa
├── composer.json
└── LICENSE
```

---

## Ambientes y boletas

La configuración de ambiente no prueba la procedencia de un CAF ni habilita al contribuyente para emitir. Los servicios REST de boletas tienen autenticación/token y servicios separados de los WS de factura; no reutilices tokens entre servicios o ambientes. Esta biblioteca no implementa ni valida esos flujos.

La Resolución Exenta SII N° 53 de 09-06-2022 eliminó, desde el 01-08-2022, la obligación general de generar y enviar diariamente el Resumen de Ventas Diarias (ex RCOF). No se implementa ese envío; revisa la normativa vigente para cualquier obligación que sustituya o complemente el proceso.

---

## Fuentes oficiales y versiones consultadas

Fuentes revisadas entre el **2026-11-15** y el **2026-11-16**. Las versiones indicadas son las que se pudieron identificar en los documentos/páginas enlazados; las interfaces de consulta pueden cambiar sin versionar sus archivos.

| Fuente | Versión/fecha identificada | Hallazgo y uso |
| :--- | :--- | :--- |
| [Formato DTE](https://www.sii.cl/factura_electronica/factura_mercado/formato_dte_202602.pdf) | Versión 2.5, 2026-02 | Formato, obligatoriedad por tipo y TED; XSD DTE v10 incluido y fijado por SHA-256. |
| [Instructivo de emisión de factura](https://www.sii.cl/factura_electronica/factura_mercado/instructivo_emision.pdf) | Versión 28.10.21 | CAF/FRMA, ISO-8859-1, TED y XMLDSig; describe SHA1+RSA/DSA para protocolos SII heredados. |
| [Ejemplo XML oficial](https://www.sii.cl/factura_electronica/factura_mercado/ejemplo_xml.zip) | ZIP consultado 2026-11-16; versión no identificada | `F60T33-ejemplo.xml` aporta el `DD`, `CAF` y `FRMT` del fixture mínimo local. `FRMT` verifica con `RSAPK` del CAF y una alteración de un carácter falla; esto no verifica `FRMA`, la autenticidad del CAF ni la aceptación por el SII. |
| [Manual para empresas usuarias del ambiente de certificación](https://www.sii.cl/servicios_online/docs/manual_certificacion.pdf) | Consultado 2026-11-16; versión no confirmada | Describe el proceso de certificación y pruebas previas a producción. No se usa como vector criptográfico automatizado; el vector local `FRMT` proviene del ZIP oficial de ejemplo. |
| [Validaciones en DTE](https://www.sii.cl/servicios_online/docs/instructivo_sobre_validaciones_dte.pdf) | Versión Junio de 2026 | Reitera schema oficial, CAF, TED, firma, codificación y consistencia como validaciones formales. |
| [Formato XML y esquemas](https://www.sii.cl/servicios_online/3532-formato_xml-3811.html) | Página consultada 2026-11-15; versión de los ZIP no visible en el texto | Indica los cuatro XSD del envío DTE y el esquema de boletas. Los artefactos descargados están incluidos en subdirectorios y fijados por SHA-256 según `schemas/README.md`. |
| [ZIP oficiales de esquemas DTE y boletas](https://www.sii.cl/factura_electronica/factura_mercado/schema_dte.zip) y [boletas](https://www.sii.cl/factura_electronica/factura_mercado/schema_envio_bol.zip) | Consultados 2026-11-15; la página no identifica versión de los ZIP | XSD incluidos y verificados localmente por SHA-256. Se ha comprobado su carga y rechazo de XML estructuralmente incompleto; esto no acredita firma, TED, reglas tributarias ni aceptación SII. |
| [Instructivo técnico de boletas](https://www.sii.cl/servicios_online/3532-instructivo_tecnico_be-3811.html) y [PDF de emisión](https://www.sii.cl/factura_electronica/factura_mercado/Instructivo_Emision_Boleta_Elect.pdf) | PDF 28-10-2021; versión de la documentación OAS/API no identificada | Describe servicios REST específicos de autenticación, envío y consulta; no se implementan. |
| [Vigencia de CAF](https://www.sii.cl/preguntas_frecuentes/factura_electronica/001_003_6968.htm) | FAQ 001.003.6968.002, actualizada 20-05-2025 | CAF desde 01-07-2018: seis meses desde `FA`; anteriores: dieciocho meses con tope 31-12-2018. `FA` es fecha de autorización, no vencimiento. La FAQ no define la inclusión del propio día límite. |
| [DTE sujetos a la restricción de vigencia de CAF](https://www.sii.cl/preguntas_frecuentes/factura_electronica/001_003_6969.htm) | FAQ 001.003.6969.003, actualizada 29-05-2025 | La restricción aplica a DTE que otorgan derecho a crédito fiscal; la vigencia no se extrapola a otros tipos. |
| [Informativo sobre Timbraje Electrónico](https://www.sii.cl/factura_electronica/factura_mercado/timbraje_electronico.pdf) | 18-07-2014 | Enumera factura 33, liquidación factura 43, factura de compra 46, nota de crédito 61 y nota de débito 56 como documentos sujetos al control; referencia complementaria de alcance. |
| [Consulta estado de envío](https://www.sii.cl/factura_electronica/factura_mercado/estado_envio.pdf) | Manual 1.10, 08-11-2004 | WS de consulta legado; no demuestra que sus interfaces sigan vigentes. |
| [Consulta estado DTE](https://www.sii.cl/factura_electronica/factura_mercado/estado_dte.pdf) | Manual 1.10, 22-12-2010 | Consulta por documento; resultado por envío no equivale a resultado individual. |
| [Aceptación/reclamo de DTE](https://www.sii.cl/factura_electronica/Webservice_Registro_Reclamo_DTE_V1.1.pdf) | Versión 1.1, 16-03-2017; la URL V1.2 indicada en el issue no se pudo confirmar | No se integra este servicio; verificar publicación vigente con el SII antes de implementar. |
| [Resolución Exenta SII N° 53](https://www.sii.cl/normativa_legislacion/resoluciones/2022/reso53.pdf) | 09-06-2022; vigencia 01-08-2022 | Eliminó el envío general diario del Resumen de Ventas Diarias. |

En la búsqueda no se identificó un procedimiento API oficial público para solicitar o anular CAF; el instructivo describe la gestión autenticada en el portal SII. Los XSD incluidos y fijados por hash cubren DTE, envío DTE y envío de boletas; no se incorporaron esquemas oficiales para otros procesos (intercambio, libros u operaciones adicionales). `schemaLocation` por sí solo no valida XML.

---

## Publicación de releases

Al completar correctamente las pruebas, cada `push` a `main` crea o reutiliza un release de GitHub para el commit verificado y solicita a Packagist que actualice el paquete. Los pull requests y las ramas distintas de `main` solo ejecutan CI.

Las versiones siguen el formato `vYYYY.MM.DD-<run_id>+<sha_corto>` (por ejemplo, `v2026.11.07-123456+abcdef0`): la fecha corresponde al inicio UTC de la ejecución de GitHub Actions, el identificador de ejecución distingue las publicaciones del día y el SHA permite rastrear el commit. Composer normaliza el guion posterior a la fecha igual que el separador anterior y elimina los metadatos de compilación (`+<sha>`); por ello, el SHA no interviene en la comparación de versiones.

Para habilitar la actualización automática, configura estos secretos en **Settings → Secrets and variables → Actions** del repositorio:

- `PACKAGIST_USERNAME`: usuario de Packagist.
- `PACKAGIST_API_TOKEN`: token de API de Packagist (el token seguro basta para solicitar la actualización).

El paquete debe estar registrado previamente en Packagist. El workflow no mueve etiquetas ya creadas: publica el release y solicita la actualización de Packagist solo cuando la etiqueta nueva o existente apunta al commit probado. Si se reintenta una ejecución, reutiliza la etiqueta y el release existentes solo si apuntan al mismo commit.

---

## Licencia

Este proyecto está licenciado bajo la licencia MIT. Consulta el archivo [LICENSE](LICENSE) para más detalles.
