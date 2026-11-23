<?php

declare(strict_types=1);

namespace SiiDte\Utils;

use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\TransportException;

/**
 * Cliente para los Web Services de recepción y consulta de DTE del SII.
 */
class SiiWebService
{
    private readonly HttpTransport $transport;
    private readonly int $timeoutSeconds;

    // URLs upload DTE
    private const UPLOAD_URLS = [
        'certificacion' => 'https://maullin.sii.cl/cgi_dte/UPL/DTEUpload',
        'produccion'    => 'https://palena.sii.cl/cgi_dte/UPL/DTEUpload',
    ];

    /** Códigos STATUS de la respuesta de DTEUpload (manual de upload del SII); cualquier otro es error interno. */
    private const UPLOAD_STATUS = [
        1 => 'el remitente (RutEnvia) no tiene permiso para enviar documentos de la empresa',
        2 => 'error en el tamaño del archivo',
        3 => 'archivo cortado',
        5 => 'no está autenticado (token inválido o vencido)',
        6 => 'la empresa no está autorizada para enviar archivos',
        7 => 'esquema inválido',
        8 => 'error en la firma del documento',
        9 => 'sistema bloqueado',
    ];

    // URLs consulta estado
    private const STATUS_URLS = [
        'certificacion' => 'https://maullin.sii.cl/DTEWS/QueryEstUp.jws',
        'produccion'    => 'https://palena.sii.cl/DTEWS/QueryEstUp.jws',
    ];

    // URLs consulta DTE individual
    private const QUERY_DTE_URLS = [
        'certificacion' => 'https://maullin.sii.cl/DTEWS/QueryEstDte.jws',
        'produccion'    => 'https://palena.sii.cl/DTEWS/QueryEstDte.jws',
    ];

    public function __construct(
        private readonly string $ambiente,
        ?HttpTransport $transport = null,
        int $timeoutSeconds = 60,
    ) {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('El timeout del servicio SII debe ser positivo.');
        }

        $this->transport = $transport ?? new CurlHttpTransport();
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Envía el sobre EnvioDTE al SII y retorna el Track ID.
     *
     * @param string $rut RUT emisor sin DV
     * @param string $dv DV del emisor
     * @param string $xmlFirmado XML del sobre firmado
     * @param string $token Token de sesión SII
     * @throws SiiException Si el ambiente no es válido o el SII rechaza el envío
     * @return string Track ID del envío
     */
    public function uploadDte(
        string $rut,
        string $dv,
        string $xmlFirmado,
        string $token,
        ?string $rutSender = null,
        ?string $dvSender = null,
    ): string {
        $url     = self::UPLOAD_URLS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");
        $this->assertValidRut($rut, $dv);
        if (($rutSender === null) !== ($dvSender === null)) {
            throw new SiiException('El RUT y DV del firmante deben suministrarse juntos.');
        }
        $rutSender ??= $rut;
        $dvSender ??= $dv;
        $this->assertValidRut($rutSender, $dvSender);
        $this->assertValidToken($token);
        if ($xmlFirmado === '' || strlen($xmlFirmado) > 5 * 1024 * 1024) {
            throw new SiiException('El XML del sobre está vacío o excede el límite permitido para el envío.');
        }

        // El SII espera multipart/form-data con el XML como archivo
        try {
            $boundary = '----SiiDteBoundary' . bin2hex(random_bytes(18));
        } catch (\Throwable) {
            throw new SiiException('No se pudo crear el separador multipart del envío.');
        }
        $body      = "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"rutSender\"\r\n\r\n{$rutSender}\r\n";
        $body     .= "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"dvSender\"\r\n\r\n{$dvSender}\r\n";
        $body     .= "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"rutCompany\"\r\n\r\n{$rut}\r\n";
        $body     .= "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"dvCompany\"\r\n\r\n{$dv}\r\n";
        $body     .= "--{$boundary}\r\n";
        $body     .= "Content-Disposition: form-data; name=\"archivo\"; filename=\"envio.xml\"\r\n";
        $body     .= "Content-Type: text/xml\r\n\r\n";
        $body     .= $xmlFirmado . "\r\n";
        $body     .= "--{$boundary}--\r\n";

        $response = $this->httpPost($url, $body, [
            'Cookie: TOKEN=' . $token,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ], true);

        try {
            $document = $this->loadResponseXml($response);
            $payload = $this->findStringReturn($document);
            if ($payload !== null) {
                $document = $this->loadResponseXml($payload);
            }
            $this->assertNoSoapFault($document);
        } catch (SiiException $exception) {
            throw new TransportException(
                'La respuesta de recepción no se pudo interpretar; el resultado remoto puede ser desconocido. No reintente automáticamente.'
                . self::responseExcerpt($response),
                true,
                0,
                $exception,
            );
        }
        $status = $this->findElementText($document, 'STATUS');
        $trackId = $this->findElementText($document, 'TRACKID');
        if ($status === '0' && $trackId !== null
            && preg_match('/^\d+$/D', $trackId) === 1 && preg_match('/[1-9]/', $trackId) === 1
        ) {
            return $trackId;
        }

        // Un STATUS distinto de 0 sin track ID significa que el SII no aceptó el sobre (incluido 99, error
        // interno). Con track ID la respuesta es contradictoria y se trata como incierta más abajo.
        if ($status !== null && preg_match('/^\d{1,3}$/D', $status) === 1 && $status !== '0' && $trackId === null) {
            $errors = [];
            foreach ($document->getElementsByTagName('*') as $element) {
                if ($element->localName === 'ERROR' && trim($element->textContent) !== '') {
                    $errors[] = self::clean($element->textContent, 200);
                }
            }
            throw new SiiException(
                'El SII rechazó el envío (STATUS ' . $status . ': ' . (self::UPLOAD_STATUS[(int) $status] ?? 'error interno del SII') . ')'
                . ($errors === [] ? '' : '. Detalle: ' . implode(' | ', array_slice($errors, 0, 5)))
                . '.'
            );
        }

        throw new TransportException(
            'No se pudo interpretar la respuesta de recepción; el resultado remoto puede ser desconocido. No reintente automáticamente.'
            . self::responseExcerpt($response),
            true,
        );
    }

