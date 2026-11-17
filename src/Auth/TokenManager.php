<?php

declare(strict_types=1);

namespace SiiDte\Auth;

use SiiDte\Exceptions\SiiException;
use SiiDte\Signature\XmlSigner;
use SiiDte\Utils\CurlHttpTransport;
use SiiDte\Utils\HttpResponse;
use SiiDte\Utils\HttpTransport;

/**
 * Gestiona la obtención y renovación del token de sesión del SII.
 *
 * El proceso es:
 *   1. Solicitar semilla al SII (getSeed)
 *   2. Firmar la semilla con el certificado digital
 *   3. Enviar la semilla firmada para obtener el token (getToken)
 */
class TokenManager
{
    private const SOAP_NAMESPACE = 'http://schemas.xmlsoap.org/soap/envelope/';
    private const SOAP_ENCODING_NAMESPACE = 'http://schemas.xmlsoap.org/soap/encoding/';
    private const SERVICE_NAMESPACE = 'http://DefaultNamespace';
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';
    private const XSD_NAMESPACE = 'http://www.w3.org/2001/XMLSchema';
    private const MAX_RESPONSE_SIZE = 2 * 1024 * 1024;

    private ?string $cachedToken = null;
    private int $tokenExpiry = 0;
    private readonly HttpTransport $transport;

    public function __construct(
        private readonly XmlSigner $signer,
        private readonly string $ambiente,
        ?HttpTransport $transport = null,
        private readonly int $timeoutSeconds = 30,
    ) {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('El timeout de autenticación debe ser positivo.');
        }

