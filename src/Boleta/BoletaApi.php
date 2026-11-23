<?php

declare(strict_types=1);

namespace SiiDte\Boleta;

use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\TransportException;
use SiiDte\Signature\XmlSigner;
use SiiDte\Utils\CurlHttpTransport;
use SiiDte\Utils\HttpRequestTransport;
use SiiDte\Utils\HttpResponse;
use SiiDte\Utils\RutHelper;

/**
 * Cliente de los servicios REST de boleta electrónica del SII:
 * semilla/token (apicert.sii.cl | api.sii.cl), envío (pangal.sii.cl | rahue.sii.cl)
 * y consulta de estado por Track ID.
 *
 * Es independiente de los servicios SOAP de facturas: usa su propia autenticación.
 */
final class BoletaApi
{
    private const AUTH_URLS = [
        'certificacion' => 'https://apicert.sii.cl/recursos/v1',
        'produccion' => 'https://api.sii.cl/recursos/v1',
    ];
    private const UPLOAD_URLS = [
        'certificacion' => 'https://pangal.sii.cl/recursos/v1',
        'produccion' => 'https://rahue.sii.cl/recursos/v1',
    ];
    private const MAX_UPLOAD_SIZE = 5 * 1024 * 1024;
    private const MAX_RESPONSE_SIZE = 2 * 1024 * 1024;
    private const USER_AGENT = 'User-Agent: Mozilla/4.0 ( compatible; PROG 1.0; Windows NT)';

    private ?string $cachedToken = null;
    private int $tokenExpiry = 0;
    private readonly HttpRequestTransport $transport;

