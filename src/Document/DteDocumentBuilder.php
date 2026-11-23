<?php

declare(strict_types=1);

namespace SiiDte\Document;

use SiiDte\Exceptions\SiiException;
use SiiDte\Utils\Decimal;
use SiiDte\Utils\RutHelper;

/**
 * Construye el XML (sin TED ni firma) de cualquier DTE soportado:
 * facturas 33/34, liquidación-factura 43, factura de compra 46, guía de despacho 52,
 * notas 56/61, boletas 39/41 y exportación 110/111/112.
 *
 * Calcula montos con aritmética decimal exacta (redondeo half-up a pesos enteros;
 * las exportaciones usan `decimales`). Los nombres de campos de entrada son en español
 * (ver README); la estructura de salida sigue el orden de los XSD oficiales.
 *
 * @phpstan-type Config array<string, mixed>
 */
final class DteDocumentBuilder
{
    public const TIPOS = [33, 34, 39, 41, 43, 46, 52, 56, 61, 110, 111, 112];

    private const DEFAULT_TASA_IVA = '19';
    private const MAX_LINEAS = 60;
    private const CONSUMIDOR_FINAL = '66666666-6';

    /** @var array<string, mixed> */
    private array $emisor;

    /**
     * @param array<string, mixed> $emisor rut_emisor, dv_emisor, razon_social, giro, codigo_actividad
     *                                     (int|string|list), direccion, comuna (o ciudad), y opcionalmente
     *                                     ciudad_origen, telefono, email, sucursal, codigo_sucursal_sii,
     *                                     tasa_iva (por defecto '19')
     */
    public function __construct(array $emisor)
    {
        $this->emisor = $emisor;
    }

    /**
     * Genera el XML ISO-8859-1 del DTE, sin TED ni XMLDSig.
     *
     * @param array<string, mixed> $doc
     * @throws SiiException Si faltan datos, son inválidos o no pueden representarse
     */
    public function build(array $doc): string
    {
        $model = $this->prepare($doc);

        $documento = [
            'Encabezado' => $this->encabezado($model),
            'Detalle' => array_map(fn(array $l): array => $this->detalle($model, $l), $model['lineas']),
            'DscRcgGlobal' => $this->descuentosGlobales($model),
            'Referencia' => $this->referencias($model),
            'Comisiones' => $this->comisionesDetalle($model),
        ];
        if ($model['variante'] === 'bol') {
            unset($documento['Comisiones']);
        }

        $id = 'T' . $model['tipo'] . 'F' . $model['folio'];
        $nodo = match ($model['variante']) {
            'liq' => 'Liquidacion',
            'exp' => 'Exportaciones',
            default => 'Documento',
        };
        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n"
            . '<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte">' . "\n"
            . '  <' . $nodo . ' ID="' . $id . '">' . "\n"
            . $this->render($documento, 1)
            . '  </' . $nodo . ">\n</DTE>\n";

        $latin1 = @iconv('UTF-8', 'ISO-8859-1', $xml);
        if ($latin1 === false) {
            throw new SiiException('El documento contiene caracteres que no pueden representarse en ISO-8859-1.');
        }

        return $latin1;
    }

    /**
     * Calcula montos y líneas sin generar XML (útil para vistas previas y validaciones).
     *
     * @param array<string, mixed> $doc
     * @return array{
     *     neto: string, iva: string, exento: string, total: string,
     *     tasa_iva: string, impuestos: list<array{tipo: int, tasa: string, monto: string, retencion: bool}>,
     *     lineas: list<array{numero: int, monto: string}>
     * }
     */
    public function calcular(array $doc): array
    {
        $model = $this->prepare($doc);

        return [
            'neto' => $model['totales']['neto'],
            'iva' => $model['totales']['iva'],
            'exento' => $model['totales']['exento'],
            'total' => $model['totales']['total'],
            'tasa_iva' => $model['tasa_iva'],
            'impuestos' => $model['totales']['impuestos'],
            'lineas' => array_map(
                static fn(array $l): array => ['numero' => $l['numero'], 'monto' => $l['monto']],
                $model['lineas']
            ),
        ];
    }

