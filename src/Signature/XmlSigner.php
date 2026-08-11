<?php

declare(strict_types=1);

namespace SiiDte\Signature;

use SiiDte\Exceptions\SiiException;
use DOMDocument;
use DOMXPath;

/**
 * Firma XML de DTE usando el certificado digital del emisor.
 * Implementa XMLDSig según la norma del SII.
 */
class XmlSigner
{
    private string $privateKey;
    private string $publicCert;
    private string $certData; // Base64 del certificado público

    public function __construct(string $certPath, string $certPassword)
    {
        $this->loadCertificate($certPath, $certPassword);
    }

    /**
     * Firma el XML de un DTE individual.
     *
     * @param string $xml XML del DTE a firmar
     * @return string XML firmado
     * @throws SiiException Si el XML es inválido o falta el nodo Documento
     */
    public function signDte(string $xml): string
    {
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;

        if (!$doc->loadXML($xml)) {
            throw new SiiException('XML del DTE inválido.');
        }

        // Obtener el ID del documento a firmar
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('sii', 'http://www.sii.cl/SiiDte');
        $docNode = $xpath->query('//sii:Documento')->item(0);

        if (!$docNode) {
            throw new SiiException('No se encontró el nodo Documento en el XML.');
        }

        $docId = $docNode->getAttribute('ID');
        if (empty($docId)) {
            throw new SiiException('El nodo Documento no tiene atributo ID.');
        }

        // Canonicalizar el nodo Documento
        $c14n = $docNode->C14N();

        // Calcular el digest SHA1
        $digest = base64_encode(sha1($c14n, true));

        // Construir el SignedInfo
        $signedInfo = $this->buildSignedInfo("#{$docId}", $digest);

        // Firmar el SignedInfo
        $signedInfoC14n = $this->canonicalizeString($signedInfo);
        openssl_sign($signedInfoC14n, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        $signatureB64 = base64_encode($signature);

        // Construir el bloque Signature completo
        $signatureXml = $this->buildSignatureBlock($signedInfo, $signatureB64, "#{$docId}");

        // Insertar la firma dentro del nodo Documento (antes del cierre)
        $sigFrag = $doc->createDocumentFragment();
        $sigFrag->appendXML($signatureXml);
        $docNode->appendChild($sigFrag);

        return $doc->saveXML();
    }

    /**
     * Firma el sobre EnvioDTE completo.
     *
     * @param string $xml XML del sobre a firmar
     * @return string XML del sobre firmado
     * @throws SiiException Si el XML es inválido o falta el nodo SetDTE
     */
    public function signEnvelope(string $xml): string
    {
        $doc = new DOMDocument('1.0', 'ISO-8859-1');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput       = false;

        if (!$doc->loadXML($xml)) {
            throw new SiiException('XML del sobre EnvioDTE inválido.');
        }

        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('sii', 'http://www.sii.cl/SiiDte');
        $setNode = $xpath->query('//sii:SetDTE')->item(0);

        if (!$setNode) {
            throw new SiiException('No se encontró el nodo SetDTE.');
        }

        $setId = $setNode->getAttribute('ID');
        $c14n  = $setNode->C14N();
        $digest = base64_encode(sha1($c14n, true));

        $signedInfo    = $this->buildSignedInfo("#{$setId}", $digest);
        $signedInfoC14n = $this->canonicalizeString($signedInfo);
        openssl_sign($signedInfoC14n, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        $signatureB64  = base64_encode($signature);

        $signatureXml  = $this->buildSignatureBlock($signedInfo, $signatureB64, "#{$setId}");

        $sigFrag = $doc->createDocumentFragment();
        $sigFrag->appendXML($signatureXml);
        $doc->documentElement->appendChild($sigFrag);

        return $doc->saveXML();
    }

    /**
     * Genera el GetTokenRequest firmado (para autenticación con el SII).
     *
     * @param string $seed Semilla obtenida del SII
     * @return string XML de solicitud de token firmado
     */
    public function signTokenRequest(string $seed): string
    {
        $xml  = '<getToken>';
        $xml .= '<item>';
        $xml .= '<Semilla>' . htmlspecialchars($seed, ENT_XML1) . '</Semilla>';
        $xml .= '</item>';
        $xml .= '</getToken>';

        openssl_sign($xml, $signature, $this->privateKey, OPENSSL_ALGO_SHA1);
        $signatureB64 = base64_encode($signature);

        $signed  = '<getToken>';
        $signed .= '<item><Semilla>' . htmlspecialchars($seed, ENT_XML1) . '</Semilla></item>';
        $signed .= '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">';
        $signed .= '<SignedInfo>';
        $signed .= '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>';
        $signed .= '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>';
        $signed .= '<Reference URI="">';
        $signed .= '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>';
        $signed .= '<DigestValue>' . base64_encode(sha1($xml, true)) . '</DigestValue>';
        $signed .= '</Reference>';
        $signed .= '</SignedInfo>';
        $signed .= '<SignatureValue>' . $signatureB64 . '</SignatureValue>';
        $signed .= '<KeyInfo><KeyValue><RSAKeyValue>';
        $signed .= '</RSAKeyValue></KeyValue></KeyInfo>';
        $signed .= '</Signature>';
        $signed .= '</getToken>';

        return $signed;
    }

    /**
     * Obtiene los datos del certificado en base64.
     *
     * @return string Certificado en base64
     */
    public function getCertData(): string
    {
        return $this->certData;
    }


    /**
     * Construye el nodo SignedInfo para la firma XML.
     *
     * @param string $uri URI del elemento a firmar
     * @param string $digest Valor digest SHA1 en base64
     * @return string Fragmento XML de SignedInfo
     */
    private function buildSignedInfo(string $uri, string $digest): string
    {
        return '<SignedInfo xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . '<CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '<SignatureMethod Algorithm="http://www.w3.org/2000/09/xmldsig#rsa-sha1"/>'
            . '<Reference URI="' . $uri . '">'
            . '<Transforms>'
            . '<Transform Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
            . '</Transforms>'
            . '<DigestMethod Algorithm="http://www.w3.org/2000/09/xmldsig#sha1"/>'
            . '<DigestValue>' . $digest . '</DigestValue>'
            . '</Reference>'
            . '</SignedInfo>';
    }

    /**
     * Construye el bloque Signature completo.
     *
     * @param string $signedInfo XML de SignedInfo
     * @param string $signatureValue Valor de la firma en base64
     * @param string $uri URI del elemento firmado
     * @return string Bloque XML Signature
     */
    private function buildSignatureBlock(string $signedInfo, string $signatureValue, string $uri): string
    {
        return '<Signature xmlns="http://www.w3.org/2000/09/xmldsig#">'
            . $signedInfo
            . '<SignatureValue>' . $signatureValue . '</SignatureValue>'
            . '<KeyInfo>'
            . '<KeyValue><RSAKeyValue/></KeyValue>'
            . '<X509Data><X509Certificate>' . $this->certData . '</X509Certificate></X509Data>'
            . '</KeyInfo>'
            . '</Signature>';
    }

    /**
     * Canonicaliza un fragmento XML usando C14N.
     *
     * @param string $xmlFragment Fragmento XML
     * @return string Fragmento canonicalizado
     */
    private function canonicalizeString(string $xmlFragment): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($xmlFragment);
        return $doc->documentElement->C14N();
    }

    /**
     * Carga el certificado digital y la clave privada.
     *
     * @param string $certPath Ruta al archivo del certificado
     * @param string $certPassword Contraseña del certificado
     * @throws SiiException Si no se puede leer o cargar el certificado
     * @return void
     */
    private function loadCertificate(string $certPath, string $certPassword): void
    {
        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            throw new SiiException("No se pudo leer el certificado: {$certPath}");
        }

        // Intentar como PKCS12 (.p12 / .pfx)
        if (openssl_pkcs12_read($certContent, $certs, $certPassword)) {
            $this->privateKey = $certs['pkey'];
            // Limpiar encabezados PEM para obtener solo el base64
            $this->certData = $this->cleanCert($certs['cert']);
            $this->publicCert = $certs['cert'];
            return;
        }

        // Intentar como PEM
        $privKey = openssl_pkey_get_private($certContent, $certPassword);
        if ($privKey !== false) {
            openssl_pkey_export($privKey, $this->privateKey);
            // Buscar el .crt correspondiente
            $crtPath = str_replace(['.pem', '.key'], '.crt', $certPath);
            if (file_exists($crtPath)) {
                $certPem = file_get_contents($crtPath);
                $this->certData   = $this->cleanCert($certPem);
                $this->publicCert = $certPem;
            }
            return;
        }

        throw new SiiException('No se pudo cargar el certificado. Verifique el archivo y la contraseña.');
    }

    /**
     * Limpia las cabeceras PEM de un certificado para obtener solo el base64.
     *
     * @param string $pem Certificado en formato PEM
     * @return string Base64 limpio
     */
    private function cleanCert(string $pem): string
    {
        $pem = preg_replace('/-----BEGIN CERTIFICATE-----/', '', $pem);
        $pem = preg_replace('/-----END CERTIFICATE-----/', '', $pem);
        return trim(str_replace(["\n", "\r", ' '], '', $pem));
    }
}
