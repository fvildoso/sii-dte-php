# Estructuras de datos heredadas (no son contrato de emisión)

> **Obsoleto y no normativo.** Los ejemplos de este archivo describen una API anterior y no deben usarse para emitir DTE. `SiiClient::enviarDte()` está bloqueado; `FolioManager` ya no importa CAF ni reserva folios; los ejemplos no están validados contra los XSD actuales. La matriz de estado y el único ejemplo de vista previa se mantienen en [`README.md`](../README.md).

Los supuestos antiguos sobre precios con IVA incluido, tasa fija del 19%, campos comunes y referencias pueden ser incorrectos para el tipo de operación. Los montos deben calcularse según el Formato DTE y reglas tributarias aplicables; la biblioteca todavía no implementa esa conformidad.

---

## Campos comunes a todos los DTE

```php
$datos = [
    // ─── REQUERIDOS ───────────────────────────────────────────
    'fecha'    => 'YYYY-MM-DD',      // Fecha de emisión
    'receptor' => [ ... ],           // Ver sección Receptor
    'detalle'  => [ ... ],           // Ver sección Detalle

    // ─── OPCIONALES ───────────────────────────────────────────
    'folio'    => 100,               // Solo para borrador; debe suministrarlo la aplicación
    'referencias' => [ ... ],        // Ver sección Referencias (obligatorio en NC y ND)
];
```

---

## Receptor

```php
'receptor' => [
    // SIEMPRE REQUERIDOS
    'rut'          => '98765432',             // Sin DV, sin puntos
    'dv'           => '1',                    // Dígito verificador
    'razon_social' => 'Empresa Compradora',

    // RECOMENDADOS
    'giro'         => 'Comercio al por Mayor',
    'direccion'    => 'Calle Ejemplo 123',
    'ciudad'       => 'Santiago',
    'email'        => 'ventas@empresa.cl',    // Para acuse de recibo electrónico

    // SOLO EXPORTACIÓN (tipo 110, 111, 112)
    'pais'         => 'US',                   // Código ISO 3166-1 alpha-2
]
```

**RUT para casos especiales:**
| Caso | RUT a usar |
|------|-----------|
| Boleta a consumidor final | `66666666-6` |
| Receptor extranjero (exportación) | `55555555-5` o un RUT genérico |
| Ventas sin receptor identificado | `66666666-6` |

---

## Detalle (líneas del documento)

```php
'detalle' => [
    [
        // REQUERIDOS
        'nombre'          => 'Nombre del producto/servicio',   // máx 80 chars
        'precio_unitario' => 119000,   // valor de ejemplo; no implica regla tributaria

        // OPCIONALES
        'codigo'          => 'SKU-001',    // Código interno de producto
        'tipo_codigo'     => 'INT1',       // INT1=interno, EAN13, DUN14, etc.
        'descripcion'     => 'Detalle adicional del ítem',
        'cantidad'        => 2,            // Default: 1
        'unidad'          => 'UN',         // UN, KG, LT, MT, HR, DZ, etc.
        'descuento_pct'   => 10,           // Descuento en porcentaje (0-100)
        'exento'          => false,        // true = ítem exento de IVA
    ],
    // más ítems...
]
```

**No usar este cálculo heredado para determinar montos tributarios.** El código usa `float`, tasa fija y un supuesto de precio incluido en IVA; no cubre todos los regímenes, monedas, impuestos ni redondeos. No se ofrece una conversión fiable de precio neto a bruto.

---

## Referencias (obligatorio en Nota de Crédito y Débito)

```php
'referencias' => [
    [
        // REQUERIDOS
        'tipo_doc'   => 33,           // Tipo del documento referenciado
        'folio'      => 150,          // Folio del documento referenciado
        'fecha'      => '2024-01-15', // Fecha del documento referenciado

        // OPCIONALES
        'nro_linea'  => 1,            // Número de línea de referencia (default: 1)
        'codigo_ref' => 1,            // 1=Anula doc, 2=Corrige texto, 3=Corrige montos
        'razon'      => 'Motivo de la corrección',
    ],
]
```

---

## Tipo 33 — Factura Electrónica

```php
$client->enviarDte(DteTypes::FACTURA_ELECTRONICA, [
    'fecha'              => '2024-01-15',
    'forma_pago'         => 1,             // 1=Contado, 2=Crédito, 3=Sin costo
    'fecha_vencimiento'  => '2024-02-15',  // Solo si forma_pago = 2 (crédito)
    'receptor'           => [ ... ],
    'detalle'            => [ ... ],
    'referencias'        => [ ... ],       // Opcional
]);
```

---

## Tipo 34 — Factura No Afecta Electrónica

Igual que tipo 33, pero todos los ítems quedan exentos de IVA automáticamente.
No es necesario marcar `'exento' => true` en cada ítem.

---

## Tipo 39 — Boleta Electrónica

```php
$client->enviarDte(DteTypes::BOLETA_ELECTRONICA, [
    'fecha'    => '2024-01-15',
    // NO tiene forma_pago ni fecha_vencimiento
    'receptor' => [
        'rut'          => '66666666',  // Consumidor final
        'dv'           => '6',
        'razon_social' => 'Consumidor Final',
        // giro, dirección y email son opcionales en boletas
    ],
    'detalle' => [ ... ],
]);
```