    // ------------------------------------------------------------------ modelo

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function prepare(array $doc): array
    {
        $tipo = $doc['tipo'] ?? null;
        if (!is_int($tipo) || !in_array($tipo, self::TIPOS, true)) {
            throw new SiiException('El tipo de DTE debe ser uno de: ' . implode(', ', self::TIPOS) . '.');
        }
        $variante = match (true) {
            $tipo === 43 => 'liq',
            $tipo >= 110 => 'exp',
            $tipo === 39 || $tipo === 41 => 'bol',
            default => 'doc',
        };

        $folio = $doc['folio'] ?? null;
        if (!is_int($folio) || $folio <= 0 || strlen((string) $folio) > 10) {
            throw new SiiException('El folio debe ser un entero positivo de hasta 10 dígitos.');
        }
        $fecha = $this->date($doc['fecha'] ?? null, 'fecha');

        $tasa = Decimal::parse($this->emisor['tasa_iva'] ?? self::DEFAULT_TASA_IVA, 'tasa_iva', 2);

        $decimales = 0;
        $moneda = null;
        if ($variante === 'exp') {
            $exportacion = $doc['exportacion'] ?? null;
            if (!is_array($exportacion)) {
                throw new SiiException('Los documentos de exportación requieren el arreglo "exportacion" con la moneda.');
            }
            $this->assertKnown($exportacion, ['moneda', 'decimales', 'aduana', 'otra_moneda'], 'exportacion');
            $moneda = $this->text($exportacion['moneda'] ?? null, 20, 'exportacion.moneda');
            $decimales = $exportacion['decimales'] ?? 2;
            if (!is_int($decimales) || $decimales < 0 || $decimales > 4) {
                throw new SiiException('exportacion.decimales debe estar entre 0 y 4.');
            }
        }

        $detalle = $doc['detalle'] ?? null;
        if (!is_array($detalle) || !array_is_list($detalle) || $detalle === [] || count($detalle) > self::MAX_LINEAS) {
            throw new SiiException('El detalle debe ser una lista de 1 a ' . self::MAX_LINEAS . ' líneas.');
        }

        $impuestos = $this->impuestosConfig($doc['impuestos'] ?? []);
        $lineas = [];
        foreach ($detalle as $index => $item) {
            if (!is_array($item)) {
                throw new SiiException('Cada línea de detalle debe ser un arreglo.');
            }
            $lineas[] = $this->linea($index + 1, $item, $tipo, $variante, $decimales, $impuestos);
        }

        $globales = $this->globales($doc['descuentos_recargos'] ?? [], $variante);
        $totales = $this->totales($tipo, $variante, $lineas, $globales, $impuestos, $tasa, $decimales, $doc);

        return [
            'doc' => $doc,
            'tipo' => $tipo,
            'variante' => $variante,
            'folio' => $folio,
            'fecha' => $fecha,
            'tasa_iva' => $tasa,
            'decimales' => $decimales,
            'moneda' => $moneda,
            'lineas' => $lineas,
            'globales' => $totales['globales'],
            'totales' => $totales,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, array{tasa: string, retencion: bool}> $impuestos
     * @return array<string, mixed>
     */
    private function linea(int $numero, array $item, int $tipo, string $variante, int $decimales, array $impuestos): array
    {
        $this->assertKnown($item, [
            'nombre', 'descripcion', 'cantidad', 'unidad', 'precio', 'precio_unitario', 'codigos',
            'exento', 'descuento_pct', 'descuento_porcentaje', 'descuento_monto', 'recargo_pct',
            'recargo_porcentaje', 'recargo_monto', 'impuesto', 'tipo_doc_liq', 'fecha_elaboracion',
            'fecha_vencimiento',
        ], 'detalle');
        $ctx = "detalle[{$numero}]";
        $nombre = $this->text($item['nombre'] ?? null, 80, "{$ctx}.nombre");
        $descripcion = isset($item['descripcion']) ? $this->text($item['descripcion'], 1000, "{$ctx}.descripcion") : null;
        $unidad = isset($item['unidad']) ? $this->text($item['unidad'], 4, "{$ctx}.unidad") : null;

        $qtyRaw = $item['cantidad'] ?? null;
        $qty = $qtyRaw === null ? null : Decimal::parse($qtyRaw, "{$ctx}.cantidad", 6);
        if ($qty !== null && Decimal::cmp($qty, '0') <= 0) {
            throw new SiiException("{$ctx}.cantidad debe ser positiva.");
        }
        $precioRaw = $item['precio'] ?? $item['precio_unitario'] ?? null;
        $precio = $precioRaw === null ? null : Decimal::parse($precioRaw, "{$ctx}.precio", 6);
        if ($precio !== null && Decimal::isNegative($precio)) {
            throw new SiiException("{$ctx}.precio no puede ser negativo.");
        }

        $exento = ($item['exento'] ?? false) === true || in_array($tipo, [34, 41, 110, 111, 112], true);

        $impuesto = null;
        if (isset($item['impuesto'])) {
            if (!is_int($item['impuesto']) || !isset($impuestos[$item['impuesto']])) {
                throw new SiiException("{$ctx}.impuesto debe ser un código declarado en el arreglo \"impuestos\".");
            }
            if ($exento || $variante === 'bol' || $variante === 'exp') {
                throw new SiiException("{$ctx}: este tipo de línea no admite impuestos adicionales.");
            }
            $impuesto = $item['impuesto'];
        }

        $descPct = $this->optionalPct($item['descuento_pct'] ?? $item['descuento_porcentaje'] ?? null, "{$ctx}.descuento_pct");
        $recPct = $this->optionalPct($item['recargo_pct'] ?? $item['recargo_porcentaje'] ?? null, "{$ctx}.recargo_pct");
        $descMonto = isset($item['descuento_monto']) ? Decimal::parse($item['descuento_monto'], "{$ctx}.descuento_monto", $decimales) : null;
        $recMonto = isset($item['recargo_monto']) ? Decimal::parse($item['recargo_monto'], "{$ctx}.recargo_monto", $decimales) : null;
        if (($descPct !== null && $descMonto !== null) || ($recPct !== null && $recMonto !== null)) {
            throw new SiiException("{$ctx}: indique descuento/recargo como porcentaje o como monto, no ambos.");
        }
        if ($variante === 'liq' && ($descPct !== null || $descMonto !== null || $recPct !== null || $recMonto !== null)) {
            throw new SiiException("{$ctx}: las liquidaciones-factura no admiten descuentos ni recargos por línea.");
        }

        if ($precio === null) {
            // Guías sin valorizar y notas que solo corrigen texto (CodRef 2) llevan líneas con MontoItem 0
            // y sin PrcItem: el XSD exige PrcItem > 0, así que no puede enviarse un precio cero.
            if (!in_array($tipo, [52, 56, 61], true)) {
                throw new SiiException("{$ctx}.precio es obligatorio.");
            }
            $bruto = '0';
        } else {
            $bruto = Decimal::round(Decimal::mul($qty ?? '1', $precio), $decimales);
        }
        if ($descPct !== null) {
            $descMonto = Decimal::round(Decimal::div(Decimal::mul($bruto, $descPct), '100'), $decimales);
        }
        if ($recPct !== null) {
            $recMonto = Decimal::round(Decimal::div(Decimal::mul($bruto, $recPct), '100'), $decimales);
        }
        $monto = Decimal::add(Decimal::sub($bruto, $descMonto ?? '0'), $recMonto ?? '0');
        if (Decimal::isNegative($monto)) {
            throw new SiiException("{$ctx}: el monto de la línea resulta negativo.");
        }
        $this->checkAmount($monto, "{$ctx}.monto");

        $codigos = [];
        foreach ((array) ($item['codigos'] ?? []) as $i => $codigo) {
            if (!is_array($codigo)) {
                throw new SiiException("{$ctx}.codigos[{$i}] debe ser un arreglo tipo/valor.");
            }
            $this->assertKnown($codigo, ['tipo', 'valor'], "{$ctx}.codigos");
            $codigos[] = [
                'TpoCodigo' => $this->text($codigo['tipo'] ?? null, 10, "{$ctx}.codigos.tipo"),
                'VlrCodigo' => $this->text($codigo['valor'] ?? null, 35, "{$ctx}.codigos.valor"),
            ];
        }
        $tipoDocLiq = null;
        if ($variante === 'liq') {
            $tipoDocLiq = $this->text($item['tipo_doc_liq'] ?? null, 3, "{$ctx}.tipo_doc_liq");
        }

        return [
            'numero' => $numero,
            'nombre' => $nombre,
            'descripcion' => $descripcion,
            'unidad' => $unidad,
            'cantidad' => $qty,
            'precio' => $precio,
            'exento' => $exento,
            'impuesto' => $impuesto,
            'desc_pct' => $descPct,
            'desc_monto' => $descMonto,
            'rec_pct' => $recPct,
            'rec_monto' => $recMonto,
            'monto' => $monto,
            'codigos' => $codigos,
            'tipo_doc_liq' => $tipoDocLiq,
            'fecha_elaboracion' => isset($item['fecha_elaboracion']) ? $this->date($item['fecha_elaboracion'], "{$ctx}.fecha_elaboracion") : null,
            'fecha_vencimiento' => isset($item['fecha_vencimiento']) ? $this->date($item['fecha_vencimiento'], "{$ctx}.fecha_vencimiento") : null,
        ];
    }

    /**
     * @return array<int, array{tasa: string, retencion: bool}>
     */
    private function impuestosConfig(mixed $config): array
    {
        if (!is_array($config)) {
            throw new SiiException('"impuestos" debe ser un arreglo código => tasa.');
        }
        $result = [];
        foreach ($config as $codigo => $definition) {
            if (!is_int($codigo) || $codigo <= 0) {
                throw new SiiException('Los códigos de impuesto deben ser enteros positivos.');
            }
            $retencion = $codigo === 15;
            if (is_array($definition)) {
                $this->assertKnown($definition, ['tasa', 'retencion'], 'impuestos');
                $retencion = ($definition['retencion'] ?? $retencion) === true;
                $definition = $definition['tasa'] ?? null;
            }
            $tasa = Decimal::parse($definition ?? ($codigo === 15 ? '19' : null), "impuestos[{$codigo}]", 2);
            if (Decimal::cmp($tasa, '0') <= 0 || Decimal::cmp($tasa, '100') > 0) {
                throw new SiiException("La tasa del impuesto {$codigo} debe estar entre 0 y 100.");
            }
            $result[$codigo] = ['tasa' => $tasa, 'retencion' => $retencion];
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function globales(mixed $globales, string $variante): array
    {
        if (!is_array($globales) || ($globales !== [] && !array_is_list($globales))) {
            throw new SiiException('"descuentos_recargos" debe ser una lista.');
        }
        if ($globales !== [] && $variante === 'liq') {
            throw new SiiException('Las liquidaciones-factura no admiten descuentos/recargos globales.');
        }
        if (count($globales) > 20) {
            throw new SiiException('Se admiten como máximo 20 descuentos/recargos globales.');
        }
        $result = [];
        foreach ($globales as $i => $g) {
            if (!is_array($g)) {
                throw new SiiException('Cada descuento/recargo global debe ser un arreglo.');
            }
            $this->assertKnown($g, ['tipo', 'valor_tipo', 'valor', 'glosa', 'exento'], 'descuentos_recargos');
            $tipo = $g['tipo'] ?? null;
            $valorTipo = $g['valor_tipo'] ?? null;
            if (!in_array($tipo, ['D', 'R'], true) || !in_array($valorTipo, ['%', '$'], true)) {
                throw new SiiException('descuentos_recargos: "tipo" debe ser D o R y "valor_tipo" % o $.');
            }
            $valor = Decimal::parse($g['valor'] ?? null, 'descuentos_recargos.valor', 2);
            if (Decimal::cmp($valor, '0') <= 0 || ($valorTipo === '%' && Decimal::cmp($valor, '100') > 0)) {
                throw new SiiException('descuentos_recargos.valor debe ser positivo (y ≤ 100 si es porcentaje).');
            }
            $result[] = [
                'numero' => $i + 1,
                'tipo' => $tipo,
                'valor_tipo' => $valorTipo,
                'valor' => $valor,
                'glosa' => isset($g['glosa']) ? $this->text($g['glosa'], 45, 'descuentos_recargos.glosa') : null,
                'exento' => ($g['exento'] ?? false) === true,
            ];
        }

        return $result;
    }

    /**
     * @param list<array<string, mixed>> $lineas
     * @param list<array<string, mixed>> $globales
     * @param array<int, array{tasa: string, retencion: bool}> $impuestos
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function totales(
        int $tipo,
        string $variante,
        array $lineas,
        array $globales,
        array $impuestos,
        string $tasa,
        int $decimales,
        array $doc,
    ): array {
        $afecto = '0';
        $exento = '0';
        $bases = [];
        foreach ($lineas as $l) {
            if ($l['exento']) {
                $exento = Decimal::add($exento, $l['monto']);
            } else {
                $afecto = Decimal::add($afecto, $l['monto']);
                if ($l['impuesto'] !== null) {
                    $bases[$l['impuesto']] = Decimal::add($bases[$l['impuesto']] ?? '0', $l['monto']);
                }
            }
        }

        $afectoOriginal = $afecto;
        foreach ($globales as $k => $g) {
            $base = $g['exento'] ? $exento : $afecto;
            $monto = $g['valor_tipo'] === '%'
                ? Decimal::round(Decimal::div(Decimal::mul($base, $g['valor']), '100'), $decimales)
                : Decimal::round($g['valor'], $decimales);
            $delta = $g['tipo'] === 'D' ? Decimal::sub('0', $monto) : $monto;
            $nuevo = Decimal::add($base, $delta);
            if (Decimal::isNegative($nuevo)) {
                throw new SiiException('Un descuento global supera el monto sobre el que se aplica.');
            }
            if ($g['exento']) {
                $exento = $nuevo;
            } else {
                $afecto = $nuevo;
            }
            $globales[$k]['monto'] = $monto;
        }

        // Los descuentos/recargos globales sobre lo afecto reducen proporcionalmente la base de cada impuesto adicional.
        if (Decimal::cmp($afectoOriginal, '0') > 0 && Decimal::cmp($afecto, $afectoOriginal) !== 0) {
            foreach ($bases as $codigo => $base) {
                $bases[$codigo] = Decimal::round(Decimal::div(Decimal::mul($base, $afecto), $afectoOriginal));
            }
        }

        $neto = '0';
        $iva = '0';
        $adicionales = [];
        $total = '0';
        if ($variante === 'bol') {
            // Boleta: los montos de línea ya incluyen IVA.
            $total = Decimal::add($afecto, $exento);
            if (Decimal::cmp($afecto, '0') > 0) {
                $neto = Decimal::round(Decimal::div($afecto, Decimal::add('1', Decimal::div($tasa, '100'))));
                $iva = Decimal::sub($afecto, $neto);
            }
        } elseif ($variante === 'exp') {
            $total = $exento;
        } else {
            $neto = $afecto;
            if (Decimal::cmp($neto, '0') > 0) {
                $iva = Decimal::round(Decimal::div(Decimal::mul($neto, $tasa), '100'));
            }
            $total = Decimal::add(Decimal::add($neto, $iva), $exento);
            foreach ($impuestos as $codigo => $def) {
                if (!isset($bases[$codigo])) {
                    continue;
                }
                $monto = Decimal::round(Decimal::div(Decimal::mul($bases[$codigo], $def['tasa']), '100'));
                $adicionales[] = ['tipo' => $codigo, 'tasa' => $def['tasa'], 'monto' => $monto, 'retencion' => $def['retencion']];
                $total = $def['retencion'] ? Decimal::sub($total, $monto) : Decimal::add($total, $monto);
            }
        }

        $comisiones = null;
        if ($variante === 'liq') {
            $comisiones = $this->comisiones($doc['comisiones'] ?? []);
            $suma = Decimal::add(Decimal::add($comisiones['neto'], $comisiones['exento']), $comisiones['iva']);
            $total = Decimal::sub($total, $suma);
        }
        if (Decimal::isNegative($total)) {
            throw new SiiException('El monto total resulta negativo.');
        }
        foreach ([$neto, $iva, $exento, $total] as $amount) {
            $this->checkAmount($amount, 'total');
        }

        return [
            'neto' => $neto,
            'iva' => $iva,
            'exento' => $exento,
            'total' => $total,
            'impuestos' => $adicionales,
            'globales' => $globales,
            'comisiones' => $comisiones,
        ];
    }

    /**
     * @return array{lineas: list<array<string, mixed>>, neto: string, exento: string, iva: string}
     */
    private function comisiones(mixed $comisiones): array
    {
        if (!is_array($comisiones) || !array_is_list($comisiones)) {
            throw new SiiException('"comisiones" debe ser una lista.');
        }
        $lineas = [];
        $neto = '0';
        $exento = '0';
        $iva = '0';
        foreach ($comisiones as $i => $c) {
            if (!is_array($c)) {
                throw new SiiException('Cada comisión debe ser un arreglo.');
            }
            $this->assertKnown($c, ['tipo', 'glosa', 'tasa', 'neto', 'exento', 'iva'], 'comisiones');
            $tipo = $c['tipo'] ?? null;
            if (!in_array($tipo, ['C', 'O'], true)) {
                throw new SiiException('comisiones.tipo debe ser C (comisión) u O (otros cargos).');
            }
            $cNeto = Decimal::parse($c['neto'] ?? '0', 'comisiones.neto', 0);
            $cExe = Decimal::parse($c['exento'] ?? '0', 'comisiones.exento', 0);
            $cIva = isset($c['iva']) ? Decimal::parse($c['iva'], 'comisiones.iva', 0) : null;
            $neto = Decimal::add($neto, $cNeto);
            $exento = Decimal::add($exento, $cExe);
            $iva = Decimal::add($iva, $cIva ?? '0');
            $lineas[] = [
                'numero' => $i + 1,
                'tipo' => $this->text($tipo, 1, 'comisiones.tipo'),
                'glosa' => $this->text($c['glosa'] ?? null, 60, 'comisiones.glosa'),
                'tasa' => isset($c['tasa']) ? Decimal::parse($c['tasa'], 'comisiones.tasa', 2) : null,
                'neto' => $cNeto,
                'exento' => $cExe,
                'iva' => $cIva,
            ];
        }

        return ['lineas' => $lineas, 'neto' => $neto, 'exento' => $exento, 'iva' => $iva];
    }

    // ------------------------------------------------------------ construcción

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function encabezado(array $m): array
    {
        $enc = [
            'IdDoc' => $this->idDoc($m),
            'Emisor' => $this->emisorNode($m),
        ];
        $doc = $m['doc'];
        if ($m['variante'] === 'doc' && isset($doc['rut_mandante'])) {
            $enc['RUTMandante'] = $this->rutValue($doc['rut_mandante'], null, 'rut_mandante');
        }
        $enc['Receptor'] = $this->receptorNode($m);
        if ($m['variante'] === 'doc' && isset($doc['rut_solicita'])) {
            $enc['RUTSolicita'] = $this->rutValue($doc['rut_solicita'], null, 'rut_solicita');
        }
        if ($m['variante'] === 'bol' && isset($doc['rut_proveedor_software'])) {
            $enc['RUTProvSW'] = $this->rutValue($doc['rut_proveedor_software'], null, 'rut_proveedor_software');
        }
        if ($m['variante'] === 'doc' || $m['variante'] === 'exp') {
            $enc['Transporte'] = $this->transporte($m);
        }
        $enc['Totales'] = $this->totalesNode($m);
        if ($m['variante'] === 'doc' || $m['variante'] === 'exp') {
            $enc['OtraMoneda'] = $this->otraMoneda($m);
        }

        return $enc;
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function idDoc(array $m): array
    {
        $doc = $m['doc'];
        $v = $m['variante'];
        $tipo = $m['tipo'];
        $id = ['TipoDTE' => $tipo, 'Folio' => $m['folio'], 'FchEmis' => $m['fecha']];

        if ($tipo === 61 && ($doc['ind_no_rebaja'] ?? false) === true) {
            $id['IndNoRebaja'] = 1;
        }
        if (($v === 'doc' || $v === 'exp') && isset($doc['tipo_despacho'])) {
            $id['TipoDespacho'] = $this->intIn($doc['tipo_despacho'], [1, 2, 3], 'tipo_despacho');
        }
        if ($tipo === 52) {
            $id['IndTraslado'] = $this->intIn($doc['ind_traslado'] ?? null, [1, 2, 3, 4, 5, 6, 7, 8, 9], 'ind_traslado');
        } elseif (isset($doc['ind_traslado'])) {
            throw new SiiException('ind_traslado solo aplica a guías de despacho (52).');
        }
        if ($v === 'bol') {
            $id['IndServicio'] = $this->intIn($doc['ind_servicio'] ?? 3, [1, 2, 3, 4], 'ind_servicio');
            if (($doc['precios_netos'] ?? false) === true) {
                throw new SiiException('Las boletas con montos netos (IndMntNeto) no están soportadas.');
            }
        } elseif (isset($doc['ind_servicio'])) {
            $id['IndServicio'] = $this->intIn($doc['ind_servicio'], [1, 2, 3, 4, 5], 'ind_servicio');
        }
        if (($v === 'doc' || $v === 'liq') && ($doc['monto_bruto'] ?? false) === true) {
            $id['MntBruto'] = 1;
        }
        if ($v === 'doc' || $v === 'liq') {
            if (isset($doc['tipo_transaccion_compra'])) {
                $id['TpoTranCompra'] = $this->intIn($doc['tipo_transaccion_compra'], [1, 2, 3, 4, 5, 6, 7], 'tipo_transaccion_compra');
            }
            if (isset($doc['tipo_transaccion_venta'])) {
                $id['TpoTranVenta'] = $this->intIn($doc['tipo_transaccion_venta'], [1, 2, 3, 4], 'tipo_transaccion_venta');
            }
        }
        if ($v !== 'bol' && isset($doc['forma_pago'])) {
            $id['FmaPago'] = $this->intIn($doc['forma_pago'], [1, 2, 3], 'forma_pago');
        }
        if (($v === 'doc' || $v === 'exp') && isset($doc['forma_pago_exportacion'])) {
            $id['FmaPagExp'] = $this->intIn($doc['forma_pago_exportacion'], [1, 2, 3, 11, 12, 21, 32, 33, 34, 35, 36, 37, 38, 39, 40], 'forma_pago_exportacion');
        }
        if (isset($doc['periodo_desde'])) {
            $id['PeriodoDesde'] = $this->date($doc['periodo_desde'], 'periodo_desde');
        }
        if (isset($doc['periodo_hasta'])) {
            $id['PeriodoHasta'] = $this->date($doc['periodo_hasta'], 'periodo_hasta');
        }
        if ($v === 'bol') {
            if (isset($doc['fecha_vencimiento'])) {
                $id['FchVenc'] = $this->date($doc['fecha_vencimiento'], 'fecha_vencimiento');
            }
            if (isset($doc['medio_pago'])) {
                $id['MedioPago'] = $this->text($doc['medio_pago'], 2, 'medio_pago');
            }

            return $id;
        }
        if (isset($doc['medio_pago'])) {
            $id['MedioPago'] = $this->text($doc['medio_pago'], 2, 'medio_pago');
        }
        if (isset($doc['fecha_vencimiento'])) {
            $id['FchVenc'] = $this->date($doc['fecha_vencimiento'], 'fecha_vencimiento');
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function emisorNode(array $m): array
    {
        $e = $this->emisor;
        $rut = $this->rutValue($e['rut_emisor'] ?? null, $e['dv_emisor'] ?? null, 'emisor');
        $comuna = $e['comuna'] ?? $e['ciudad'] ?? null;
        $node = ['RUTEmisor' => $rut];

        if ($m['variante'] === 'bol') {
            $node['RznSocEmisor'] = $this->text($e['razon_social'] ?? null, 100, 'emisor.razon_social');
            $node['GiroEmisor'] = $this->text($e['giro'] ?? null, 80, 'emisor.giro');
            if (isset($e['codigo_sucursal_sii'])) {
                $node['CdgSIISucur'] = $this->text($e['codigo_sucursal_sii'], 9, 'emisor.codigo_sucursal_sii');
            }
            $node['DirOrigen'] = $this->text($e['direccion'] ?? null, 70, 'emisor.direccion');
            $node['CmnaOrigen'] = $this->text($comuna, 20, 'emisor.comuna');
            if (isset($e['ciudad_origen'])) {
                $node['CiudadOrigen'] = $this->text($e['ciudad_origen'], 20, 'emisor.ciudad_origen');
            }

            return $node;
        }

        $node['RznSoc'] = $this->text($e['razon_social'] ?? null, 100, 'emisor.razon_social');
        $node['GiroEmis'] = $this->text($e['giro'] ?? null, 80, 'emisor.giro');
        if (isset($e['telefono'])) {
            $node['Telefono'] = array_map(
                fn(mixed $t): string => $this->text($t, 20, 'emisor.telefono'),
                is_array($e['telefono']) ? array_values($e['telefono']) : [$e['telefono']]
            );
        }
        if (isset($e['email'])) {
            $node['CorreoEmisor'] = $this->text($e['email'], 80, 'emisor.email');
        }
        $actecos = $e['codigo_actividad'] ?? null;
        $actecos = is_array($actecos) ? array_values($actecos) : [$actecos];
        if (count($actecos) > 4) {
            throw new SiiException('Se admiten como máximo 4 códigos de actividad.');
        }
        $node['Acteco'] = array_map(function (mixed $a): string {
            $a = is_int($a) ? (string) $a : $a;
            if (!is_string($a) || preg_match('/^[1-9][0-9]{0,5}$/D', $a) !== 1) {
                throw new SiiException('El código de actividad debe ser un entero positivo de hasta 6 dígitos.');
            }

            return $a;
        }, $actecos);
        if (isset($e['sucursal'])) {
            $node['Sucursal'] = $this->text($e['sucursal'], 20, 'emisor.sucursal');
        }
        if (isset($e['codigo_sucursal_sii'])) {
            $node['CdgSIISucur'] = $this->text($e['codigo_sucursal_sii'], 9, 'emisor.codigo_sucursal_sii');
        }
        $obligatorioDir = $m['variante'] === 'liq';
        if ($obligatorioDir || isset($e['direccion'])) {
            $node['DirOrigen'] = $this->text($e['direccion'] ?? null, 70, 'emisor.direccion');
        }
        if ($comuna !== null) {
            $node['CmnaOrigen'] = $this->text($comuna, 20, 'emisor.comuna');
        }
        if (isset($e['ciudad_origen'])) {
            $node['CiudadOrigen'] = $this->text($e['ciudad_origen'], 20, 'emisor.ciudad_origen');
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function receptorNode(array $m): array
    {
        $r = $m['doc']['receptor'] ?? null;
        $v = $m['variante'];
        if ($v === 'bol' && $r === null) {
            return ['RUTRecep' => self::CONSUMIDOR_FINAL];
        }
        if (!is_array($r)) {
            throw new SiiException('El receptor es obligatorio.');
        }
        $this->assertKnown($r, [
            'rut', 'dv', 'razon_social', 'giro', 'contacto', 'correo', 'telefono', 'direccion', 'comuna',
            'ciudad', 'codigo_interno', 'direccion_postal', 'comuna_postal', 'ciudad_postal',
            'extranjero_id', 'nacionalidad',
        ], 'receptor');

        $node = ['RUTRecep' => $this->rutValue($r['rut'] ?? null, $r['dv'] ?? null, 'receptor')];
        if (isset($r['codigo_interno'])) {
            $node['CdgIntRecep'] = $this->text($r['codigo_interno'], 20, 'receptor.codigo_interno');
        }
        if ($v === 'bol') {
            if (isset($r['razon_social'])) {
                $node['RznSocRecep'] = $this->text($r['razon_social'], 100, 'receptor.razon_social');
            }
        } else {
            $node['RznSocRecep'] = $this->text($r['razon_social'] ?? null, 100, 'receptor.razon_social');
        }
        if (($v === 'doc' || $v === 'exp') && (isset($r['extranjero_id']) || isset($r['nacionalidad']))) {
            $node['Extranjero'] = [
                'NumId' => isset($r['extranjero_id']) ? $this->text($r['extranjero_id'], 20, 'receptor.extranjero_id') : null,
                'Nacionalidad' => isset($r['nacionalidad']) ? $this->text($r['nacionalidad'], 3, 'receptor.nacionalidad') : null,
            ];
        }
        if ($v === 'liq' || isset($r['giro'])) {
            if ($v !== 'bol') {
                $node['GiroRecep'] = $this->text($r['giro'] ?? null, 40, 'receptor.giro');
            }
        }
        if (isset($r['contacto'])) {
            $node['Contacto'] = $this->text($r['contacto'], 80, 'receptor.contacto');
        }
        if (isset($r['correo'])) {
            $node['CorreoRecep'] = $this->text($r['correo'], 80, 'receptor.correo');
        }
        if ($v === 'bol' && isset($r['telefono'])) {
            $node['TelefonoRecep'] = $this->text($r['telefono'], 20, 'receptor.telefono');
        }
        if ($v === 'liq' || isset($r['direccion'])) {
            $node['DirRecep'] = $this->text($r['direccion'] ?? null, 70, 'receptor.direccion');
        }
        $comuna = $r['comuna'] ?? $r['ciudad'] ?? null;
        if ($v === 'liq' || $comuna !== null) {
            $node['CmnaRecep'] = $this->text($comuna, 20, 'receptor.comuna');
        }
        if (isset($r['ciudad']) && isset($r['comuna'])) {
            $node['CiudadRecep'] = $this->text($r['ciudad'], 20, 'receptor.ciudad');
        }
        if (isset($r['direccion_postal'])) {
            $node['DirPostal'] = $this->text($r['direccion_postal'], 70, 'receptor.direccion_postal');
        }
        if (isset($r['comuna_postal'])) {
            $node['CmnaPostal'] = $this->text($r['comuna_postal'], 20, 'receptor.comuna_postal');
        }
        if (isset($r['ciudad_postal'])) {
            $node['CiudadPostal'] = $this->text($r['ciudad_postal'], 20, 'receptor.ciudad_postal');
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>|null
     */
    private function transporte(array $m): ?array
    {
        $doc = $m['doc'];
        $exp = $m['variante'] === 'exp' ? ($doc['exportacion'] ?? []) : [];
        $t = $doc['transporte'] ?? null;
        $aduana = $exp['aduana'] ?? null;
        if ($t === null && $aduana === null) {
            return null;
        }
        $t ??= [];
        if (!is_array($t)) {
            throw new SiiException('"transporte" debe ser un arreglo.');
        }
        $this->assertKnown($t, [
            'patente', 'rut_transportista', 'chofer', 'direccion_destino', 'comuna_destino',
            'ciudad_destino', 'fecha_salida', 'hora_salida', 'fecha_llegada',
        ], 'transporte');
        $node = [];
        if (isset($t['patente'])) {
            $node['Patente'] = $this->text($t['patente'], 8, 'transporte.patente');
        }
        if (isset($t['rut_transportista'])) {
            $node['RUTTrans'] = $this->rutValue($t['rut_transportista'], null, 'transporte.rut_transportista');
        }
        if (isset($t['chofer'])) {
            $c = $t['chofer'];
            if (!is_array($c)) {
                throw new SiiException('transporte.chofer debe ser un arreglo rut/nombre.');
            }
            $this->assertKnown($c, ['rut', 'nombre'], 'transporte.chofer');
            $node['Chofer'] = [
                'RUTChofer' => $this->rutValue($c['rut'] ?? null, null, 'transporte.chofer.rut'),
                'NombreChofer' => $this->text($c['nombre'] ?? null, 30, 'transporte.chofer.nombre'),
            ];
        }
        if (isset($t['direccion_destino'])) {
            $node['DirDest'] = $this->text($t['direccion_destino'], 70, 'transporte.direccion_destino');
        }
        if (isset($t['comuna_destino'])) {
            $node['CmnaDest'] = $this->text($t['comuna_destino'], 20, 'transporte.comuna_destino');
        }
        if (isset($t['ciudad_destino'])) {
            $node['CiudadDest'] = $this->text($t['ciudad_destino'], 20, 'transporte.ciudad_destino');
        }
        if ($aduana !== null) {
            $node['Aduana'] = $this->aduana($aduana);
        }
        if ($m['variante'] === 'doc') {
            if (isset($t['fecha_salida'])) {
                $node['FchSalida'] = $this->date($t['fecha_salida'], 'transporte.fecha_salida');
            }
            if (isset($t['hora_salida'])) {
                $node['HraSalida'] = $this->text($t['hora_salida'], 8, 'transporte.hora_salida');
            }
            if (isset($t['fecha_llegada'])) {
                $node['FchLlegada'] = $this->date($t['fecha_llegada'], 'transporte.fecha_llegada');
            }
        }

        return $node === [] ? null : $node;
    }

    /**
     * Pasa los campos de Aduana (nombres exactos del formato SII) en el orden del XSD.
     *
     * @return array<string, mixed>
     */
    private function aduana(mixed $aduana): array
    {
        if (!is_array($aduana)) {
            throw new SiiException('exportacion.aduana debe ser un arreglo con los nombres del formato SII.');
        }
        $order = [
            'CodModVenta', 'CodClauVenta', 'TotClauVenta', 'CodViaTransp', 'NombreTransp', 'RUTCiaTransp',
            'NomCiaTransp', 'IdAdicTransp', 'Booking', 'Operador', 'CodPtoEmbarque', 'IdAdicPtoEmb',
            'CodPtoDesemb', 'IdAdicPtoDesemb', 'Tara', 'CodUnidMedTara', 'PesoBruto', 'CodUnidPesoBruto',
            'PesoNeto', 'CodUnidPesoNeto', 'TotItems', 'TotBultos', 'TipoBultos', 'MntFlete', 'MntSeguro',
            'CodPaisRecep', 'CodPaisDestin',
        ];
        $this->assertKnown($aduana, $order, 'aduana');
        $bultoOrder = ['CodTpoBultos', 'CantBultos', 'Marcas', 'IdContainer', 'Sello', 'EmisorSello'];
        $node = [];
        foreach ($order as $name) {
            if (!isset($aduana[$name])) {
                continue;
            }
            if ($name === 'TipoBultos') {
                $bultos = $aduana[$name];
                if (!is_array($bultos) || !array_is_list($bultos)) {
                    throw new SiiException('aduana.TipoBultos debe ser una lista.');
                }
                $node[$name] = array_map(function (mixed $b) use ($bultoOrder): array {
                    if (!is_array($b)) {
                        throw new SiiException('Cada TipoBultos debe ser un arreglo.');
                    }
                    $this->assertKnown($b, $bultoOrder, 'aduana.TipoBultos');
                    $out = [];
                    foreach ($bultoOrder as $field) {
                        if (isset($b[$field])) {
                            $out[$field] = $this->scalar($b[$field], "TipoBultos.{$field}");
                        }
                    }

                    return $out;
                }, $bultos);
                continue;
            }
            $node[$name] = $this->scalar($aduana[$name], "aduana.{$name}");
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>|null
     */
    private function otraMoneda(array $m): ?array
    {
        $om = $m['variante'] === 'exp' ? ($m['doc']['exportacion']['otra_moneda'] ?? null) : ($m['doc']['otra_moneda'] ?? null);
        if ($om === null) {
            return null;
        }
        if (!is_array($om)) {
            throw new SiiException('"otra_moneda" debe ser un arreglo con los nombres del formato SII.');
        }
        $order = $m['variante'] === 'exp'
            ? ['TpoMoneda', 'TpoCambio', 'MntExeOtrMnda', 'MntTotOtrMnda']
            : ['TpoMoneda', 'TpoCambio', 'MntNetoOtrMnda', 'MntExeOtrMnda', 'MntFaeCarneOtrMnda', 'MntMargComOtrMnda', 'IVAOtrMnda', 'IVANoRetOtrMnda', 'MntTotOtrMnda'];
        $this->assertKnown($om, $order, 'otra_moneda');
        $node = [];
        foreach ($order as $name) {
            if (isset($om[$name])) {
                $node[$name] = $this->scalar($om[$name], "otra_moneda.{$name}");
            }
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $m
     * @return array<string, mixed>
     */
    private function totalesNode(array $m): array
    {
        $t = $m['totales'];
        $v = $m['variante'];
        $dec = $m['decimales'];
        $fmt = static fn(string $x): string => $dec > 0 ? Decimal::trim($x) : $x;

        if ($v === 'exp') {
            return ['TpoMoneda' => $m['moneda'], 'MntExe' => $fmt($t['exento']), 'MntTotal' => $fmt($t['total'])];
        }
        $hayAfecto = Decimal::cmp($t['neto'], '0') > 0;
        $node = [];
        if ($hayAfecto) {
            $node['MntNeto'] = $t['neto'];
        }
        if (Decimal::cmp($t['exento'], '0') > 0) {
            $node['MntExe'] = $t['exento'];
        }
        if ($v === 'bol') {
            if ($hayAfecto) {
                $node['IVA'] = $t['iva'];
            }
            $node['MntTotal'] = $t['total'];

            return $node;
        }
        if ($hayAfecto) {
            $node['TasaIVA'] = $m['tasa_iva'];
            $node['IVA'] = $t['iva'];
        }
        $node['ImptoReten'] = array_map(static fn(array $i): array => [
            'TipoImp' => $i['tipo'],
            'TasaImp' => $i['tasa'],
            'MontoImp' => $i['monto'],
        ], $t['impuestos']);
        if ($v === 'liq' && $t['comisiones'] !== null) {
            $node['Comisiones'] = [
                'ValComNeto' => $t['comisiones']['neto'],
                'ValComExe' => $t['comisiones']['exento'],
                'ValComIVA' => $t['comisiones']['iva'],
            ];
        }
        $node['MntTotal'] = $t['total'];

        return $node;
    }

    /**
     * @param array<string, mixed> $m
     * @param array<string, mixed> $l
     * @return array<string, mixed>
     */
    private function detalle(array $m, array $l): array
    {
        $v = $m['variante'];
        $dec = $m['decimales'];
        $money = static fn(?string $x): ?string => $x === null ? null : ($dec > 0 ? Decimal::trim($x) : $x);
        $n = ['NroLinDet' => $l['numero']];
        $n['CdgItem'] = array_map(static fn(array $c): array => $c, $l['codigos']);
        if ($l['tipo_doc_liq'] !== null) {
            $n['TpoDocLiq'] = $l['tipo_doc_liq'];
        }
        if ($l['exento']) {
            $n['IndExe'] = 1;
        }
        $n['NmbItem'] = $l['nombre'];
        if ($l['descripcion'] !== null) {
            $n['DscItem'] = $l['descripcion'];
        }
        if ($l['cantidad'] !== null) {
            $n['QtyItem'] = $l['cantidad'];
        }
        if ($v !== 'bol' && $v !== 'liq') {
            if ($l['fecha_elaboracion'] !== null) {
                $n['FchElabor'] = $l['fecha_elaboracion'];
            }
            if ($l['fecha_vencimiento'] !== null) {
                $n['FchVencim'] = $l['fecha_vencimiento'];
            }
        }
        if ($l['unidad'] !== null) {
            $n['UnmdItem'] = $l['unidad'];
        }
        if ($l['precio'] !== null) {
            $n['PrcItem'] = $l['precio'];
        }
        if ($v !== 'liq') {
            if ($l['desc_pct'] !== null) {
                $n['DescuentoPct'] = $l['desc_pct'];
            }
            if ($l['desc_monto'] !== null) {
                $n['DescuentoMonto'] = $money($l['desc_monto']);
            }
            if ($l['rec_pct'] !== null) {
                $n['RecargoPct'] = $l['rec_pct'];
            }
            if ($l['rec_monto'] !== null) {
                $n['RecargoMonto'] = $money($l['rec_monto']);
            }
        }
        if ($l['impuesto'] !== null && ($v === 'doc' || $v === 'liq')) {
            $n['CodImpAdic'] = $l['impuesto'];
        }
        $n['MontoItem'] = $money($l['monto']);

        return $n;
    }

    /**
     * @param array<string, mixed> $m
     * @return list<array<string, mixed>>
     */
    private function descuentosGlobales(array $m): array
    {
        $dec = $m['decimales'];
        $out = [];
        foreach ($m['globales'] as $g) {
            $out[] = [
                'NroLinDR' => $g['numero'],
                'TpoMov' => $g['tipo'],
                'GlosaDR' => $g['glosa'],
                'TpoValor' => $g['valor_tipo'],
                'ValorDR' => $g['valor_tipo'] === '$' ? Decimal::trim(Decimal::round($g['valor'], $dec)) : $g['valor'],
                'IndExeDR' => $g['exento'] ? 1 : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $m
     * @return list<array<string, mixed>>
     */
    private function referencias(array $m): array
    {
        $refs = $m['doc']['referencias'] ?? [];
        if (!is_array($refs) || ($refs !== [] && !array_is_list($refs)) || count($refs) > 40) {
            throw new SiiException('"referencias" debe ser una lista de hasta 40 elementos.');
        }
        if ($refs === [] && in_array($m['tipo'], [56, 61, 111, 112], true)) {
            throw new SiiException('Las notas de débito/crédito requieren al menos una referencia al documento original.');
        }
        $out = [];
        foreach ($refs as $i => $r) {
            if (!is_array($r)) {
                throw new SiiException('Cada referencia debe ser un arreglo.');
            }
            $this->assertKnown($r, ['tipo_doc', 'folio', 'fecha', 'codigo', 'razon', 'global', 'rut_otro', 'codigo_vendedor', 'codigo_caja'], 'referencias');
            $node = ['NroLinRef' => $i + 1];
            if ($m['variante'] === 'bol') {
                if (isset($r['tipo_doc'])) {
                    $node['TpoDocRef'] = $this->text($r['tipo_doc'], 3, 'referencias.tipo_doc');
                }
                if (isset($r['folio'])) {
                    $node['FolioRef'] = $this->text($r['folio'], 18, 'referencias.folio');
                }
                if (isset($r['codigo'])) {
                    // En boletas CodRef es texto libre (hasta 18): el set de pruebas del SII exige "SET".
                    $node['CodRef'] = is_int($r['codigo'])
                        ? $this->intIn($r['codigo'], [1, 2, 3], 'referencias.codigo')
                        : $this->text($r['codigo'], 18, 'referencias.codigo');
                }
                if (isset($r['razon'])) {
                    $node['RazonRef'] = $this->text($r['razon'], 90, 'referencias.razon');
                }
                if (isset($r['codigo_vendedor'])) {
                    $node['CodVndor'] = $this->text($r['codigo_vendedor'], 10, 'referencias.codigo_vendedor');
                }
                if (isset($r['codigo_caja'])) {
                    $node['CodCaja'] = $this->text($r['codigo_caja'], 10, 'referencias.codigo_caja');
                }
                $out[] = $node;
                continue;
            }
            $node['TpoDocRef'] = $this->text($r['tipo_doc'] ?? null, 3, 'referencias.tipo_doc');
            if (($r['global'] ?? false) === true) {
                $node['IndGlobal'] = 1;
            }
            $node['FolioRef'] = $this->text($r['folio'] ?? null, 18, 'referencias.folio');
            if (isset($r['rut_otro']) && $m['variante'] !== 'liq') {
                $node['RUTOtr'] = $this->rutValue($r['rut_otro'], null, 'referencias.rut_otro');
            }
            $node['FchRef'] = $this->date($r['fecha'] ?? null, 'referencias.fecha');
            if (isset($r['codigo'])) {
                $node['CodRef'] = $this->intIn($r['codigo'], [1, 2, 3], 'referencias.codigo');
            }
            if (isset($r['razon'])) {
                $node['RazonRef'] = $this->text($r['razon'], 90, 'referencias.razon');
            }
            $out[] = $node;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $m
     * @return list<array<string, mixed>>
     */
    private function comisionesDetalle(array $m): array
    {
        $c = $m['totales']['comisiones'] ?? null;
        if ($c === null) {
            return [];
        }

        return array_map(static fn(array $l): array => [
            'NroLinCom' => $l['numero'],
            'TipoMovim' => $l['tipo'],
            'Glosa' => $l['glosa'],
            'TasaComision' => $l['tasa'],
            'ValComNeto' => $l['neto'],
            'ValComExe' => $l['exento'],
            'ValComIVA' => $l['iva'],
        ], $c['lineas']);
    }

    // ---------------------------------------------------------------- utilidades

    /**
     * Serializa un árbol ordenado: lista => elemento repetido, arreglo => hijos, escalar => texto.
     *
     * @param array<string, mixed> $node
     */
    private function render(array $node, int $level): string
    {
        $pad = str_repeat('  ', $level + 1);
        $xml = '';
        foreach ($node as $name => $value) {
            if ($value === null || $value === []) {
                continue;
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $item) {
                    $xml .= $this->renderOne($pad, $name, $item, $level);
                }
                continue;
            }
            $xml .= $this->renderOne($pad, $name, $value, $level);
        }

        return $xml;
    }

    private function renderOne(string $pad, string $name, mixed $value, int $level): string
    {
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            $children = $this->render($value, $level + 1);
            if ($children === '') {
                return '';
            }

            return "{$pad}<{$name}>\n{$children}{$pad}</{$name}>\n";
        }

        return "{$pad}<{$name}>" . htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</{$name}>\n";
    }

    /** @param array<string, mixed> $data @param list<string> $allowed */
    private function assertKnown(array $data, array $allowed, string $context): void
    {
        $unknown = array_diff(array_map('strval', array_keys($data)), $allowed);
        if ($unknown !== []) {
            throw new SiiException("Campos no soportados en {$context}: " . implode(', ', $unknown) . '.');
        }
    }

    private function text(mixed $value, int $max, string $field): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (!is_string($value) || trim($value) === '' || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0D\x0E-\x1F]/', $value) === 1
        ) {
            throw new SiiException("Texto inválido o vacío en {$field}.");
        }
        $value = trim($value);
        if (mb_strlen($value, 'UTF-8') > $max) {
            throw new SiiException("{$field} excede el máximo de {$max} caracteres.");
        }

        return $value;
    }

    private function scalar(mixed $value, string $field): string
    {
        if (is_bool($value) || !is_scalar($value)) {
            throw new SiiException("{$field} debe ser un valor escalar.");
        }

        return $this->text((string) $value, 100, $field);
    }

    private function date(mixed $value, string $field): string
    {
        if (!is_string($value) || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) !== 1
            || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            || $value < '2000-01-01' || $value > '2050-12-31'
        ) {
            throw new SiiException("{$field} debe ser una fecha AAAA-MM-DD válida entre 2000 y 2050.");
        }

        return $value;
    }

    /** @param list<int> $allowed */
    private function intIn(mixed $value, array $allowed, string $field): int
    {
        if (!is_int($value) || !in_array($value, $allowed, true)) {
            throw new SiiException("{$field} debe ser uno de: " . implode(', ', $allowed) . '.');
        }

        return $value;
    }

    private function optionalPct(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $pct = Decimal::parse($value, $field, 2);
        if (Decimal::isNegative($pct) || Decimal::cmp($pct, '100') > 0) {
            throw new SiiException("{$field} debe estar entre 0 y 100.");
        }

        return $pct;
    }

    private function checkAmount(string $amount, string $field): void
    {
        if (strlen(explode('.', $amount)[0]) > 14) {
            throw new SiiException("El monto {$field} excede los dígitos permitidos.");
        }
    }

    /**
     * RUT con DV separado o en formato "12345678-9"; devuelve "12345678-9".
     */
    private function rutValue(mixed $rut, mixed $dv, string $context): string
    {
        if (is_int($rut)) {
            $rut = (string) $rut;
        }
        if (!is_string($rut)) {
            throw new SiiException("RUT inválido en {$context}.");
        }
        $rut = str_replace('.', '', trim($rut));
        if ($dv === null || $dv === '') {
            if (!str_contains($rut, '-')) {
                throw new SiiException("RUT inválido en {$context}: falta el dígito verificador.");
            }
            [$rut, $dv] = explode('-', $rut, 2);
        }
        if (is_int($dv)) {
            $dv = (string) $dv;
        }
        if (!is_string($dv) || preg_match('/^[0-9]{1,8}$/D', $rut) !== 1 || preg_match('/^[0-9Kk]$/D', $dv) !== 1) {
            throw new SiiException("RUT inválido en {$context}.");
        }
        $full = ltrim($rut, '0') . '-' . strtoupper($dv);
        if (!RutHelper::validate($full)) {
            throw new SiiException("RUT inválido en {$context}.");
        }

        return $full;
    }
}
