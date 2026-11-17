# Guía de Contexto e Instrucciones para Agentes de IA (AGENTS.md)

Este documento contiene las reglas de arquitectura, patrones de diseño y restricciones que cualquier Agente de IA (Cursor, Windsurf, Copilot, Junie, etc.) debe seguir al integrar o extender esta librería en proyectos PHP.

---

## 1. Arquitectura del Paquete

- **Fachada principal:** `SiiDte\SiiClient` conserva APIs heredadas, pero `enviarDte()` está bloqueado. `previsualizarXml()` solo genera un borrador no enviable.
- **Inyección de dependencias opcionales:**
  - `FolioManager`: API heredada deshabilitada; no importar CAF, reservar, consultar o anular folios con ella. El esquema no aísla RUT/ambiente ni verifica FRMA.
  - `DteRepository`: Persistencia heredada opcional; no sustituye la reserva compartida ni la persistencia previa al envío a cargo de la aplicación.
- **CAF:** `SiiDte\Folio\CafInspector` inspecciona estructura/metadatos y correspondencia de llaves; no verifica ni genera FRMA y no busca llaves públicas SII por IDK. Conserva el CAF suministrado literalmente al preparar el TED.
- **TED:** `SiiDte\Document\TedBuilder` solo genera/verifica localmente FRMT para factura 33; FRMA se copia desde el CAF sin verificación local. La preparación experimental no equivale a aceptación ni habilita `SiiClient::enviarDte()`.
- **Sobres y transporte:** `SiiDte\Envelope\EnvelopeBuilder` genera un sobre individual y requiere el RUT de `RutEnvia` correcto; `HttpTransport` es inyectable para autenticación/envío/consultas. Las respuestas y firmas locales simuladas no equivalen a pruebas en certificación.
- **Tipos de documento:** Las constantes de `SiiDte\Document\DteTypes` identifican códigos, no soporte integral.
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

## 3. Estructuras de Datos

No se declara un payload tributario integral por tipo. El contrato de `previsualizarXml()` requiere un folio explícito para el borrador, pero sus campos y cálculos no son un formato oficial validado. No uses las estructuras antiguas de `estructura_datos.md` como contrato de emisión; revisa el Formato DTE y los XSD vigentes del SII.

---

## 4. Buenas Prácticas y Manejo de Errores

1. **Excepciones de dominio:** Captura `SiiDte\Exceptions\SiiException` sin registrar datos sensibles.
2. **Folios:** La aplicación reserva transaccionalmente por RUT, ambiente y tipo, aplica una restricción única por folio y pasa el folio explícitamente. No uses `FolioManager` ni sus migraciones para un almacén compartido.
3. **Persistencia y concurrencia:** La aplicación determina visibilidad/autorización entre instancias, persiste la intención antes del envío y concilia resultados ambiguos. No asumas idempotencia ni reenvíes automáticamente.
4. **Estado:** `EPR` describe procesamiento del sobre, no aceptación de cada documento.
5. **Verificación de entorno:** Nunca hardcodees credenciales ni rutas de certificados. Usa `.env` según `.env.example`.
6. **Emisión:** No exijas al emisor generar o verificar FRMA: es parte del CAF firmado por el SII y debe conservarse sin alteración. No retires la barrera de `enviarDte()` hasta completar la implementación tributaria y la validación local integral, y obtener evidencia en certificación; producción requiere además autorización y controles operativos propios.
