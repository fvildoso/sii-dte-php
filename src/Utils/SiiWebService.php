<?php

declare(strict_types=1);

namespace SiiDte\Utils;

use SiiDte\Exceptions\SiiException;

/**
 * Cliente para los Web Services de recepción y consulta de DTE del SII.
 */
class SiiWebService
{
    private string $ambiente;

    // URLs upload DTE
    private const UPLOAD_URLS = [
        'certificacion' => 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload',
        'produccion'    => 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload',
    ];

    // URLs consulta estado
    private const STATUS_URLS = [
        'certificacion' => 'https://maullin.sii.cl/DTEWS/QueryEstDteAv.jws',
        'produccion'    => 'https://palena.sii.cl/DTEWS/QueryEstDteAv.jws',
    ];

    // URLs consulta DTE individual
    private const QUERY_DTE_URLS = [
        'certificacion' => 'https://maullin.sii.cl/DTEWS/services/wsRPETCConsulta',
        'produccion'    => 'https://palena.sii.cl/DTEWS/services/wsRPETCConsulta',
    ];

    public function __construct(string $ambiente)
    {
        $this->ambiente = $ambiente;
    }

    /**
     * Envía el sobre EnvioDTE al SII y retorna el Track ID.
     *
     * @param string $rut          RUT emisor sin DV
     * @param string $dv           DV del emisor
     * @param string $xmlFirmado   XML del sobre firmado
     * @param string $token        Token de sesión SII
     * @return string              Track ID del envío
     * @throws SiiException        Si el ambiente no es válido o el SII rechaza el envío
     */
    public function uploadDte(string $rut, string $dv, string $xmlFirmado, string $token): string
    {
        $url     = self::UPLOAD_URLS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");
        $rutFull = $rut . '-' . $dv;

        // El SII espera multipart/form-data con el XML como archivo
        $boundary  = '----SiiDteBoundary' . md5((string) time());
        $body      = "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"rutSender\"\r\n\r\n{$rutFull}\r\n";
        $body     .= "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"rutCompany\"\r\n\r\n{$rutFull}\r\n";
        $body     .= "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"archivo\"; filename=\"envio.xml\"\r\n";
        $body     .= "Content-Type: text/xml\r\n\r\n";
        $body     .= $xmlFirmado . "\r\n";
        $body     .= "--{$boundary}--\r\n";

        $response  = $this->httpPost($url, $body, [
            'Cookie: TOKEN=' . $token,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ]);

        // Parsear Track ID
        if (preg_match('/TRACKID>(\d+)<\/TRACKID/i', $response, $m)) {
            return $m[1];
        }

        if (preg_match('/ESTADO>([^<]+)<\/ESTADO/i', $response, $m)) {
            throw new SiiException("SII rechazó el envío. Estado: {$m[1]} | Respuesta: " . substr($response, 0, 500));
        }

        throw new SiiException('Respuesta inesperada del SII al enviar DTE: ' . substr($response, 0, 500));
    }

    /**
     * Consulta el estado de un envío por Track ID.
     *
     * @param string $rut Cuerpo del RUT del emisor
     * @param string $dv Dígito verificador del emisor
     * @param string $trackId ID de seguimiento retornado por el SII
     * @param string $token Token de sesión vigente
     * @return array Mapa con el estado, glosa y detalle de la respuesta
     * @throws SiiException Si el ambiente no es válido
     */
    public function getStatus(string $rut, string $dv, string $trackId, string $token): array
    {
        $url     = self::STATUS_URLS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");
        $rutFull = $rut . '-' . $dv;

        $body    = '<getEstadoAvanzado>'
            . '<Rut>' . htmlspecialchars($rutFull, ENT_XML1) . '</Rut>'
            . '<TrackId>' . htmlspecialchars($trackId, ENT_XML1) . '</TrackId>'
            . '<Token>' . htmlspecialchars($token, ENT_XML1) . '</Token>'
            . '</getEstadoAvanzado>';

        $soapXml = $this->wrapSoap($body);
        $response = $this->httpPost($url, $soapXml, [
            'Content-Type: text/xml; charset=UTF-8',
            'SOAPAction: "getEstadoAvanzado"',
        ]);

        return $this->parseEstadoResponse($response);
    }

