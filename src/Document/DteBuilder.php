<?php

declare(strict_types=1);

namespace SiiDte\Document;

use SiiDte\Exceptions\SiiException;
use SiiDte\Utils\RutHelper;

/**
 * Genera un borrador XML para previsualización, no un DTE listo para emitir.
 *
 * No incorpora TED, validación XSD ni una firma XML verificada.
 */
/**
 * @deprecated Usa \SiiDte\Document\DteDocumentBuilder, que cubre todos los tipos de DTE.
 */
class DteBuilder
{
    private array $emisor;
    public const IVA_TASA = 19;

    public function __construct(array $config)
    {
        $this->emisor = $config;
    }

    /**
     * Genera un borrador XML para revisión local.
     *
     * @param int $tipoDte Código del tipo de DTE (ej: 33 para Factura Electrónica)
     * @param array $datos Datos del documento (folio, fecha, receptor, detalle, etc.)
     * @throws SiiException Si faltan datos o no pueden representarse sin pérdida
     * @return string Borrador ISO-8859-1, no válido para envío al SII
     */
    public function build(int $tipoDte, array $datos): string
    {
        $this->validateDatos($tipoDte, $datos);

        $folio   = (int) $datos['folio'];
        $fecha   = $datos['fecha'];
        $receptor = $datos['receptor'];
        $detalle  = $datos['detalle'];
        $refs     = $datos['referencias'] ?? [];
        $totales  = $this->calcularTotales($tipoDte, $detalle);
        $docId    = "DTE-{$tipoDte}F{$folio}";

        $xml  = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n";
        $xml .= '<DTE version="1.0" xmlns="http://www.sii.cl/SiiDte" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">' . "\n";
        $xml .= "  <Documento ID=\"{$docId}\">\n";
        $xml .= $this->buildEncabezado($tipoDte, $folio, $fecha, $receptor, $totales, $datos);
        foreach ($detalle as $idx => $item) {
            $xml .= $this->buildDetalle($idx + 1, $item, $tipoDte);
        }
        foreach ($refs as $ref) {
            $xml .= $this->buildReferencia($ref);
        }
        $xml .= "  </Documento>\n";
        $xml .= "</DTE>\n";
        $latin1 = @iconv('UTF-8', 'ISO-8859-1', $xml);
        if ($latin1 === false) {
            throw new SiiException('El borrador contiene caracteres que no pueden representarse en ISO-8859-1.');
        }

        return $latin1;
    }

    /**
     * Calcula los montos totales (neto, IVA, exento, total) a partir del detalle.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array $detalle Lista de ítems del documento
     * @return array Mapa con los montos calculados
     */
    public function calcularTotales(int $tipoDte, array $detalle): array
    {
        $sumaAfecta = 0;
        $sumaExenta = 0;

        foreach ($detalle as $item) {
            $precio = (float) $item['precio_unitario'];
            $qty    = (float) ($item['cantidad'] ?? 1);
            $desc   = (float) ($item['descuento_pct'] ?? 0);
            $monto  = round($precio * $qty * (1 - $desc / 100));
            $esExento = !empty($item['exento']) || !DteTypes::aplicaIva($tipoDte);
            if ($esExento) {
                $sumaExenta += $monto;
            } else {
                $sumaAfecta += $monto;
            }
        }

        $totales = [];
        if ($sumaAfecta > 0) {
            $neto            = (int) round($sumaAfecta / (1 + self::IVA_TASA / 100));
            $iva             = $sumaAfecta - $neto;
            $totales['neto'] = $neto;
            $totales['iva']  = $iva;
        }
        if ($sumaExenta > 0) {
            $totales['exento'] = (int) $sumaExenta;
        }
        $totales['total'] = ($totales['neto'] ?? 0) + ($totales['iva'] ?? 0) + ($totales['exento'] ?? 0);

        return $totales;
    }