---

## Tipo 46 — Factura de Compra Electrónica

La emite el **comprador** cuando el vendedor no puede emitir factura. El comprador retiene el IVA.

```php
$client->enviarDte(DteTypes::FACTURA_COMPRA_ELECTRONICA, [
    'fecha'    => '2024-01-15',
    'receptor' => [
        // El "receptor" aquí es el VENDEDOR que no pudo emitir factura
        'rut'          => '11111111',
        'dv'           => '1',
        'razon_social' => 'Proveedor sin sistema DTE',
        'giro'         => 'Comercio',
    ],
    'detalle' => [ ... ],
]);
// ⚠️ El IVA se registra como IVARetTotal (el comprador lo declara)
```

---

## Tipo 52 — Guía de Despacho Electrónica

```php
$client->enviarDte(DteTypes::GUIA_DESPACHO_ELECTRONICA, [
    'fecha'        => '2024-01-15',
    'ind_traslado' => 1,             // ← REQUERIDO. Ver tabla abajo.
    'receptor'     => [ ... ],
    'detalle'      => [ ... ],

    // RECOMENDADO: datos del vehículo y destino
    'transporte' => [
        'patente'            => 'ABCD12',
        'rut_transportista'  => '11111111-1',
        'direccion_destino'  => 'Bodega Norte, Calle 5',
        'ciudad_destino'     => 'Santiago',
    ],
]);
```

**Valores de `ind_traslado`:**
| Valor | Significado |
|-------|-------------|
| 1 | Operación constituye venta |
| 2 | Ventas por efectuar |
| 3 | Consignaciones |
| 4 | Entrega gratuita |
| 5 | Traslados internos |
| 6 | Otros traslados no venta |
| 7 | Guía de devolución |
| 8 | Traslado para exportación (no venta) |
| 9 | Venta para exportación |

---

## Tipo 56 — Nota de Débito Electrónica

Aumenta el monto de una factura original (ej: intereses, ajuste de precio).

```php
$client->enviarDte(DteTypes::NOTA_DEBITO_ELECTRONICA, [
    'fecha'    => '2024-01-15',
    'receptor' => [ ... ],
    'detalle'  => [
        ['nombre' => 'Intereses por pago tardío', 'precio_unitario' => 11900],
    ],
    // REQUERIDO: referencia a la factura original
    'referencias' => [
        [
            'tipo_doc'   => 33,
            'folio'      => 150,
            'fecha'      => '2024-01-01',
            'codigo_ref' => 3,           // 3=Corrige montos
            'razon'      => 'Intereses por mora según contrato',
        ],
    ],
]);
```

---

## Tipo 61 — Nota de Crédito Electrónica

Disminuye el monto o anula una factura.

```php
$client->enviarDte(DteTypes::NOTA_CREDITO_ELECTRONICA, [
    'fecha'    => '2024-01-15',
    'receptor' => [ ... ],
    'detalle'  => [
        ['nombre' => 'Devolución mercadería', 'precio_unitario' => 59500],
    ],
    // REQUERIDO
    'referencias' => [
        [
            'tipo_doc'   => 33,
            'folio'      => 150,
            'fecha'      => '2024-01-01',
            'codigo_ref' => 1,           // 1=Anula documento completo
            'razon'      => 'Mercadería defectuosa devuelta',
        ],
    ],
]);
```

---

## Tipos 110, 111, 112 — Exportación

```php
$client->enviarDte(DteTypes::FACTURA_EXPORTACION_ELECTRONICA, [
    'fecha'        => '2024-01-15',
    'moneda'       => 'USD',         // USD, EUR, GBP, etc.
    'ind_servicio' => 4,             // 1=Bienes, 4=Servicios (afectos o no afectos)
    'receptor' => [
        'rut'          => '55555555',
        'dv'           => '5',
        'razon_social' => 'Foreign Company Ltd',
        'pais'         => 'US',      // ← REQUERIDO: código ISO del país
        'direccion'    => '123 Main St, New York, NY 10001',
    ],
    'detalle' => [
        ['nombre' => 'Consulting Services', 'cantidad' => 40, 'unidad' => 'HR', 'precio_unitario' => 150],
    ],
    // Nota de Crédito/Débito de exportación requiere referencias
]);
```

---

## Manejo de ítems mixtos (afectos y exentos en el mismo documento)

Solo posible en facturas tipo 33 y 34:

```php
'detalle' => [
    [
        'nombre'          => 'Software (afecto IVA)',
        'precio_unitario' => 119000,
        // 'exento' no se indica → afecto IVA por default
    ],
    [
        'nombre'          => 'Capacitación (exenta)',
        'precio_unitario' => 50000,
        'exento'          => true,   // ← este ítem queda exento
    ],
],
// Resultado: el documento tendrá MntNeto + IVA + MntExe
```

---

## Estado actual

Estos ejemplos y estructuras son material histórico. No se verificaron contra el Formato DTE versión 2.5 (2026-02), no pasan validación XSD y no acreditan tipos de DTE conformes. Para un borrador actual se requiere un folio explícito; no se produce un DTE listo para enviar.
