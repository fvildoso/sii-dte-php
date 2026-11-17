# Esquemas oficiales SII

Los XSD oficiales descargados están incluidos en `schemas/sii/dte/` y `schemas/sii/envio_bol/`. La validación falla cerrada si falta un archivo o no coincide exactamente su SHA-256; no se descargan esquemas durante la ejecución.

Los ZIP se publican en la [página oficial de Formato XML del SII](https://www.sii.cl/servicios_online/3532-formato_xml-3811.html):

- DTE: <https://www.sii.cl/factura_electronica/factura_mercado/schema_dte.zip>
- Boletas: <https://www.sii.cl/factura_electronica/factura_mercado/schema_envio_bol.zip>

La página no asigna versión a estos ZIP. Para hacer reproducible el contenido observado, el validador fija los hashes SHA-256 de cada archivo extraído. Las huellas se calcularon sobre los miembros de los ZIP oficiales consultados el 2026-11-15:

| Archivo | SHA-256 |
| :--- | :--- |
| `dte/DTE_v10.xsd` | `85518401b7dddac1bc0b7b99646a1ce353a4afa645344ea5f10af3a2e6676b37` |
| `dte/EnvioDTE_v10.xsd` | `33ea8dd38c895c359dddbcd21feb5acf8a4717f7f67524a6b0dd9a83d76920eb` |
| `dte/SiiTypes_v10.xsd` | `ce9a84bde70aa9d0f9269d99acd3b0ea81d868022517ac31064b6cdfa0c45bdf` |
| `dte/xmldsignature_v10.xsd` | `427e3225cd379ae92bae464b892dbf964665af92d453ac61774cffab38b95edb` |
| `envio_bol/EnvioBOLETA_v11.xsd` | `e0fbbaf70b0ac8ea2c0ac2310edac41bdd3773cef20fccf6d97e5b92ebeedaa9` |
| `envio_bol/xmldsignature_v10.xsd` | `427e3225cd379ae92bae464b892dbf964665af92d453ac61774cffab38b95edb` |

Dependencias identificadas en `schema_dte.zip`: `dte/EnvioDTE_v10.xsd` incluye `DTE_v10.xsd` y `xmldsignature_v10.xsd`; `dte/DTE_v10.xsd` incluye `SiiTypes_v10.xsd` y `xmldsignature_v10.xsd`. `schema_envio_bol.zip` contiene `envio_bol/EnvioBOLETA_v11.xsd` y `xmldsignature_v10.xsd`.

Los archivos corresponden a los ZIP indicados en las fuentes y están dispuestos en sus directorios de perfil para que se resuelvan las dependencias relativas. `SiiSchemaValidator` comprueba el hash de todos los archivos requeridos y valida con acceso de red deshabilitado. La aceptación XSD solo confirma estructura: no acredita que las muestras sean DTE válidos, ni verifica firma, TED, FRMA, reglas tributarias, certificación o aceptación del SII.