        $this->transport = $transport ?? new CurlHttpTransport();
    }

    // URLs de los WS del SII
    private const ENDPOINTS = [
        'certificacion' => 'https://maullin.sii.cl/DTEWS/',
        'produccion'    => 'https://palena.sii.cl/DTEWS/',
    ];

    /**
     * Obtiene un token válido. Usa caché si aún es válido.
     *
     * @param string $rut RUT del emisor (sin DV)
     * @param string $dv Dígito verificador
     * @return string Token de sesión obtenido
     */
    public function getToken(string $rut, string $dv): string
    {
        // El token del SII dura ~1 hora. Renovamos si queda menos de 5 minutos.
        if ($this->cachedToken && time() < $this->tokenExpiry - 300) {
            return $this->cachedToken;
        }

        $seed  = $this->getSeed();
        $token = $this->requestToken($seed);

        $this->cachedToken = $token;
        $this->tokenExpiry = time() + 3600; // 1 hora

        return $token;
    }

    /**
     * Paso 1: Obtener la semilla del SII.
     *
     * @throws SiiException Si ocurre un error al obtener la semilla
     * @return string Semilla obtenida
     */
    private function getSeed(): string
    {
        $url      = $this->getBaseUrl() . 'CrSeed.jws';
        $response = $this->soapCall($url, 'getSeed');
        $document = $this->loadResponseXml($this->unwrapSoapString($response, 'getSeedReturn'));
        $this->assertSiiSuccess($document, 'obtener semilla');
        $seed = $this->findElementText($document, 'SEMILLA');

        if ($seed === null || preg_match('/^\d+$/D', $seed) !== 1) {
            throw new SiiException('La respuesta del SII no contiene una semilla numérica válida.');
        }

        return $seed;
    }

    /**
     * Paso 2: Firmar semilla y obtener token.
     *
     * @param string $seed Semilla obtenida del SII
     * @throws SiiException Si la firma falla o el SII rechaza la solicitud
     * @return string Token de sesión
     */
    private function requestToken(string $seed): string
    {
        // Firmar la semilla
        $signedSeed = $this->signer->signTokenRequest($seed);
        $url  = $this->getBaseUrl() . 'GetTokenFromSeed.jws';
        $response = $this->soapCall($url, 'getToken', $signedSeed);
        $document = $this->loadResponseXml($this->unwrapSoapString($response, 'getTokenReturn'));
        $this->assertSiiSuccess($document, 'obtener token');
        $token = $this->findElementText($document, 'TOKEN');

        if ($token === null || trim($token) === '') {
            throw new SiiException('La respuesta del SII no contiene un token.');
        }

        return trim($token);
    }

    /**
     * Realiza una llamada SOAP básica al SII.
     *
     * @param string $url URL del servicio
     * @param string|null $xmlParameter XML firmado para el parámetro pszXml
     * @throws SiiException Si ocurre un error de red
     * @return string Respuesta del servidor
     */
    private function soapCall(string $url, string $operation, ?string $xmlParameter = null): string
    {
        $response = $this->transport->post(
            $url,
            $this->buildSoapRequest($operation, $xmlParameter),
            ['Content-Type: text/xml; charset=UTF-8', 'SOAPAction: ""'],
            $this->timeoutSeconds,
        );

        return $this->responseBody($response);
    }

    private function buildSoapRequest(string $operation, ?string $xmlParameter): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $envelope = $document->createElementNS(self::SOAP_NAMESPACE, 'soapenv:Envelope');
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NAMESPACE);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsd', self::XSD_NAMESPACE);
        $envelope->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:def', self::SERVICE_NAMESPACE);
        $document->appendChild($envelope);
        $body = $document->createElementNS(self::SOAP_NAMESPACE, 'soapenv:Body');
        $envelope->appendChild($body);
        $operationNode = $document->createElementNS(self::SERVICE_NAMESPACE, 'def:' . $operation);
        $operationNode->setAttributeNS(
            self::SOAP_NAMESPACE,
            'soapenv:encodingStyle',
            self::SOAP_ENCODING_NAMESPACE,
        );
        $body->appendChild($operationNode);

        if ($xmlParameter !== null) {
            $parameter = $document->createElement('pszXml');
            $parameter->setAttributeNS(self::XSI_NAMESPACE, 'xsi:type', 'xsd:string');
            $parameter->appendChild($document->createTextNode($xmlParameter));
            $operationNode->appendChild($parameter);
        }

        $xml = $document->saveXML();
        if ($xml === false) {
            throw new SiiException('No se pudo serializar la solicitud SOAP de autenticación.');
        }

        return $xml;
    }

    private function responseBody(HttpResponse $response): string
    {
        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            throw new SiiException('El servicio de autenticación del SII respondió HTTP ' . $response->statusCode . '.');
        }

        return $response->body;
    }

    private function unwrapSoapString(string $response, string $returnElement): string
    {
        $document = $this->loadResponseXml($response);
        $payload = $this->findElement($document, $returnElement);
        if ($payload instanceof \DOMElement) {
            return $payload->textContent;
        }

        $fault = $this->findElement($document, 'Fault');
        if ($fault instanceof \DOMElement) {
            throw new SiiException('El servicio de autenticación del SII devolvió un error SOAP.');
        }

        if ($document->documentElement instanceof \DOMElement
            && $document->documentElement->localName === 'RESPUESTA'
        ) {
            return $response;
        }

        throw new SiiException('La respuesta SOAP de autenticación no contiene el resultado esperado.');
    }

    private function assertSiiSuccess(\DOMDocument $document, string $operation): void
    {
        $status = $this->findElementText($document, 'ESTADO');
        if ($status !== '00') {
            $glosa = $this->findElementText($document, 'GLOSA');
            $message = 'Error al ' . $operation . ' con el SII.';
            if ($status !== null) {
                $message .= ' Estado: ' . substr($status, 0, 32) . '.';
            }
            if ($glosa !== null && trim($glosa) !== '') {
                $message .= ' ' . substr(trim($glosa), 0, 200);
            }

            throw new SiiException($message);
        }
    }

    private function loadResponseXml(string $xml): \DOMDocument
    {
        if ($xml === '' || strlen($xml) > self::MAX_RESPONSE_SIZE) {
            throw new SiiException('La respuesta XML de autenticación está vacía o excede el límite permitido.');
        }

        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new SiiException('El servicio de autenticación devolvió XML inválido o inseguro.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return $document;
    }

    private function findElementText(\DOMDocument $document, string $localName): ?string
    {
        $element = $this->findElement($document, $localName);

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

    /**
     * Obtiene la URL base según el ambiente configurado.
     *
     * @throws SiiException Si el ambiente no es reconocido
     * @return string URL base del ambiente
     */
    private function getBaseUrl(): string
    {
        return self::ENDPOINTS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: {$this->ambiente}");
    }
}