    /**
     * Consulta si un DTE específico fue recibido y aceptado por el SII.
     *
     * @param string $rutEmisor RUT del emisor
     * @param string $dvEmisor Dígito verificador del emisor
     * @param string $rutReceptor RUT del receptor
     * @param string $dvReceptor Dígito verificador del receptor
     * @param int $tipoDte Código del tipo de DTE
     * @param int $folio Número de folio del documento
     * @param string $fechaEmision Fecha de emisión (AAAA-MM-DD)
     * @param int $montoPesos Monto total del documento
     * @param string $token Token de sesión vigente
     * @return array Resultado de la consulta puntual
     * @throws SiiException Si el ambiente no es válido
     */
    public function queryDte(
        string $rutEmisor,
        string $dvEmisor,
        string $rutReceptor,
        string $dvReceptor,
        int    $tipoDte,
        int    $folio,
        string $fechaEmision,
        int    $montoPesos,
        string $token
    ): array {
        $url = self::QUERY_DTE_URLS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");

        $body = '<getEstDte>'
            . '<RutEmisor>' . $rutEmisor . '-' . $dvEmisor . '</RutEmisor>'
            . '<RutReceptor>' . $rutReceptor . '-' . $dvReceptor . '</RutReceptor>'
            . '<TipoDTE>' . $tipoDte . '</TipoDTE>'
            . '<Folio>' . $folio . '</Folio>'
            . '<FchEmis>' . $fechaEmision . '</FchEmis>'
            . '<MontoPesos>' . $montoPesos . '</MontoPesos>'
            . '<Token>' . $token . '</Token>'
            . '</getEstDte>';

        $soapXml  = $this->wrapSoap($body);
        $response = $this->httpPost($url, $soapXml, [
            'Content-Type: text/xml; charset=UTF-8',
            'SOAPAction: "getEstDte"',
        ]);

        return $this->parseEstadoResponse($response);
    }


    /**
     * Parsea la respuesta XML del estado de envío.
     *
     * @param string $response Respuesta XML del SII
     * @return array Datos estructurados del estado
     */
    private function parseEstadoResponse(string $response): array
    {
        $estado = '';
        $glosa  = '';

        if (preg_match('/<ESTADO>([^<]*)<\/ESTADO>/i', $response, $m)) {
            $estado = $m[1];
        }
        if (preg_match('/<GLOSA>([^<]*)<\/GLOSA>/i', $response, $m)) {
            $glosa = $m[1];
        }

        // Extraer detalles de documentos si existen
        $detalle = [];
        if (preg_match_all('/<DTE_ENVIADO>(.*?)<\/DTE_ENVIADO>/is', $response, $docs)) {
            foreach ($docs[1] as $doc) {
                $item = [];
                if (preg_match('/<FOLIO>(\d+)<\/FOLIO>/i', $doc, $f)) {
                    $item['folio']  = $f[1];
                }
                if (preg_match('/<TIPO_DTE>(\d+)<\/TIPO_DTE>/i', $doc, $t)) {
                    $item['tipo']   = $t[1];
                }
                if (preg_match('/<ESTADO>([^<]+)<\/ESTADO>/i', $doc, $e)) {
                    $item['estado'] = $e[1];
                }
                $detalle[] = $item;
            }
        }

        return [
            'estado'  => $estado,
            'glosa'   => $glosa,
            'detalle' => $detalle,
            'raw'     => $response,
        ];
    }

    /**
     * Envuelve el cuerpo del mensaje en un sobre SOAP 1.1.
     *
     * @param string $body Cuerpo del mensaje XML
     * @return string Mensaje SOAP completo
     */
    private function wrapSoap(string $body): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body>' . $body . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }

    /**
     * Realiza una petición HTTP POST usando cURL.
     *
     * @param string $url URL de destino
     * @param string $body Cuerpo del POST
     * @param array $headers Lista de cabeceras HTTP
     * @return string Cuerpo de la respuesta
     * @throws SiiException Si ocurre un error de conexión o el servidor responde error
     */
    private function httpPost(string $url, string $body, array $headers): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new SiiException("Error HTTP [{$errno}]: {$errmsg}");
        }

        if ($httpCode >= 500) {
            throw new SiiException("Error del servidor SII (HTTP {$httpCode}).");
        }

        return (string) $response;
    }
}