    public function __construct(
        private readonly XmlSigner $signer,
        private readonly string $ambiente,
        ?HttpRequestTransport $transport = null,
        private readonly int $timeoutSeconds = 30,
    ) {
        if (!isset(self::AUTH_URLS[$ambiente])) {
            throw new SiiException("Ambiente no reconocido: {$ambiente}");
        }
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('El timeout debe ser positivo.');
        }
        $this->transport = $transport ?? new CurlHttpTransport();
    }

    /**
     * Obtiene (y cachea por ~1 hora) el token de los servicios de boleta.
     */
    public function getToken(): string
    {
        if ($this->cachedToken !== null && time() < $this->tokenExpiry - 300) {
            return $this->cachedToken;
        }

        $base = self::AUTH_URLS[$this->ambiente];
        $seedResponse = $this->send('GET', $base . '/boleta.electronica.semilla', ['Accept: application/xml']);
        $this->assertOk($seedResponse, 'obtener la semilla');
        $seedDocument = $this->loadXml($seedResponse->body);
        $seed = $this->findText($seedDocument, 'SEMILLA');
        if ($seed === null || preg_match('/^\d+$/D', $seed) !== 1) {
            throw new SiiException('La respuesta del SII no contiene una semilla numérica válida' . $this->siiStatus($seedDocument) . '.');
        }

        $tokenResponse = $this->send('POST', $base . '/boleta.electronica.token', [
            'Content-Type: application/xml',
            'Accept: application/xml',
        ], $this->signer->signTokenRequest($seed));
        $this->assertOk($tokenResponse, 'obtener el token');
        $tokenDocument = $this->loadXml($tokenResponse->body);
        $token = $this->findText($tokenDocument, 'TOKEN');
        if ($token === null || $token === '') {
            // Sin token el SII informa el motivo en ESTADO/GLOSA (certificado no reconocido, firma inválida, etc.).
            throw new SiiException('El SII no entregó el token de boletas' . $this->siiStatus($tokenDocument) . '.');
        }
        if (preg_match('/^[A-Za-z0-9._\-]{4,256}$/D', $token) !== 1) {
            throw new SiiException('El SII entregó un token de boletas con formato inesperado (' . strlen($token) . ' caracteres)' . $this->siiStatus($tokenDocument) . '.');
        }

        $this->cachedToken = $token;
        $this->tokenExpiry = time() + 3600;

        return $token;
    }

    /**
     * Envía un sobre EnvioBOLETA firmado.
     *
     * @param string $rutEmisor RUT de la empresa emisora ("76123456-0")
     * @param string|null $rutEnvia RUT de quien firma/transmite; por defecto, el emisor
     * @throws TransportException Si el resultado remoto puede ser desconocido (no reintente a ciegas)
     * @throws SiiException Si el SII rechaza el envío
     * @return array{trackId: string, estado: string, respuesta: array<string, mixed>}
     */
    public function enviar(string $sobreXml, string $rutEmisor, ?string $rutEnvia = null): array
    {
        if ($sobreXml === '' || strlen($sobreXml) > self::MAX_UPLOAD_SIZE) {
            throw new SiiException('El sobre de boletas está vacío o excede el límite permitido.');
        }
        [$rutCompany, $dvCompany] = $this->splitRut($rutEmisor);
        [$rutSender, $dvSender] = $this->splitRut($rutEnvia ?? $rutEmisor);
        $token = $this->getToken();

        $boundary = '----SiiDteBoundary' . bin2hex(random_bytes(18));
        $body = '';
        foreach (['rutSender' => $rutSender, 'dvSender' => $dvSender, 'rutCompany' => $rutCompany, 'dvCompany' => $dvCompany] as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }
        $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"archivo\"; filename=\"envio.xml\"\r\n"
            . "Content-Type: text/xml\r\n\r\n{$sobreXml}\r\n--{$boundary}--\r\n";

        $response = $this->send('POST', self::UPLOAD_URLS[$this->ambiente] . '/boleta.electronica.envio', [
            'Cookie: TOKEN=' . $token,
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            'Accept: application/json',
        ], $body, true);

        $json = $this->decodeJson($response->body);
        if ($response->statusCode >= 200 && $response->statusCode < 300 && is_array($json)
            && isset($json['trackid']) && preg_match('/^[1-9]\d*$/D', (string) $json['trackid']) === 1
        ) {
            return [
                'trackId' => (string) $json['trackid'],
                'estado' => is_string($json['estado'] ?? null) ? $json['estado'] : 'REC',
                'respuesta' => $json,
            ];
        }
        if ($response->statusCode >= 400 && $response->statusCode < 500) {
            throw new SiiException('El SII rechazó el envío de boletas (HTTP ' . $response->statusCode . ')' . $this->detail($json) . '.');
        }

        throw new TransportException(
            'No se pudo interpretar la respuesta del envío de boletas; el resultado remoto puede ser desconocido. No reintente automáticamente.',
            true,
        );
    }

    /**
     * Consulta el estado de un envío de boletas por Track ID.
     *
     * @return array{trackId: string, estado: string, estadistica: list<array<string, mixed>>, detalle: list<array<string, mixed>>, respuesta: array<string, mixed>}
     */
    public function estado(string $rutEmisor, string $trackId): array
    {
        if (preg_match('/^[1-9]\d*$/D', $trackId) !== 1) {
            throw new SiiException('El Track ID debe ser numérico.');
        }
        [$rut, $dv] = $this->splitRut($rutEmisor);
        $response = $this->send('GET', self::AUTH_URLS[$this->ambiente] . "/boleta.electronica.envio/{$rut}-{$dv}-{$trackId}", [
            'Cookie: TOKEN=' . $this->getToken(),
            'Accept: application/json',
        ]);
        $this->assertOk($response, 'consultar el estado');
        $json = $this->decodeJson($response->body);
        if (!is_array($json) || !is_string($json['estado'] ?? null)) {
            throw new SiiException('La respuesta de estado del SII no tiene el formato esperado.');
        }

        $list = static fn(mixed $v): array => is_array($v) ? array_values(array_filter($v, 'is_array')) : [];

        return [
            'trackId' => $trackId,
            'estado' => $json['estado'],
            'estadistica' => $list($json['estadistica'] ?? []),
            'detalle' => $list($json['detalle_rep_rech'] ?? []),
            'respuesta' => $json,
        ];
    }

    /** @param list<string> $headers */
    private function send(string $method, string $url, array $headers, ?string $body = null, bool $effectful = false): HttpResponse
    {
        $headers[] = self::USER_AGENT;
        try {
            $response = $this->transport->request($method, $url, $headers, $body, $this->timeoutSeconds);
        } catch (TransportException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new TransportException(
                'La solicitud HTTP falló' . ($effectful ? '; el resultado remoto puede ser desconocido. No reintente automáticamente.' : '.'),
                $effectful,
                0,
                $e,
            );
        }
        if (strlen($response->body) > self::MAX_RESPONSE_SIZE) {
            throw new SiiException('La respuesta del SII excede el tamaño permitido.');
        }

        return $response;
    }

    private function assertOk(HttpResponse $response, string $action): void
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new SiiException("El SII respondió HTTP {$response->statusCode} al {$action}.");
        }
    }

    /** @return array{0: string, 1: string} */
    private function splitRut(string $rut): array
    {
        $rut = str_replace('.', '', strtoupper(trim($rut)));
        if (!RutHelper::validate($rut)) {
            throw new SiiException('RUT inválido.');
        }
        $parts = RutHelper::parse($rut);

        return [$parts['rut'], $parts['dv']];
    }

    private function loadXml(string $xml): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (stripos($xml, '<!DOCTYPE') !== false || !$document->loadXML($xml, LIBXML_NONET)) {
                throw new SiiException('La respuesta del SII no es XML válido.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $document;
    }

    private function findText(\DOMDocument $document, string $localName): ?string
    {
        foreach ($document->getElementsByTagName('*') as $element) {
            if ($element->localName === $localName) {
                return trim($element->textContent);
            }
        }

        return null;
    }

    private function decodeJson(string $body): mixed
    {
        try {
            return json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * " (estado X: glosa)" a partir de la cabecera RESP_HDR del SII, o cadena vacía si no viene.
     */
    private function siiStatus(\DOMDocument $document): string
    {
        $clean = static fn(?string $text): string => mb_substr(preg_replace('/[^\P{C}\t]+/u', '', (string) $text) ?? '', 0, 200);
        $estado = $this->findText($document, 'ESTADO');
        $glosa = $this->findText($document, 'GLOSA');
        if ($estado === null && $glosa === null) {
            return '';
        }

        return ' (estado ' . ($estado === null ? '?' : $clean($estado)) . ($glosa === null || $glosa === '' ? '' : ': ' . $clean($glosa)) . ')';
    }

    private function detail(mixed $json): string
    {
        if (is_array($json)) {
            foreach (['mensaje', 'message', 'error'] as $key) {
                if (isset($json[$key]) && is_string($json[$key])) {
                    return ': ' . mb_substr(preg_replace('/[^\P{C}\t]+/u', '', $json[$key]) ?? '', 0, 200);
                }
            }
        }

        return '';
    }
}
