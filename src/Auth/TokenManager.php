<?php

declare(strict_types=1);

namespace SiiDte\Auth;

use SiiDte\Signature\XmlSigner;
use SiiDte\Exceptions\SiiException;

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
    private XmlSigner $signer;
    private string $ambiente;
    private ?string $cachedToken = null;
    private int $tokenExpiry    = 0;

    // URLs de los WS del SII
    private const ENDPOINTS = [
        'certificacion' => 'https://maullin.sii.cl/DTEWS/',
        'produccion'    => 'https://palena.sii.cl/DTEWS/',
    ];

    public function __construct(XmlSigner $signer, string $ambiente)
    {
        $this->signer  = $signer;
        $this->ambiente = $ambiente;
    }

    /**
     * Obtiene un token válido. Usa caché si aún es válido.
     *
     * @param string $rut RUT del emisor (sin DV)
     * @param string $dv  Dígito verificador
     * @return string Token de sesión obtenido
     */
    public function getToken(string $rut, string $dv): string
    {
        // El token del SII dura ~1 hora. Renovamos si queda menos de 5 minutos.
        if ($this->cachedToken && time() < $this->tokenExpiry - 300) {
            return $this->cachedToken;
        }

        $seed  = $this->getSeed();
        $token = $this->requestToken($seed, $rut, $dv);

        $this->cachedToken = $token;
        $this->tokenExpiry = time() + 3600; // 1 hora

        return $token;
    }

    /**
     * Paso 1: Obtener la semilla del SII.
     *
     * @return string Semilla obtenida
     * @throws SiiException Si ocurre un error al obtener la semilla
     */
    private function getSeed(): string
    {
        $url      = $this->getBaseUrl() . 'CrSeed.jws';
        $response = $this->soapCall($url, 'getSeed', '<getSeed/>');

        // Parsear la respuesta
        if (preg_match('/<SEMILLA>([^<]+)<\/SEMILLA>/', $response, $m)) {
            return $m[1];
        }

        // Verificar si hubo error
        if (preg_match('/<ESTADO>([^<]+)<\/ESTADO>/', $response, $m)) {
            throw new SiiException("Error al obtener semilla SII. Estado: $m[1]");
        }

        throw new SiiException('Respuesta inesperada al obtener semilla del SII: ' . substr($response, 0, 500));
    }

    /**
     * Paso 2: Firmar semilla y obtener token.
     *
     * @param string $seed Semilla obtenida del SII
     * @param string $rut Cuerpo del RUT
     * @param string $dv Dígito verificador
     * @return string Token de sesión
     * @throws SiiException Si la firma falla o el SII rechaza la solicitud
     */
    private function requestToken(string $seed, string $rut, string $dv): string
    {
        // Firmar la semilla
        $signedSeed = $this->signer->signTokenRequest($seed);

        // Envolver en SOAP body
        $body = '<getToken>' . $signedSeed . '</getToken>';
        $url  = $this->getBaseUrl() . 'GetTokenFromSeed.jws';

        $response = $this->soapCall($url, 'getToken', $body);

        // Extraer token
        if (preg_match('/<TOKEN>([^<]+)<\/TOKEN>/', $response, $m)) {
            return $m[1];
        }

        // Verificar estado
        if (preg_match('/<ESTADO>([^<]+)<\/ESTADO>/', $response, $m)
            && preg_match('/<GLOSA>([^<]+)<\/GLOSA>/', $response, $g)) {
            throw new SiiException("Error al obtener token. Estado: $m[1] - $g[1]");
        }

        throw new SiiException('No se pudo obtener el token del SII. Respuesta: ' . substr($response, 0, 500));
    }

    /**
     * Realiza una llamada SOAP básica al SII.
     *
     * @param string $url URL del servicio
     * @param string $action Acción SOAP a ejecutar
     * @param string $body Cuerpo XML de la petición
     * @return string Respuesta del servidor
     * @throws SiiException Si ocurre un error de red
     */
    private function soapCall(string $url, string $action, string $body): string
    {
        $soap = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:xsd="http://www.w3.org/2001/XMLSchema"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<soapenv:Body>' . $body . '</soapenv:Body>'
            . '</soapenv:Envelope>';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $soap,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: text/xml; charset=UTF-8',
                'SOAPAction: "' . $action . '"',
            ],
        ]);

        $response = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0) {
            throw new SiiException("Error de conexión con SII [$errno]: $errmsg");
        }

        return (string) $response;
    }

    /**
     * Obtiene la URL base según el ambiente configurado.
     *
     * @return string URL base del ambiente
     * @throws SiiException Si el ambiente no es reconocido
     */
    private function getBaseUrl(): string
    {
        return self::ENDPOINTS[$this->ambiente]
            ?? throw new SiiException("Ambiente no reconocido: $this->ambiente");
    }
}