    /**
     * Construye la sección Encabezado del XML.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param int $folio Folio del documento
     * @param string $fecha Fecha de emisión (AAAA-MM-DD)
     * @param array $receptor Datos del receptor
     * @param array $totales Montos totales calculados
     * @param array $datos Datos adicionales del documento
     * @return string Fragmento XML del encabezado
     */
    private function buildEncabezado(int $tipoDte, int $folio, string $fecha, array $receptor, array $totales, array $datos): string
    {
        $rutEmisor = $this->emisor['rut_emisor'] . '-' . $this->emisor['dv_emisor'];
        $rutRec    = RutHelper::format($receptor['rut'], $receptor['dv']);

        $h  = "    <Encabezado>\n";
        $h .= "      <IdDoc>\n";
        $h .= "        <TipoDTE>{$tipoDte}</TipoDTE>\n";
        $h .= "        <Folio>{$folio}</Folio>\n";
        $h .= "        <FchEmis>{$fecha}</FchEmis>\n";
        if (!DteTypes::esBoleta($tipoDte)) {
            $fmaPago = $datos['forma_pago'] ?? 1;
            $h .= "        <FmaPago>{$fmaPago}</FmaPago>\n";
            if ($fmaPago == 2 && !empty($datos['fecha_vencimiento'])) {
                $h .= "        <FchVenc>{$datos['fecha_vencimiento']}</FchVenc>\n";
            }
        }
        if (DteTypes::esTraslado($tipoDte)) {
            $h .= '        <IndTraslado>' . ($datos['ind_traslado'] ?? 1) . "</IndTraslado>\n";
        }
        if (DteTypes::esExportacion($tipoDte)) {
            $h .= '        <IndServicio>' . ($datos['ind_servicio'] ?? 1) . "</IndServicio>\n";
        }
        $h .= "      </IdDoc>\n";

        // Emisor
        $h .= "      <Emisor>\n";
        $h .= "        <RUTEmisor>{$rutEmisor}</RUTEmisor>\n";
        $h .= '        <RznSoc>' . $this->esc($this->emisor['razon_social']) . "</RznSoc>\n";
        $h .= '        <GiroEmis>' . $this->esc($this->emisor['giro']) . "</GiroEmis>\n";
        if (!empty($this->emisor['telefono'])) {
            $h .= '        <Telefono>' . $this->esc($this->emisor['telefono']) . "</Telefono>\n";
        }
        if (!empty($this->emisor['email'])) {
            $h .= '        <CorreoEmisor>' . $this->esc($this->emisor['email']) . "</CorreoEmisor>\n";
        }
        $h .= '        <Acteco>' . ($this->emisor['codigo_actividad'] ?? '620900') . "</Acteco>\n";
        $h .= '        <DirOrigen>' . $this->esc($this->emisor['direccion']) . "</DirOrigen>\n";
        $h .= '        <CmnaOrigen>' . $this->esc($this->emisor['ciudad']) . "</CmnaOrigen>\n";
        $h .= "      </Emisor>\n";

        // Receptor
        $h .= "      <Receptor>\n";
        $h .= "        <RUTRecep>{$rutRec}</RUTRecep>\n";
        $h .= '        <RznSocRecep>' . $this->esc($receptor['razon_social']) . "</RznSocRecep>\n";
        if (!empty($receptor['giro'])) {
            $h .= '        <GiroRecep>' . $this->esc($receptor['giro']) . "</GiroRecep>\n";
        }
        if (!empty($receptor['email'])) {
            $h .= '        <CorreoRecep>' . $this->esc($receptor['email']) . "</CorreoRecep>\n";
        }
        if (!empty($receptor['direccion'])) {
            $h .= '        <DirRecep>' . $this->esc($receptor['direccion']) . "</DirRecep>\n";
        }
        if (!empty($receptor['ciudad'])) {
            $h .= '        <CmnaRecep>' . $this->esc($receptor['ciudad']) . "</CmnaRecep>\n";
        }
        if (DteTypes::esExportacion($tipoDte) && !empty($receptor['pais'])) {
            $h .= '        <CdgPais>' . $this->esc($receptor['pais']) . "</CdgPais>\n";
        }
        $h .= "      </Receptor>\n";

        // Transporte (Guía de Despacho)
        if (DteTypes::esTraslado($tipoDte) && !empty($datos['transporte'])) {
            $t = $datos['transporte'];
            $h .= "      <Transporte>\n";
            if (!empty($t['patente'])) {
                $h .= '        <Patente>' . $this->esc($t['patente']) . "</Patente>\n";
            }
            if (!empty($t['rut_transportista'])) {
                $h .= '        <RUTTrans>' . $this->esc($t['rut_transportista']) . "</RUTTrans>\n";
            }
            if (!empty($t['direccion_destino'])) {
                $h .= '        <DirDest>' . $this->esc($t['direccion_destino']) . "</DirDest>\n";
            }
            if (!empty($t['ciudad_destino'])) {
                $h .= '        <CmnaDest>' . $this->esc($t['ciudad_destino']) . "</CmnaDest>\n";
            }
            $h .= "      </Transporte>\n";
        }

        // Totales
        $h .= "      <Totales>\n";
        if (DteTypes::esExportacion($tipoDte)) {
            $h .= '        <TpoMoneda>' . $this->esc((string) ($datos['moneda'] ?? 'USD')) . "</TpoMoneda>\n";
            $h .= "        <MntTotal>{$totales['total']}</MntTotal>\n";
        } else {
            if (isset($totales['neto'])) {
                $h .= "        <MntNeto>{$totales['neto']}</MntNeto>\n";
            }
            if (isset($totales['exento'])) {
                $h .= "        <MntExe>{$totales['exento']}</MntExe>\n";
            }
            if (isset($totales['iva'])) {
                $h .= '        <TasaIVA>' . self::IVA_TASA . "</TasaIVA>\n";
                $h .= "        <IVA>{$totales['iva']}</IVA>\n";
            }
            if ($tipoDte === DteTypes::FACTURA_COMPRA_ELECTRONICA && isset($totales['iva'])) {
                $h .= "        <IVARetTotal>{$totales['iva']}</IVARetTotal>\n";
            }
            $h .= "        <MntTotal>{$totales['total']}</MntTotal>\n";
        }
        $h .= "      </Totales>\n";
        $h .= "    </Encabezado>\n";

        return $h;
    }