    /**
     * Extracto legible de una respuesta inesperada (sin etiquetas ni caracteres de control), para diagnosticar.
     */
    private static function responseExcerpt(string $body): string
    {
        $text = self::clean(strip_tags($body), 300);

        return $text === '' ? ' Respuesta vacía.' : ' Respuesta: ' . $text;
    }

    private static function clean(string $text, int $max): string
    {
        $text = preg_replace('/[^\P{C}\t]+/u', ' ', $text) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, $max);
    }

    /**
     * Consulta el estado de un envío por Track ID.
     *
     * @param string $rut Cuerpo del RUT del emisor
     * @param string $dv Dígito verificador del emisor
     * @param string $trackId ID de seguimiento retornado por el SII
     * @param string $token Token de sesión vigente
     * @throws SiiException Si el ambiente no es válido
     * @return array Mapa con el estado, glosa y detalle de la respuesta
     */
    public function getStatus(string $rut, string $dv, string $trackId, string $token): array
    {
        $url     = self::STATUS_URLS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");
        $this->assertValidRut($rut, $dv);
        $this->assertValidToken($token);
        if (preg_match('/^\d+$/D', $trackId) !== 1) {
            throw new SiiException('El Track ID debe ser numérico.');
        }
        $body = '<m:getEstUp xmlns:m="http://DefaultNamespace">'
            . $this->soapStringField('RutCompania', $rut)
            . $this->soapStringField('DvCompania', $dv)
            . $this->soapStringField('TrackId', $trackId)
            . $this->soapStringField('Token', $token)
            . '</m:getEstUp>';

        $soapXml = $this->wrapSoap($body);
        $response = $this->httpPost($url, $soapXml, [
            'Content-Type: text/xml; charset=UTF-8',
            'SOAPAction: ""',
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
     * @throws SiiException Si el ambiente no es válido
     * @return array Resultado de la consulta puntual
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
        string $token,
        ?string $rutConsultante = null,
        ?string $dvConsultante = null,
    ): array {
        $url = self::QUERY_DTE_URLS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");

        $this->assertValidRut($rutEmisor, $dvEmisor);
        $this->assertValidRut($rutReceptor, $dvReceptor);
        if (($rutConsultante === null) !== ($dvConsultante === null)) {
            throw new SiiException('El RUT y DV del consultante deben suministrarse juntos.');
        }
        $rutConsultante ??= $rutEmisor;
        $dvConsultante ??= $dvEmisor;
        $this->assertValidRut($rutConsultante, $dvConsultante);
        $this->assertValidToken($token);
        $parsedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $fechaEmision);
        if ($tipoDte <= 0 || $folio <= 0 || $montoPesos < 0 || $parsedDate === false
            || $parsedDate->format('Y-m-d') !== $fechaEmision
        ) {
            throw new SiiException('Los parámetros de consulta del DTE no son válidos.');
        }

        $body = '<m:getEstDte xmlns:m="http://DefaultNamespace">'
            . $this->soapStringField('RutConsultante', $rutConsultante)
            . $this->soapStringField('DvConsultante', $dvConsultante)
            . $this->soapStringField('RutCompania', $rutEmisor)
            . $this->soapStringField('DvCompania', $dvEmisor)
            . $this->soapStringField('RutReceptor', $rutReceptor)
            . $this->soapStringField('DvReceptor', $dvReceptor)
            . $this->soapStringField('TipoDte', (string) $tipoDte)
            . $this->soapStringField('FolioDte', (string) $folio)
            . $this->soapStringField('FechaEmisionDte', $parsedDate->format('dmY'))
            . $this->soapStringField('MontoDte', (string) $montoPesos)
            . $this->soapStringField('Token', $token)
            . '</m:getEstDte>';

        $soapXml  = $this->wrapSoap($body);
        $response = $this->httpPost($url, $soapXml, [
            'Content-Type: text/xml; charset=UTF-8',
            'SOAPAction: ""',
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
        $document = $this->loadResponseXml($response);
        $payload = $this->findStringReturn($document);
        if ($payload !== null) {
            $document = $this->loadResponseXml($payload);
        }
        $this->assertNoSoapFault($document);

        $estado = $this->findElementText($document, 'ESTADO') ?? '';
        $glosa = $this->findElementText($document, 'GLOSA') ?? '';
        $detalle = [];
        foreach ($document->getElementsByTagName('*') as $node) {
            if ($node->localName !== 'DTE_ENVIADO') {
                continue;
            }

            $item = [];
            foreach (['FOLIO' => 'folio', 'TIPO_DTE' => 'tipo', 'ESTADO' => 'estado', 'GLOSA' => 'glosa'] as $tag => $key) {
                $value = $this->findElementText($node, $tag);
                if ($value !== null) {
                    $item[$key] = $value;
                }
            }
            $detalle[] = $item;
        }

        return [
            'estado'  => $estado,
            'glosa'   => $glosa,
            'detalle' => $detalle,
            'raw'     => $response,
        ];
    }

    private function soapStringField(string $name, string $value): string
    {
        return '<' . $name . ' xsi:type="xsd:string">'
            . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</' . $name . '>';
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
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' soapenv:encodingStyle="http://schemas.xmlsoap.org/soap/encoding/">'
            . '<soapenv:Body>' . $body . '</soapenv:Body>'
            . '</soapenv:Envelope>';
    }

    /**
     * Realiza una petición HTTP POST usando cURL.
     *
     * @param string $url URL de destino
     * @param string $body Cuerpo del POST
     * @param array $headers Lista de cabeceras HTTP
     * @throws SiiException Si ocurre un error de conexión o el servidor responde error
     * @return string Cuerpo de la respuesta
     */
    private function httpPost(string $url, string $body, array $headers, bool $effectful = false): string
    {
        // El manual de upload del SII usa este User-Agent; sin él DTEUpload puede responder una página HTML.
        $headers[] = 'User-Agent: Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)';
        $response = $this->transport->post($url, $body, $headers, $this->timeoutSeconds);
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            if ($effectful) {
                throw new TransportException(
                    'El servicio SII respondió HTTP ' . $response->statusCode
                    . '; el resultado del envío puede ser desconocido. No reintente automáticamente.'
                    . self::responseExcerpt($response->body),
                    true,
                );
            }

            throw new SiiException('El servicio SII respondió HTTP ' . $response->statusCode . '.');
        }

        return $response->body;
    }

    private function assertValidRut(string $rut, string $dv): void
    {
        if (preg_match('/^[0-9]{1,8}$/D', $rut) !== 1
            || preg_match('/^[0-9Kk]$/D', $dv) !== 1
            || !RutHelper::validate($rut . '-' . $dv)
        ) {
            throw new SiiException('El RUT indicado para la operación no es válido.');
        }
    }

    private function assertValidToken(string $token): void
    {
        if ($token === '' || preg_match('/^[A-Za-z0-9._-]+$/D', $token) !== 1) {
            throw new SiiException('El token de sesión está vacío o contiene caracteres de control.');
        }
    }

    private function loadResponseXml(string $xml): \DOMDocument
    {
        if ($xml === '' || strlen($xml) > 2 * 1024 * 1024) {
            throw new SiiException('La respuesta XML del SII está vacía o excede el límite permitido.');
        }

        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new SiiException('El servicio SII devolvió XML inválido o inseguro.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return $document;
    }

    private function findStringReturn(\DOMNode $node): ?string
    {
        if ($node instanceof \DOMElement && preg_match('/Return$/', $node->localName) === 1) {
            return $node->textContent;
        }

        foreach ($node->childNodes as $child) {
            $found = $this->findStringReturn($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function findElementText(\DOMNode $node, string $localName): ?string
    {
        $element = $this->findElement($node, $localName);

        return $element instanceof \DOMElement ? trim($element->textContent) : null;
    }

    private function findElement(\DOMNode $node, string $localName): ?\DOMElement
    {
        if ($node instanceof \DOMElement && $node->localName === $localName) {
            return $node;
        }

        foreach ($node->childNodes as $child) {
            $found = $this->findElement($child, $localName);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function assertNoSoapFault(\DOMDocument $document): void
    {
        if ($this->findElement($document, 'Fault') instanceof \DOMElement) {
            throw new SiiException('El servicio SII devolvió un error SOAP.');
        }
    }
}
