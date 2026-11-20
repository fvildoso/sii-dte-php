# sii-dte-php

Librería PHP 8.4+ para emitir Documentos Tributarios Electrónicos del SII de Chile: facturas, notas, guía de despacho, liquidación-factura, facturas de exportación y **boleta electrónica**.

> La librería no se ha probado contra el SII real. Antes de producción, emite en el ambiente de certificación (Maullín/Pangal) con tus propios certificado y CAF.

## Tipos soportados

| Código | Documento | Código | Documento |
|---|---|---|---|
| 33 | Factura electrónica | 52 | Guía de despacho |
| 34 | Factura exenta | 56 | Nota de débito |
| 39 | Boleta electrónica | 61 | Nota de crédito |
| 41 | Boleta exenta | 110 | Factura de exportación |
| 43 | Liquidación-factura | 111 / 112 | Nota de débito / crédito de exportación |
| 46 | Factura de compra | | |

Facturas y demás DTE viajan en `EnvioDTE` por SOAP/`DTEUpload`; las boletas (39/41) en `EnvioBOLETA` por la API REST de boletas.

## Instalación

```bash
composer require fvildoso/sii-dte-php
```

Requiere `ext-openssl`, `ext-dom`, `ext-libxml`, `ext-curl`, `ext-iconv`, `ext-bcmath`.

## Uso

```php
use SiiDte\Folio\PdoFolioProvider;
use SiiDte\SiiClient;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\PdoDteStore;

$pdo = new PDO($dsn, $user, $pass);
$folios = new PdoFolioProvider($pdo);   // $folios->crearEsquema() una vez
$store  = new PdoDteStore($pdo);        // $store->crearEsquema() una vez

$client = (new SiiClient([
    'rut_emisor' => '76123456', 'dv_emisor' => '0',
    'razon_social' => 'Mi Empresa SpA', 'giro' => 'Servicios informáticos',
    'codigo_actividad' => 620200,
    'direccion' => 'Av. Providencia 1234', 'comuna' => 'Providencia', 'ciudad' => 'Santiago',
    'ambiente' => 'certificacion',
    'numero_resolucion' => 0, 'fecha_resolucion' => '2019-10-18',
], CertificateStore::fromFile('/ruta/segura/cert.p12', $clave)))
    ->usarFolios($folios)->usarAlmacen($store);

$folios->importarCaf(file_get_contents('caf33.xml'), '76123456-0', 'certificacion', 33);

$r = $client->emitir([
    'tipo' => 33,
    'fecha' => date('Y-m-d'),
    'receptor' => ['rut' => '8414240-9', 'razon_social' => 'Cliente', 'giro' => 'Comercio',
                   'direccion' => 'Calle 1', 'comuna' => 'Santiago'],
    'detalle' => [['nombre' => 'Servicio', 'cantidad' => 1, 'precio' => 10000]],
]);
echo $r->trackId, ' folio ', $r->folio;
```

`emitir()` reserva el folio, calcula montos, genera y firma el TED con el CAF, firma documento y sobre, valida contra los XSD del SII, guarda el intento en el almacén y envía. Devuelve `EmisionResultado` (`tipo`, `folio`, `trackId`, `estado`, `dteXml`, `sobreXml`, `ted`).

- **Boletas**: usa `'tipo' => 39` (precios con IVA incluido). Se envían por REST; consulta con `consultarEstado($trackId, boleta: true)`.
- **PDF417**: la librería entrega el `ted` (XML) para que tu generador de códigos de barras lo dibuje; `generarHtmlPdf()` produce una representación HTML.
- **Folios**: implementa `FolioProvider` para usar tu propio almacén; `PdoFolioProvider` reserva de forma atómica (un `UPDATE` condicional) y omite CAF vencidos. `InMemoryFolioProvider` sirve para pruebas.
- **Persistencia**: `DteStore` (`PdoDteStore`, `InMemoryDteStore`). Estados: `preparado`, `enviado`, `envio_incierto` (falla de red tras enviar: consulta antes de reintentar), `rechazado`.
- **Sin enviar**: `preparar($doc, $folio, $cafXml)` genera todo sin contactar al SII; `previsualizarXml()` entrega el XML sin firmar y sin reservar folio.

## Formato del documento

Claves: `tipo`, `fecha`, `receptor`, `detalle[]` (`nombre`, `descripcion`, `cantidad`, `unidad`, `precio`, `codigos`, `exento`, `descuento_pct|monto`, `recargo_pct|monto`, `impuesto`), `descuentos_recargos[]`, `referencias[]` (obligatorias en 56/61/111/112), `impuestos`, `forma_pago`, `medio_pago`, `fecha_vencimiento`; `transporte` e `ind_traslado` (52), `comisiones` (43), `exportacion` (110–112), `ind_no_rebaja` (61). Se rechazan claves desconocidas. Los montos usan aritmética decimal exacta (bcmath, redondeo half-up); `calcular($doc)` en `DteDocumentBuilder` muestra los totales sin emitir. Ejemplos en `examples/`.

## Límites conocidos

- URL de consulta de estado de boletas y regla del total de liquidaciones (43, resta comisiones) basadas en la documentación, sin verificar contra el SII.
- No incluye render de PDF417, libros IES/IEC, envío de respuestas de intercambio ni set de pruebas de certificación.

## Desarrollo

```bash
composer check   # php-cs-fixer, phpstan nivel 6, phpunit
```
Los XSD en `schemas/` se verifican por SHA-256; `.gitattributes` evita que cambien los saltos de línea.