    /**
     * Construye una línea de detalle en el XML.
     *
     * @param int $nroLinea Número de línea correlativo
     * @param array $item Datos del producto o servicio
     * @param int $tipoDte Código del tipo de DTE
     * @return string Fragmento XML del ítem
     */
    private function buildDetalle(int $nroLinea, array $item, int $tipoDte): string
    {
        $precio = (float) $item['precio_unitario'];
        $qty    = (float) ($item['cantidad'] ?? 1);
        $desc   = (float) ($item['descuento_pct'] ?? 0);
        $monto  = round($precio * $qty * (1 - $desc / 100));
        $esExento = !empty($item['exento']) || !DteTypes::aplicaIva($tipoDte);

        $d  = "    <Detalle>\n";
        $d .= "      <NroLinDet>{$nroLinea}</NroLinDet>\n";
        if (!empty($item['codigo'])) {
            $tipoCodigo = $item['tipo_codigo'] ?? 'INT1';
            $d .= '      <CdgItem><TpoCodigo>' . $this->esc((string) $tipoCodigo) . '</TpoCodigo>'
                . '<VlrCodigo>' . $this->esc($item['codigo']) . "</VlrCodigo></CdgItem>\n";
        }
        $d .= '      <NmbItem>' . $this->esc($item['nombre']) . "</NmbItem>\n";
        if (!empty($item['descripcion'])) {
            $d .= '      <DscItem>' . $this->esc($item['descripcion']) . "</DscItem>\n";
        }
        $d .= "      <QtyItem>{$qty}</QtyItem>\n";
        if (!empty($item['unidad'])) {
            $d .= '      <UnmdItem>' . $this->esc($item['unidad']) . "</UnmdItem>\n";
        }
        $d .= '      <PrcItem>' . round($precio) . "</PrcItem>\n";
        if ($desc > 0) {
            $d .= "      <DescuentoPct>{$desc}</DescuentoPct>\n";
            $d .= '      <DescuentoMonto>' . round($precio * $qty * $desc / 100) . "</DescuentoMonto>\n";
        }
        if ($esExento) {
            $d .= "      <IndExe>1</IndExe>\n";
        }
        $d .= '      <MontoItem>' . round($monto) . "</MontoItem>\n";
        $d .= "    </Detalle>\n";

        return $d;
    }

