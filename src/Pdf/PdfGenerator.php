<?php

declare(strict_types=1);

namespace SiiDte\Pdf;

use SiiDte\Exceptions\SiiException;

/**
 * Genera el PDF de un DTE.
 *
 * ─── ¿QUÉ GENERA EL PDF? ───────────────────────────────────────────────────
 *
 * Esta librería ofrece DOS formas de obtener el PDF:
 *
 * OPCIÓN 1 — PDF del SII (recomendada para empezar)
 *   El SII provee un endpoint que recibe el XML firmado y devuelve el PDF
 *   en su formato oficial, con timbre electrónico y código de barras 2D (PDF417).
 *   ✅ Gratuito, sin dependencias adicionales
 *   ✅ Formato oficial aceptado para cualquier trámite
 *   ❌ Formato fijo, no puedes personalizarlo con tu logo/colores
 *   ❌ Requiere conexión a internet en cada generación
 *
 * OPCIÓN 2 — PDF personalizado (requiere librería adicional)
 *   Generas el PDF tú mismo usando DOMPDF o TCPDF, a partir del XML.
 *   ✅ Diseño personalizado (logo, colores, layout)
 *   ✅ Sin dependencia del SII para generar el PDF
 *   ❌ Debes implementar el timbre electrónico 2D tú mismo
 *   ❌ Requiere instalar dompdf/dompdf o tecnickcom/tcpdf
 *
 * ─── LO QUE HACE ESTA CLASE ────────────────────────────────────────────────
 *   ✅ Opción 1: llama al WS del SII y retorna los bytes del PDF
 *   ✅ Opción 2: extrae los datos del XML y construye HTML para renderizar
 *
 * ─── LO QUE DEBES HACER TÚ (Opción 2) ──────────────────────────────────────
 *   ❌ Instalar dompdf: composer require dompdf/dompdf
 *   ❌ Implementar el timbre 2D (PDF417) con el CAF y datos del DTE
 *   ❌ Diseñar la plantilla HTML de la factura
 */
class PdfGenerator
{
    private const PDF_URLS = [
        'certificacion' => 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload',
        'produccion'    => 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload',
    ];

    // URL real del servicio de visualización de DTE del SII
    private const VISTA_URLS = [
        'certificacion' => 'https://maullin.sii.cl/cgi_dte/Of_Cartola/cartola_dte.cgi',
        'produccion'    => 'https://palena.sii.cl/cgi_dte/Of_Cartola/cartola_dte.cgi',
    ];

    public function __construct(
        private string $ambiente,
        private string $token
    ) {}

    /**
     * OPCIÓN 1: Obtiene el PDF desde el servicio del SII.
     *
     * El SII provee el PDF a través de su portal. Esta función retorna
     * los bytes del PDF listos para guardar o enviar como respuesta HTTP.
     *
     * NOTA: El SII no tiene un WS directo de PDF; el PDF se obtiene
     * navegando al portal con el token. Para automatizar esto se usa
     * el endpoint de visualización con el XML.
     *
     * @param string $xmlFirmado XML del DTE ya firmado
     * @param string $rutEmisor  RUT del emisor con DV (ej: "12345678-9")
     * @return string            Bytes del PDF
     */
    public function fromSii(string $xmlFirmado, string $rutEmisor): string
    {
        // El SII retorna el PDF cuando se envía el XML al endpoint de vista previa
        $url = self::VISTA_URLS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");

        $boundary = '----SiiPdfBoundary' . md5((string) time());
        $body     = "--{$boundary}\r\n"
                  . "Content-Disposition: form-data; name=\"RUT_EMISOR\"\r\n\r\n{$rutEmisor}\r\n"
                  . "--{$boundary}\r\n"
                  . "Content-Disposition: form-data; name=\"XML_DTE\"; filename=\"dte.xml\"\r\n"
                  . "Content-Type: text/xml\r\n\r\n"
                  . $xmlFirmado . "\r\n"
                  . "--{$boundary}--\r\n";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Cookie: TOKEN=' . $this->token,
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $httpCode !== 200) {
            throw new SiiException(
                "No se pudo obtener el PDF del SII (HTTP {$httpCode}). "
                . "Alternativa: usa fromHtml() con dompdf para generar el PDF localmente."
            );
        }

        // Verificar que la respuesta sea un PDF
        if (!str_starts_with($response, '%PDF')) {
            throw new SiiException(
                "El SII no devolvió un PDF válido. "
                . "Respuesta: " . substr($response, 0, 200)
            );
        }

