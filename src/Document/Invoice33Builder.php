<?php

declare(strict_types=1);

namespace SiiDte\Document;

use SiiDte\Exceptions\SiiException;
use SiiDte\Utils\RutHelper;

/**
 * Construye un documento parcial para revisión local, no un DTE para envío.
 *
 * Solo admite líneas afectas con precio neto, IVA explícito del 19% y cálculos
 * exactos en pesos enteros. No incluye TED, fecha de firma ni firma XML.
 */
/**
 * @deprecated Usa \SiiDte\Document\DteDocumentBuilder, que cubre todos los tipos de DTE.
 */
class Invoice33Builder
{
    private array $emisor;

    /**
     * @param array $emisor rut_emisor, dv_emisor, razon_social, giro,
     *                      codigo_actividad, direccion y ciudad (comuna)
     */
    public function __construct(array $emisor)
    {
        $this->emisor = $emisor;
    }

    /**
     * @param int $folio Folio externo; no se reserva ni verifica contra un CAF
     * @param array $datos fecha, tasa_iva ('19'), receptor y detalle. Cada línea
     *                     requiere nombre, cantidad y precio_unitario neto;
     *                     cantidad y precio son cadenas decimales positivas.
     * @throws SiiException Si la entrada excede el subconjunto o requiere redondeo
     * @return string XML parcial ISO-8859-1, no válido para envío
     */
    public function build(int $folio, array $datos): string
    {
        if ($folio <= 0 || strlen((string) $folio) > 10) {
            throw new SiiException('El folio externo debe ser positivo y tener como máximo 10 dígitos.');
        }
        self::keys($this->emisor, ['rut_emisor', 'dv_emisor', 'razon_social', 'giro', 'codigo_actividad', 'direccion', 'ciudad'], 'emisor');
        self::keys($datos, ['fecha', 'tasa_iva', 'receptor', 'detalle'], 'datos');
        $fecha = $datos['fecha'];
        if (!is_string($fecha) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $fecha)
            || $fecha < '2000-01-01' || $fecha > '2050-12-31'
            || !checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            throw new SiiException('La fecha debe ser válida y estar entre 2000-01-01 y 2050-12-31.');
        }
        if ($datos['tasa_iva'] !== '19') {
            throw new SiiException("La tasa de IVA debe indicarse explícitamente como '19'.");
        }
        if (!is_array($datos['receptor'])) {
            throw new SiiException('El receptor debe ser un arreglo.');
        }
        $receptor = $datos['receptor'];
        self::keys($receptor, ['rut', 'dv', 'razon_social', 'giro', 'direccion', 'ciudad'], 'receptor');
        $rutEmisor = self::rut($this->emisor['rut_emisor'], $this->emisor['dv_emisor'], 'emisor');
        $rutReceptor = self::rut($receptor['rut'], $receptor['dv'], 'receptor');
        $acteco = $this->emisor['codigo_actividad'];
        if (!is_string($acteco) || !preg_match('/^[1-9][0-9]{0,5}$/D', $acteco)) {
            throw new SiiException('El código de actividad debe ser una cadena entera positiva de hasta 6 dígitos.');
        }
        $emisor = [
            self::text($this->emisor['razon_social'], 100, 'razon_social del emisor'),
            self::text($this->emisor['giro'], 80, 'giro del emisor'),
            self::text($this->emisor['direccion'], 70, 'direccion del emisor'),
            self::text($this->emisor['ciudad'], 20, 'ciudad del emisor'),
        ];
        $recep = [
            self::text($receptor['razon_social'], 100, 'razon_social del receptor'),
            self::text($receptor['giro'], 40, 'giro del receptor'),
            self::text($receptor['direccion'], 70, 'direccion del receptor'),
            self::text($receptor['ciudad'], 20, 'ciudad del receptor'),
        ];
        $detalle = $datos['detalle'];
        if (!is_array($detalle) || !array_is_list($detalle) || count($detalle) < 1 || count($detalle) > 60) {
            throw new SiiException('El detalle debe ser una lista de 1 a 60 líneas.');
        }
        $neto = '0';
        $lineas = '';
        foreach ($detalle as $index => $item) {
            if (!is_array($item)) {
                throw new SiiException('Cada línea de detalle debe ser un arreglo.');
            }
            self::keys($item, ['nombre', 'cantidad', 'precio_unitario'], 'detalle');
            $nombre = self::text($item['nombre'], 80, 'nombre del detalle');
            [$cantidad, $cantidadEntera, $escalaCantidad] = self::decimal($item['cantidad'], 'cantidad');
            [$precio, $precioEntero, $escalaPrecio] = self::decimal($item['precio_unitario'], 'precio_unitario');
            $producto = self::multiply($cantidadEntera, $precioEntero);
            $escala = $escalaCantidad + $escalaPrecio;
            $producto = str_pad($producto, $escala + 1, '0', STR_PAD_LEFT);
            if ($escala > 0 && substr($producto, -$escala) !== str_repeat('0', $escala)) {
                throw new SiiException('El monto de la línea requiere redondeo a pesos enteros.');
            }
            $monto = self::normalize($escala > 0 ? substr($producto, 0, -$escala) : $producto);
            self::checkMonto($monto);
            $neto = self::add($neto, $monto);
            self::checkMonto($neto);
            $numero = $index + 1;
            $lineas .= "    <Detalle>\n      <NroLinDet>{$numero}</NroLinDet>\n"
                . "      <NmbItem>{$nombre}</NmbItem>\n      <QtyItem>{$cantidad}</QtyItem>\n"
                . "      <PrcItem>{$precio}</PrcItem>\n      <MontoItem>{$monto}</MontoItem>\n    </Detalle>\n";
        }
        $ivaEscalado = self::multiply($neto, '19');
        $ivaEscalado = str_pad($ivaEscalado, 3, '0', STR_PAD_LEFT);
        if (substr($ivaEscalado, -2) !== '00') {
            throw new SiiException('El IVA requiere redondeo a pesos enteros.');
        }
        $iva = self::normalize(substr($ivaEscalado, 0, -2));
        $total = self::add($neto, $iva);
        self::checkMonto($iva);
        self::checkMonto($total);

