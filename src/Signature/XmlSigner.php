<?php

declare(strict_types=1);

namespace SiiDte\Signature;

use SiiDte\Exceptions\SiiException;
use SiiDte\Storage\CertificateStore;
use SiiDte\Utils\XmlFragment;

/**
 * Genera firmas XMLDSig parciales para DTE y sobres usando el certificado del emisor.
 */
class XmlSigner
{
    private const MAX_XML_SIZE = 5 * 1024 * 1024;
    private const SII_NAMESPACE = 'http://www.sii.cl/SiiDte';
    private const DSIG_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';
    private const C14N_ALGORITHM = 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315';
    private const SHA1_ALGORITHM = 'http://www.w3.org/2000/09/xmldsig#sha1';
    private const RSA_SHA1_ALGORITHM = 'http://www.w3.org/2000/09/xmldsig#rsa-sha1';

    private string $privateKey;
    private string $certData; // Base64 del certificado público

    public function __construct(string|CertificateStore $certificateSource, ?string $certPassword = null)
    {
        if ($certificateSource instanceof CertificateStore) {
            if (!$this->loadPkcs12($certificateSource->getPkcs12Data(), $certificateSource->getPassword())) {
                throw new SiiException('No se pudo abrir el certificado PKCS#12. Verifica el archivo y la contraseña.');
            }

            return;
        }

        if ($certPassword === null) {
            throw new SiiException('La contraseña del certificado es obligatoria.');
        }

        $this->loadCertificate($certificateSource, $certPassword);
    }

    /**
     * Firma el XML de un DTE individual.
     *
     * @param string $xml XML del DTE a firmar
     * @throws SiiException Si el XML es inválido o falta el nodo Documento
     * @return string XML firmado
     */
    public function signDte(string $xml): string
    {
        $doc = $this->loadSafeXml($xml, 'XML del DTE inválido.');
        $root = $doc->documentElement;

        if (!$root instanceof \DOMElement
            || $root->localName !== 'DTE'
            || $root->namespaceURI !== self::SII_NAMESPACE
        ) {
            throw new SiiException('La raíz del XML no es un DTE del SII.');
        }

        $documentNodes = $this->directChildren($root, 'Documento', self::SII_NAMESPACE);
        if (count($documentNodes) !== 1) {
            throw new SiiException('El DTE debe contener exactamente un nodo Documento directo.');
        }

        if ($root->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Signature')->length > 0) {
            throw new SiiException('El DTE ya contiene una firma XMLDSig.');
        }

        $target = $documentNodes[0];
        $id = $this->getUniqueTargetId($doc, $target);
        $caf = XmlFragment::extract($xml, ['DTE', 'Documento', 'TED', 'DD', 'CAF']);
        $signedXml = $this->signTarget($doc, $target, $root, $id);

        return $caf === null
            ? $signedXml
            : XmlFragment::replace($signedXml, ['DTE', 'Documento', 'TED', 'DD', 'CAF'], $caf);
    }

