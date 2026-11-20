# Guía para agentes

- Fachada: `SiiDte\SiiClient`; el flujo completo está en `SiiDte\Emission\DteEmitter` (reservar folio → DteDocumentBuilder → TedBuilder → XmlSigner → EnvelopeBuilder → validación XSD → DteStore → envío).
- Facturas y demás DTE: SOAP (`SiiWebService`); boletas 39/41: REST (`BoletaApi`, requiere `HttpRequestTransport`).
- Folios solo vía `FolioProvider` (reserva atómica); nunca reutilizar folios. Si el envío termina en `envio_incierto`, consultar estado antes de reintentar.
- Montos con `SiiDte\Utils\Decimal` (bcmath), nunca con floats.
- Los XSD de `schemas/` se verifican por hash; no cambiar sus bytes (`.gitattributes`).
- Tests: `tests/Support/SyntheticSii` genera CAF/certificado sintéticos; `ScriptedTransport` simula al SII.
- Antes de entregar: `composer check`.

## Integrar la librería en un proyecto (con migraciones propias)

No uses `PdoFolioProvider`/`PdoDteStore` ni `crearEsquema()` si el proyecto ya tiene ORM y migraciones: crea las tablas con las migraciones del proyecto e implementa las dos interfaces con su ORM.

1. **`SiiDte\Folio\FolioProvider::reservar($rutEmisor, $ambiente, $tipoDte): FolioReservado`**
   - Devuelve `new FolioReservado($folio, $cafXml)`; lanza `FolioAgotadoException` si no quedan folios vigentes.
   - Debe ser atómico: dos procesos nunca reciben el mismo folio (transacción con `SELECT ... FOR UPDATE` o un `UPDATE` condicional).
   - Tabla sugerida de CAF: `rut_emisor`, `ambiente` (`certificacion|produccion`), `tipo_dte`, `folio_desde`, `folio_hasta`, `proximo_folio`, `fecha_autorizacion`, `caf_xml` (TEXT; guárdalo cifrado, contiene la llave privada RSASK). Clave única: (`rut_emisor`, `ambiente`, `tipo_dte`, `folio_desde`).
   - Valida el CAF al importarlo con `SiiDte\Folio\CafInspector` (RUT, tipo, vigencia).
2. **`SiiDte\Storage\DteStore`** (opcional pero recomendado): `guardarIntento()` se llama antes de enviar y `registrarResultado()` después (`preparado`, `enviado`, `envio_incierto`, `rechazado`, con `trackId` y detalle). Tabla sugerida: `rut_emisor`, `ambiente`, `tipo_dte`, `folio`, `estado`, `track_id`, `detalle`, `dte_xml`, `sobre_xml`, timestamps; única por (`rut_emisor`, `ambiente`, `tipo_dte`, `folio`).
3. Conecta todo en el contenedor del proyecto: `SiiClient::fromEnv()->usarFolios($miProvider)->usarAlmacen($miStore)`.
4. El certificado va fuera del repositorio (`CertificateStore::fromFile()` o `fromEnv()`).
5. Un `envio_incierto` no se reenvía a ciegas: consulta `consultarEstado($trackId)` o reconcilia primero.