    /**
     * Construye la sección Referencia del XML.
     *
     * @param array $ref Datos del documento referenciado
     * @return string Fragmento XML de la referencia
     */
    private function buildReferencia(array $ref): string
    {
        $r  = "    <Referencia>\n";
        $r .= '      <NroLinRef>' . ($ref['nro_linea'] ?? 1) . "</NroLinRef>\n";
        $r .= '      <TpoDocRef>' . $ref['tipo_doc'] . "</TpoDocRef>\n";
        $r .= '      <FolioRef>' . $ref['folio'] . "</FolioRef>\n";
        $r .= '      <FchRef>' . $ref['fecha'] . "</FchRef>\n";
        if (!empty($ref['codigo_ref'])) {
            $r .= '      <CodRef>' . $ref['codigo_ref'] . "</CodRef>\n";
        }
        if (!empty($ref['razon'])) {
            $r .= '      <RazonRef>' . $this->esc($ref['razon']) . "</RazonRef>\n";
        }
        $r .= "    </Referencia>\n";

        return $r;
    }

    /**
     * Valida que los datos obligatorios estén presentes y sean correctos.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array $datos Datos a validar
     * @throws SiiException Si falta algún campo requerido
     */
    private function validateDatos(int $tipoDte, array $datos): void
    {
        if (!in_array($tipoDte, DteTypes::todos(), true)) {
            throw new SiiException("El tipo DTE {$tipoDte} no está anunciado por la biblioteca.");
        }

        foreach (['folio', 'fecha', 'receptor', 'detalle'] as $key) {
            if (empty($datos[$key])) {
                throw new SiiException("Campo requerido faltante: '{$key}'.");
            }
        }
        if (filter_var($datos['folio'], FILTER_VALIDATE_INT) === false || (int) $datos['folio'] <= 0) {
            throw new SiiException('El folio del borrador debe ser un entero positivo suministrado externamente.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $datos['fecha']);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $date->format('Y-m-d') !== $datos['fecha']
        ) {
            throw new SiiException('La fecha del DTE debe tener formato AAAA-MM-DD válido.');
        }
        $issuerRut = (string) ($this->emisor['rut_emisor'] ?? '') . '-' . (string) ($this->emisor['dv_emisor'] ?? '');
        if (!RutHelper::validate($issuerRut)) {
            throw new SiiException('El RUT del emisor del borrador no es válido.');
        }
        if (!is_array($datos['receptor'])) {
            throw new SiiException('El receptor del borrador debe ser un arreglo de datos.');
        }
        if (!DteTypes::esExportacion($tipoDte)) {
            $recipientRut = (string) ($datos['receptor']['rut'] ?? '')
                . '-' . (string) ($datos['receptor']['dv'] ?? '');
            if (!RutHelper::validate($recipientRut)) {
                throw new SiiException('El RUT del receptor del borrador no es válido.');
            }
        }
        if (count($datos['detalle']) === 0) {
            throw new SiiException('El DTE debe tener al menos una línea de detalle.');
        }
        foreach ($datos['detalle'] as $i => $item) {
            if (empty($item['nombre'])) {
                throw new SiiException('Detalle línea ' . ($i + 1) . ": falta 'nombre'.");
            }
            if (!isset($item['precio_unitario'])) {
                throw new SiiException('Detalle línea ' . ($i + 1) . ": falta 'precio_unitario'.");
            }
        }
        if (DteTypes::esTraslado($tipoDte) && empty($datos['ind_traslado'])) {
            throw new SiiException("Guía de Despacho (tipo 52) requiere 'ind_traslado' (valores 1-9). Ver docs/estructura_datos.md");
        }
        if (DteTypes::requiereReferencia($tipoDte) && empty($datos['referencias'])) {
            throw new SiiException(DteTypes::getName($tipoDte) . " (tipo {$tipoDte}) requiere al menos una referencia al documento original.");
        }
        if (DteTypes::esExportacion($tipoDte) && empty($datos['receptor']['pais'])) {
            throw new SiiException("DTE de exportación requiere 'receptor.pais' con código de país ISO (ej: 'US', 'BR').");
        }
    }

    /**
     * Escapa caracteres especiales para el XML.
     *
     * @param string $text Texto a escapar
     * @return string Texto escapado
     */
    private function esc(string $text): string
    {
        if (preg_match('//u', $text) !== 1) {
            throw new SiiException('Los textos del borrador deben estar codificados en UTF-8 válido.');
        }

        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