        return $response;
    }

    /**
     * OPCIÓN 2: Genera un HTML renderizable a partir del XML del DTE.
     *
     * Usa este HTML con dompdf o en un <iframe> para mostrar la factura.
     * Requiere que implementes tu propio diseño o uses la plantilla base.
     *
     * Uso con dompdf:
     *   composer require dompdf/dompdf
     *
     *   $html = $generator->toHtml($xmlFirmado);
     *   $dompdf = new Dompdf\Dompdf();
     *   $dompdf->loadHtml($html);
     *   $dompdf->setPaper('letter', 'portrait');
     *   $dompdf->render();
     *   $pdfBytes = $dompdf->output();
     *
     * @param string $xmlFirmado XML del DTE firmado
     * @param array  $opciones   Opciones de personalización:
     *   - logo_url: URL o base64 del logo de la empresa
     *   - color_primario: color hex para encabezado (default: #1e3a5f)
     *   - mostrar_timbre: bool, si incluir sección de timbre (default: true)
     * @return string HTML listo para renderizar a PDF
     */
    public function toHtml(string $xmlFirmado, array $opciones = []): string
    {
        $datos = $this->parseXml($xmlFirmado);
        return $this->renderHtml($datos, $opciones);
    }


    private function parseXml(string $xml): array
    {
        libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $doc->loadXML($xml);

        $get = function (string $tag, \DOMDocument $d) {
            return $d->getElementsByTagName($tag)->item(0)?->textContent ?? '';
        };

        return [
            'tipo_dte'      => $get('TipoDTE', $doc),
            'folio'         => $get('Folio', $doc),
            'fecha'         => $get('FchEmis', $doc),
            'emisor'        => [
                'rut'          => $get('RUTEmisor', $doc),
                'razon_social' => $get('RznSoc', $doc),
                'giro'         => $get('GiroEmis', $doc),
                'direccion'    => $get('DirOrigen', $doc),
                'ciudad'       => $get('CmnaOrigen', $doc),
            ],
            'receptor'      => [
                'rut'          => $get('RUTRecep', $doc),
                'razon_social' => $get('RznSocRecep', $doc),
                'giro'         => $get('GiroRecep', $doc),
                'direccion'    => $get('DirRecep', $doc),
                'ciudad'       => $get('CmnaRecep', $doc),
            ],
            'monto_neto'    => $get('MntNeto', $doc),
            'monto_iva'     => $get('IVA', $doc),
            'monto_exento'  => $get('MntExe', $doc),
            'monto_total'   => $get('MntTotal', $doc),
            'detalle'       => $this->parseDetalle($doc),
        ];
    }

    private function parseDetalle(\DOMDocument $doc): array
    {
        $items = [];
        foreach ($doc->getElementsByTagName('Detalle') as $det) {
            $get = fn(string $t) => $det->getElementsByTagName($t)->item(0)?->textContent ?? '';
            $items[] = [
                'nombre'      => $get('NmbItem'),
                'descripcion' => $get('DscItem'),
                'cantidad'    => $get('QtyItem'),
                'unidad'      => $get('UnmdItem'),
                'precio'      => $get('PrcItem'),
                'descuento'   => $get('DescuentoPct'),
                'monto'       => $get('MontoItem'),
                'exento'      => $get('IndExe') === '1',
            ];
        }
        return $items;
    }

    private function renderHtml(array $d, array $opt): string
    {
        $color   = $opt['color_primario'] ?? '#1e3a5f';
        $logo    = $opt['logo_url'] ?? '';
        $tipoNombre = match ((int) $d['tipo_dte']) {
            33  => 'FACTURA ELECTRÓNICA', 34 => 'FACTURA NO AFECTA ELECTRÓNICA',
            39  => 'BOLETA ELECTRÓNICA',  41 => 'BOLETA NO AFECTA ELECTRÓNICA',
            46  => 'FACTURA DE COMPRA ELECTRÓNICA',
            52  => 'GUÍA DE DESPACHO ELECTRÓNICA',
            56  => 'NOTA DE DÉBITO ELECTRÓNICA',  61 => 'NOTA DE CRÉDITO ELECTRÓNICA',
            110 => 'FACTURA DE EXPORTACIÓN',
            default => "DTE TIPO {$d['tipo_dte']}",
        };

        $filas = '';
        foreach ($d['detalle'] as $item) {
            $badge = $item['exento'] ? '<span style="font-size:9px;color:#888">(exento)</span>' : '';
            $filas .= "<tr>
                <td>{$item['nombre']} {$badge}<br><small style='color:#666'>{$item['descripcion']}</small></td>
                <td style='text-align:center'>{$item['cantidad']} {$item['unidad']}</td>
                <td style='text-align:right'>$ " . number_format((int) $item['precio'], 0, ',', '.') . "</td>
                <td style='text-align:right'>$ " . number_format((int) $item['monto'], 0, ',', '.') . "</td>
            </tr>";
        }

        $logoTag = $logo ? "<img src='{$logo}' style='max-height:60px'>" : '';

        return <<<HTML
            <!DOCTYPE html>
            <html lang="es">
            <head>
            <meta charset="UTF-8">
            <style>
              body { font-family: Arial, sans-serif; font-size: 11px; color: #222; margin: 0; padding: 20px; }
              .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; }
              .emisor { flex: 1; }
              .doc-box { background: {$color}; color: white; padding: 15px 20px; text-align: center; min-width: 200px; border-radius: 4px; }
              .doc-box .tipo { font-size: 13px; font-weight: bold; }
              .doc-box .folio { font-size: 22px; font-weight: bold; margin: 5px 0; }
              .doc-box .rut { font-size: 11px; }
              table { width: 100%; border-collapse: collapse; margin-top: 15px; }
              .receptor-table td { padding: 3px 6px; }
              .receptor-table tr:nth-child(odd) { background: #f5f5f5; }
              .detalle-table th { background: {$color}; color: white; padding: 6px 8px; text-align: left; }
              .detalle-table td { padding: 5px 8px; border-bottom: 1px solid #eee; vertical-align: top; }
              .totales { margin-top: 15px; float: right; width: 280px; }
              .totales table td { padding: 4px 8px; }
              .totales .total-row { font-weight: bold; font-size: 13px; background: {$color}; color: white; }
              .timbre { margin-top: 30px; border-top: 2px dashed #999; padding-top: 10px; font-size: 9px; color: #666; }
              .clearfix::after { content: ''; display: table; clear: both; }
            </style>
            </head>
            <body>

            <div class="header">
              <div class="emisor">
                {$logoTag}
                <h2 style="margin:5px 0">{$d['emisor']['razon_social']}</h2>
                <div>{$d['emisor']['giro']}</div>
                <div>{$d['emisor']['direccion']}, {$d['emisor']['ciudad']}</div>
                <div><strong>RUT:</strong> {$d['emisor']['rut']}</div>
              </div>
              <div class="doc-box">
                <div class="rut">RUT: {$d['emisor']['rut']}</div>
                <div class="tipo">{$tipoNombre}</div>
                <div class="folio">N° {$d['folio']}</div>
                <div>S.I.I. — SANTIAGO</div>
              </div>
            </div>

            <table class="receptor-table">
              <tr><td width="80"><strong>Señor(es):</strong></td><td>{$d['receptor']['razon_social']}</td>
                  <td width="80"><strong>RUT:</strong></td><td>{$d['receptor']['rut']}</td></tr>
              <tr><td><strong>Giro:</strong></td><td>{$d['receptor']['giro']}</td>
                  <td><strong>Fecha:</strong></td><td>{$d['fecha']}</td></tr>
              <tr><td><strong>Dirección:</strong></td><td>{$d['receptor']['direccion']}</td>
                  <td><strong>Ciudad:</strong></td><td>{$d['receptor']['ciudad']}</td></tr>
            </table>

            <table class="detalle-table">
              <thead>
                <tr><th>Descripción</th><th style="text-align:center">Cant.</th>
                    <th style="text-align:right">Precio Unit.</th><th style="text-align:right">Total</th></tr>
              </thead>
              <tbody>{$filas}</tbody>
            </table>

            <div class="clearfix">
              <div class="totales">
                <table>
            HTML
        . ($d['monto_neto'] ? "<tr><td>Neto:</td><td style='text-align:right'>$ " . number_format((int) $d['monto_neto'], 0, ',', '.') . "</td></tr>" : '')
        . ($d['monto_exento'] ? "<tr><td>Exento:</td><td style='text-align:right'>$ " . number_format((int) $d['monto_exento'], 0, ',', '.') . "</td></tr>" : '')
        . ($d['monto_iva'] ? "<tr><td>IVA (19%):</td><td style='text-align:right'>$ " . number_format((int) $d['monto_iva'], 0, ',', '.') . "</td></tr>" : '')
        . "<tr class='total-row'><td>TOTAL:</td><td style='text-align:right'>$ " . number_format((int) $d['monto_total'], 0, ',', '.') . "</td></tr>"
        . <<<HTML
                </table>
              </div>
            </div>

            <div class="timbre">
              <strong>Timbre Electrónico SII</strong><br>
              Resolución N° {RES} del {FECHA_RES} — Verifique documento en www.sii.cl<br>
              <em>[El código de barras 2D (PDF417) con el timbre electrónico debe generarse aquí
              usando los datos del CAF y el algoritmo de firma del SII. Ver docs/timbre.md]</em>
            </div>

            </body>
            </html>
            HTML;
    }
}