        $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n"
            . '<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte">' . "\n"
            . "  <Documento ID=\"DTE-33F{$folio}\">\n    <Encabezado>\n      <IdDoc>\n"
            . "        <TipoDTE>33</TipoDTE>\n        <Folio>{$folio}</Folio>\n        <FchEmis>{$fecha}</FchEmis>\n"
            . "      </IdDoc>\n      <Emisor>\n        <RUTEmisor>{$rutEmisor}</RUTEmisor>\n"
            . "        <RznSoc>{$emisor[0]}</RznSoc>\n        <GiroEmis>{$emisor[1]}</GiroEmis>\n"
            . "        <Acteco>{$acteco}</Acteco>\n        <DirOrigen>{$emisor[2]}</DirOrigen>\n"
            . "        <CmnaOrigen>{$emisor[3]}</CmnaOrigen>\n      </Emisor>\n      <Receptor>\n"
            . "        <RUTRecep>{$rutReceptor}</RUTRecep>\n        <RznSocRecep>{$recep[0]}</RznSocRecep>\n"
            . "        <GiroRecep>{$recep[1]}</GiroRecep>\n        <DirRecep>{$recep[2]}</DirRecep>\n"
            . "        <CmnaRecep>{$recep[3]}</CmnaRecep>\n      </Receptor>\n      <Totales>\n"
            . "        <MntNeto>{$neto}</MntNeto>\n        <TasaIVA>19</TasaIVA>\n        <IVA>{$iva}</IVA>\n"
            . "        <MntTotal>{$total}</MntTotal>\n      </Totales>\n    </Encabezado>\n"
            . $lineas . "  </Documento>\n</DTE>\n";
        $latin1 = @iconv('UTF-8', 'ISO-8859-1', $xml);
        if ($latin1 === false) {
            throw new SiiException('El documento contiene caracteres no representables en ISO-8859-1.');
        }

        return $latin1;
    }

    private static function keys(array $data, array $expected, string $context): void
    {
        if (array_diff(array_keys($data), $expected) || array_diff($expected, array_keys($data))) {
            throw new SiiException("Campos faltantes o no soportados en {$context}.");
        }
    }

    private static function rut(mixed $body, mixed $dv, string $context): string
    {
        if (!is_string($body) || !preg_match('/^[1-9][0-9]{0,7}$/D', $body)
            || !is_string($dv) || !preg_match('/^[0-9Kk]$/D', $dv)
            || !RutHelper::validate($body . '-' . $dv)) {
            throw new SiiException("RUT inválido en {$context}.");
        }

        return $body . '-' . strtoupper($dv);
    }

    private static function text(mixed $value, int $maxLength, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0D\x0E-\x1F]/', $value)
            || preg_match_all('/./us', $value) > $maxLength) {
            throw new SiiException("Texto inválido en {$field}.");
        }

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return array{string, string, int} */
    private static function decimal(mixed $value, string $field): array
    {
        if (!is_string($value) || !preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,6})?$/D', $value)) {
            throw new SiiException("{$field} debe ser una cadena decimal positiva de hasta 12 enteros y 6 decimales.");
        }
        $parts = explode('.', $value, 2);
        $integer = self::normalize($parts[0]);
        $fraction = rtrim($parts[1] ?? '', '0');
        $digits = self::normalize($integer . $fraction);
        if ($digits === '0') {
            throw new SiiException("{$field} debe ser positivo.");
        }

        return [$integer . ($fraction !== '' ? '.' . $fraction : ''), $digits, strlen($fraction)];
    }

    private static function normalize(string $number): string
    {
        return ltrim($number, '0') ?: '0';
    }

    private static function checkMonto(string $number): void
    {
        if (strlen($number) > 18) {
            throw new SiiException('El monto excede los 18 dígitos permitidos.');
        }
    }

    private static function add(string $left, string $right): string
    {
        $i = strlen($left) - 1;
        $j = strlen($right) - 1;
        $carry = 0;
        $result = '';
        while ($i >= 0 || $j >= 0 || $carry > 0) {
            $sum = ($i >= 0 ? (int) $left[$i--] : 0) + ($j >= 0 ? (int) $right[$j--] : 0) + $carry;
            $result = ($sum % 10) . $result;
            $carry = intdiv($sum, 10);
        }

        return self::normalize($result);
    }

    private static function multiply(string $left, string $right): string
    {
        $digits = array_fill(0, strlen($left) + strlen($right), 0);
        for ($i = strlen($left) - 1; $i >= 0; --$i) {
            for ($j = strlen($right) - 1; $j >= 0; --$j) {
                $position = $i + $j + 1;
                $sum = $digits[$position] + (int) $left[$i] * (int) $right[$j];
                $digits[$position] = $sum % 10;
                $digits[$position - 1] += intdiv($sum, 10);
            }
        }

        return self::normalize(implode('', $digits));
    }
}