    /**
     * Firma el conjunto de un sobre EnvioDTE o EnvioBOLETA.
     *
     * @param string $xml XML del sobre a firmar
     * @throws SiiException Si el XML es inválido o falta el nodo SetDTE
     * @return string XML del sobre firmado
     */
    public function signEnvelope(string $xml): string
    {
        $doc = $this->loadSafeXml($xml, 'XML del sobre inválido.');
        $root = $doc->documentElement;

        if (!$root instanceof \DOMElement
            || !in_array($root->localName, ['EnvioDTE', 'EnvioBOLETA'], true)
            || $root->namespaceURI !== self::SII_NAMESPACE
        ) {
            throw new SiiException('La raíz XML no corresponde a un sobre DTE o de boletas del SII.');
        }

        $setNodes = $this->directChildren($root, 'SetDTE', self::SII_NAMESPACE);
        if (count($setNodes) !== 1) {
            throw new SiiException('El sobre debe contener exactamente un nodo SetDTE directo.');
        }

        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->localName === 'Signature'
                && $child->namespaceURI === self::DSIG_NAMESPACE
            ) {
                throw new SiiException('El sobre ya contiene una firma XMLDSig.');
            }
        }

        $target = $setNodes[0];
        $id = $this->getUniqueTargetId($doc, $target);
        $rootName = $root->localName;
        $cafPath = [$rootName, 'SetDTE', 'DTE', 'Documento', 'TED', 'DD', 'CAF'];
        $originalCafs = XmlFragment::extractAll($xml, $cafPath);
        $signedXml = $this->signTarget($doc, $target, $root, $id);

        return $originalCafs === []
            ? $signedXml
            : XmlFragment::replaceAll($signedXml, $cafPath, $originalCafs);
    }

    /**
     * Genera el GetTokenRequest firmado (para autenticación con el SII).
     *
     * @param string $seed Semilla obtenida del SII
     * @return string XML de solicitud de token firmado
     */
    public function signTokenRequest(string $seed): string
    {
        if ($seed === '' || preg_match('/^\d+$/D', $seed) !== 1) {
            throw new SiiException('La semilla de autenticación debe ser una cadena numérica no vacía.');
        }

        if ($this->certData === '') {
            throw new SiiException('No hay un certificado público disponible para firmar la semilla.');
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $root = $doc->createElement('getToken');
        $doc->appendChild($root);
        $item = $doc->createElement('item');
        $root->appendChild($item);
        $seedNode = $doc->createElement('Semilla');
        $seedNode->appendChild($doc->createTextNode($seed));
        $item->appendChild($seedNode);

        $signatureNode = $doc->createElementNS(self::DSIG_NAMESPACE, 'ds:Signature');
        $root->appendChild($signatureNode);
        $signedInfo = $this->createDsigElement($doc, $signatureNode, 'SignedInfo');
        $this->appendDsigElement($doc, $signedInfo, 'CanonicalizationMethod')
            ->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $this->appendDsigElement($doc, $signedInfo, 'SignatureMethod')
            ->setAttribute('Algorithm', self::RSA_SHA1_ALGORITHM);

        $reference = $this->appendDsigElement($doc, $signedInfo, 'Reference');
        $reference->setAttribute('URI', '');
        $transforms = $this->appendDsigElement($doc, $reference, 'Transforms');
        $this->appendDsigElement($doc, $transforms, 'Transform')
            ->setAttribute('Algorithm', self::DSIG_NAMESPACE . 'enveloped-signature');
        $this->appendDsigElement($doc, $reference, 'DigestMethod')
            ->setAttribute('Algorithm', self::SHA1_ALGORITHM);
        $digestValue = $this->appendDsigElement($doc, $reference, 'DigestValue');

        $signatureNode->parentNode?->removeChild($signatureNode);
        $rootCanonical = $root->C14N();
        if ($rootCanonical === false) {
            throw new SiiException('No se pudo canonicalizar la solicitud de semilla.');
        }

        $root->appendChild($signatureNode);
        $digestValue->appendChild($doc->createTextNode(base64_encode(hash('sha1', $rootCanonical, true))));

        $signatureValue = $this->appendDsigElement($doc, $signatureNode, 'SignatureValue');
        $keyInfo = $this->appendDsigElement($doc, $signatureNode, 'KeyInfo');
        $keyValue = $this->appendDsigElement($doc, $keyInfo, 'KeyValue');
        $rsaKeyValue = $this->appendDsigElement($doc, $keyValue, 'RSAKeyValue');
        $rsaDetails = $this->getRsaPublicDetails();
        $this->appendDsigElement($doc, $rsaKeyValue, 'Modulus')
            ->appendChild($doc->createTextNode(base64_encode($rsaDetails['n'])));
        $this->appendDsigElement($doc, $rsaKeyValue, 'Exponent')
            ->appendChild($doc->createTextNode(base64_encode($rsaDetails['e'])));
        $x509Data = $this->appendDsigElement($doc, $keyInfo, 'X509Data');
        $this->appendDsigElement($doc, $x509Data, 'X509Certificate')
            ->appendChild($doc->createTextNode($this->certData));

        $signedInfoCanonical = $signedInfo->C14N();
        if ($signedInfoCanonical === false) {
            throw new SiiException('No se pudo canonicalizar SignedInfo para la solicitud de semilla.');
        }

        if (!@openssl_sign($signedInfoCanonical, $signature, $this->privateKey, OPENSSL_ALGO_SHA1)) {
            $this->clearOpenSslErrors();

            throw new SiiException('No se pudo firmar SignedInfo para la solicitud de semilla.');
        }

        $signatureValue->appendChild($doc->createTextNode(base64_encode($signature)));
        $signedXml = $doc->saveXML();
        if ($signedXml === false) {
            throw new SiiException('No se pudo serializar la solicitud de semilla firmada.');
        }

        return $signedXml;
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

    private function signTarget(
        \DOMDocument $doc,
        \DOMElement $target,
        \DOMElement $signatureParent,
        string $targetId,
    ): string {
        if ($this->certData === '') {
            throw new SiiException('No hay un certificado público disponible para incluir en XMLDSig.');
        }

        $targetCanonical = $target->C14N();
        if ($targetCanonical === false) {
            throw new SiiException('No se pudo canonicalizar el elemento que se debe firmar.');
        }

        $signatureNode = $doc->createElementNS(self::DSIG_NAMESPACE, 'ds:Signature');
        $signatureParent->appendChild($signatureNode);
        $signedInfo = $this->createDsigElement($doc, $signatureNode, 'SignedInfo');
        $this->appendDsigElement($doc, $signedInfo, 'CanonicalizationMethod')
            ->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $this->appendDsigElement($doc, $signedInfo, 'SignatureMethod')
            ->setAttribute('Algorithm', self::RSA_SHA1_ALGORITHM);

        $reference = $this->appendDsigElement($doc, $signedInfo, 'Reference');
        $reference->setAttribute('URI', '#' . $targetId);
        $transforms = $this->appendDsigElement($doc, $reference, 'Transforms');
        $this->appendDsigElement($doc, $transforms, 'Transform')
            ->setAttribute('Algorithm', self::C14N_ALGORITHM);
        $this->appendDsigElement($doc, $reference, 'DigestMethod')
            ->setAttribute('Algorithm', self::SHA1_ALGORITHM);
        $digestValue = $this->appendDsigElement($doc, $reference, 'DigestValue');
        $digestValue->appendChild($doc->createTextNode(base64_encode(hash('sha1', $targetCanonical, true))));

        $signatureValue = $this->appendDsigElement($doc, $signatureNode, 'SignatureValue');
        $keyInfo = $this->appendDsigElement($doc, $signatureNode, 'KeyInfo');
        $keyValue = $this->appendDsigElement($doc, $keyInfo, 'KeyValue');
        $rsaKeyValue = $this->appendDsigElement($doc, $keyValue, 'RSAKeyValue');
        $rsaDetails = $this->getRsaPublicDetails();
        $modulus = $this->appendDsigElement($doc, $rsaKeyValue, 'Modulus');
        $modulus->appendChild($doc->createTextNode(base64_encode($rsaDetails['n'])));
        $exponent = $this->appendDsigElement($doc, $rsaKeyValue, 'Exponent');
        $exponent->appendChild($doc->createTextNode(base64_encode($rsaDetails['e'])));
        $x509Data = $this->appendDsigElement($doc, $keyInfo, 'X509Data');
        $certificate = $this->appendDsigElement($doc, $x509Data, 'X509Certificate');
        $certificate->appendChild($doc->createTextNode($this->certData));

        $signedInfoCanonical = $signedInfo->C14N();
        if ($signedInfoCanonical === false) {
            throw new SiiException('No se pudo canonicalizar SignedInfo en su contexto XML final.');
        }

        if (!@openssl_sign($signedInfoCanonical, $signature, $this->privateKey, OPENSSL_ALGO_SHA1)) {
            $this->clearOpenSslErrors();

            throw new SiiException('No se pudo generar la firma XMLDSig con la clave privada configurada.');
        }

        $signatureValue->appendChild($doc->createTextNode(base64_encode($signature)));
        $signedXml = $doc->saveXML();
        if ($signedXml === false) {
            throw new SiiException('No se pudo serializar el XML firmado.');
        }

        return $signedXml;
    }

    /**
     * @return array{n: string, e: string}
     */
    private function getRsaPublicDetails(): array
    {
        $key = @openssl_pkey_get_private($this->privateKey);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false
            || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA
            || !isset($details['rsa']['n'], $details['rsa']['e'])
        ) {
            $this->clearOpenSslErrors();

            throw new SiiException('XMLDSig de este flujo requiere una clave privada RSA válida.');
        }

        return ['n' => $details['rsa']['n'], 'e' => $details['rsa']['e']];
    }

    private function loadSafeXml(string $xml, string $invalidMessage): \DOMDocument
    {
        if (strlen($xml) > self::MAX_XML_SIZE) {
            throw new SiiException('El XML excede el límite permitido para firmar.');
        }

        $doc = new \DOMDocument('1.0');
        $doc->preserveWhiteSpace = true;
        $doc->formatOutput = false;
        $doc->resolveExternals = false;
        $doc->substituteEntities = false;
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (!$doc->loadXML($xml, LIBXML_NONET) || $doc->doctype !== null || !$doc->documentElement instanceof \DOMElement) {
                throw new SiiException($invalidMessage);
            }
        } catch (\Throwable $exception) {
            if ($exception instanceof SiiException) {
                throw $exception;
            }

            throw new SiiException($invalidMessage);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return $doc;
    }

    /**
     * @return list<\DOMElement>
     */
    private function directChildren(\DOMElement $parent, string $localName, string $namespace): array
    {
        $matches = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === $namespace
            ) {
                $matches[] = $child;
            }
        }

        return $matches;
    }

    private function getUniqueTargetId(\DOMDocument $doc, \DOMElement $target): string
    {
        if (!$target->hasAttribute('ID')) {
            throw new SiiException('El elemento que se debe firmar no tiene atributo ID.');
        }

        $targetId = $target->getAttribute('ID');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9._-]*$/D', $targetId)) {
            throw new SiiException('El atributo ID del elemento no tiene un formato seguro.');
        }

        $ids = [];
        $elements = [$doc->documentElement];
        while ($elements !== []) {
            $element = array_pop($elements);
            foreach ($element->attributes as $attribute) {
                $isXmlId = $attribute->namespaceURI === 'http://www.w3.org/XML/1998/namespace'
                    && $attribute->localName === 'id';
                if ($attribute->localName !== 'ID' && !$isXmlId) {
                    continue;
                }

                if ($attribute->namespaceURI !== null && !$isXmlId) {
                    throw new SiiException('El XML contiene un atributo ID con namespace ambiguo.');
                }

                $value = $attribute->value;
                if (isset($ids[$value])) {
                    throw new SiiException('El XML contiene valores ID duplicados.');
                }
                $ids[$value] = true;
            }

            foreach ($element->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $elements[] = $child;
                }
            }
        }

        if (!$target->hasAttributeNS(null, 'ID') || !isset($ids[$targetId])) {
            throw new SiiException('No se pudo resolver de forma única el atributo ID del elemento.');
        }

        return $targetId;
    }

    private function createDsigElement(\DOMDocument $doc, \DOMElement $parent, string $name): \DOMElement
    {
        $element = $doc->createElementNS(self::DSIG_NAMESPACE, 'ds:' . $name);
        $parent->appendChild($element);

        return $element;
    }

    private function appendDsigElement(\DOMDocument $doc, \DOMElement $parent, string $name): \DOMElement
    {
        return $this->createDsigElement($doc, $parent, $name);
    }

    private function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }

    /**
     * Carga el certificado digital y la clave privada.
     *
     * @param string $certPath Ruta al archivo del certificado
     * @param string $certPassword Contraseña del certificado
     * @throws SiiException Si no se puede leer o cargar el certificado
     */
    private function loadCertificate(string $certPath, string $certPassword): void
    {
        $certContent = file_get_contents($certPath);
        if ($certContent === false) {
            throw new SiiException('No se pudo leer el certificado.');
        }

        if ($this->loadPkcs12($certContent, $certPassword)) {
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
            }

            return;
        }

        throw new SiiException('No se pudo cargar el certificado. Verifique el archivo y la contraseña.');
    }

    private function loadPkcs12(string $pkcs12Data, string $certPassword): bool
    {
        if (!@openssl_pkcs12_read($pkcs12Data, $certs, $certPassword)) {
            while (openssl_error_string() !== false) {
            }

            return false;
        }

        $privateKey = $certs['pkey'] ?? null;
        $certificate = $certs['cert'] ?? null;
        if (!is_string($privateKey)
            || !is_string($certificate)
            || !@openssl_x509_check_private_key($certificate, $privateKey)
        ) {
            while (openssl_error_string() !== false) {
            }

            throw new SiiException('El PKCS#12 no contiene una clave privada correspondiente al certificado.');
        }

        $this->privateKey = $privateKey;
        $this->certData = $this->cleanCert($certificate);

        return true;
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
