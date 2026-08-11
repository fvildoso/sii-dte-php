<?php

declare(strict_types=1);

namespace SiiDte\Envelope;

use SiiDte\Utils\RutHelper;

/**
 * Construye el sobre XML EnvioDTE para enviar al SII.
 *
 * El sobre contiene uno o más DTE firmados y los datos del emisor/receptor.
 */
class EnvelopeBuilder
{
    /**
     * Construye el XML del sobre EnvioDTE.
     *
     * @param string $dteFirmadoXml XML del DTE ya firmado
     * @param array  $emisor        Configuración del emisor
     * @param array  $receptor      Datos del receptor
     * @return string XML del sobre generado
     */
    public static function build(string $dteFirmadoXml, array $emisor, array $receptor): string
    {
        $rutEmisor = $emisor['rut_emisor'] . '-' . $emisor['dv_emisor'];
        $rutRec    = RutHelper::format($receptor['rut'], $receptor['dv']);
        $ahora     = date('Y-m-d\TH:i:s');
        $setId     = 'SetDoc';

        // Extraer el nodo DTE del XML firmado (sin declaración XML)
        $dteContent = preg_replace('/<\?xml[^?]+\?>\s*/i', '', $dteFirmadoXml);

        $xml  = '<?xml version="1.0" encoding="ISO-8859-1"?>' . "\n";
        $xml .= '<EnvioDTE version="1.0" xmlns="http://www.sii.cl/SiiDte"' . "\n";
        $xml .= '  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' . "\n";
        $xml .= '  xsi:schemaLocation="http://www.sii.cl/SiiDte EnvioDTE_v10.xsd">' . "\n";

        // SetDTE
        $xml .= '  <SetDTE ID="' . $setId . '">' . "\n";
        $xml .= '    <Caratula version="1.0">' . "\n";
        $xml .= '      <RutEmisor>' . $rutEmisor . '</RutEmisor>' . "\n";
        $xml .= '      <RutEnvia>' . $rutEmisor . '</RutEnvia>' . "\n";
        $xml .= '      <RutReceptor>' . $rutRec . '</RutReceptor>' . "\n";
        $xml .= '      <FchResol>' . ($emisor['fecha_resolucion'] ?? date('Y-m-d')) . '</FchResol>' . "\n";
        $xml .= '      <NroResol>' . ($emisor['numero_resolucion'] ?? 0) . '</NroResol>' . "\n";
        $xml .= '      <TmstFirmaEnv>' . $ahora . '</TmstFirmaEnv>' . "\n";
        $xml .= '      <SubTotDTE>' . "\n";
        $xml .= '        <TpoDTE>' . self::extractTipoDte($dteFirmadoXml) . '</TpoDTE>' . "\n";
        $xml .= '        <NroDTE>1</NroDTE>' . "\n";
        $xml .= '      </SubTotDTE>' . "\n";
        $xml .= '    </Caratula>' . "\n";

        // DTE firmado
        $xml .= $dteContent . "\n";

        $xml .= '  </SetDTE>' . "\n";
        $xml .= '</EnvioDTE>' . "\n";

        return $xml;
    }

    /**
     * Extrae el tipo de DTE desde el XML.
     *
     * @param string $xml XML del DTE
     * @return string Código del tipo de DTE
     */
    private static function extractTipoDte(string $xml): string
    {
        if (preg_match('/<TipoDTE>(\d+)<\/TipoDTE>/', $xml, $m)) {
            return $m[1];
        }
        return '0';
    }
}
