<?php

declare(strict_types=1);

namespace SiiDte\Envelope;

use SiiDte\Document\DteTypes;
use SiiDte\Exceptions\SiiException;
use SiiDte\Utils\RutHelper;
use SiiDte\Utils\XmlFragment;

/**
 * Construye el sobre XML EnvioDTE para enviar al SII.
 *
 * El sobre contiene un DTE firmado y los datos del emisor/receptor.
 */
class EnvelopeBuilder
{
    private const SII_NAMESPACE = 'http://www.sii.cl/SiiDte';
    private const XSI_NAMESPACE = 'http://www.w3.org/2001/XMLSchema-instance';
    private const MAX_XML_SIZE = 5 * 1024 * 1024;
    /**
     * Construye el XML del sobre EnvioDTE.
     *
     * @param string $dteFirmadoXml XML del DTE ya firmado
     * @param array $emisor Configuración del emisor
     * @param array $receptor Datos del receptor
     * @param string|null $rutEnvia RUT del titular que transmite/firma, en formato completo
     * @param \DateTimeImmutable|null $timestamp Hora local del sobre; solo para reloj inyectable y pruebas
     * @param string|null $rutReceptorSobre RUT del destinatario del sobre, distinto del receptor del DTE
     * @throws SiiException Si el DTE o los datos de la carátula no son consistentes
     * @return string XML del sobre generado
     */
    public static function build(
        string $dteFirmadoXml,
        array $emisor,
        array $receptor,
        ?string $rutEnvia = null,
        ?\DateTimeImmutable $timestamp = null,
        ?string $rutReceptorSobre = null,
    ): string {
        $dteDocument = self::loadSafeXml($dteFirmadoXml);
        $dteRoot = $dteDocument->documentElement;
        if (!$dteRoot instanceof \DOMElement
            || $dteRoot->localName !== 'DTE'
            || $dteRoot->namespaceURI !== self::SII_NAMESPACE
        ) {
            throw new SiiException('El XML firmado debe tener una raíz DTE del SII.');
        }

        $documentNode = self::documentNode($dteRoot);
        $header = $documentNode instanceof \DOMElement ? self::directChild($documentNode, 'Encabezado') : null;
        $idDoc = $header instanceof \DOMElement ? self::directChild($header, 'IdDoc') : null;
        $dteIssuer = $header instanceof \DOMElement ? self::directChild($header, 'Emisor') : null;
        $dteReceiver = $header instanceof \DOMElement ? self::directChild($header, 'Receptor') : null;
        $type = $idDoc instanceof \DOMElement ? self::directChildText($idDoc, 'TipoDTE') : null;
        $issuerRut = $dteIssuer instanceof \DOMElement ? self::directChildText($dteIssuer, 'RUTEmisor') : null;
        $receiverRut = $dteReceiver instanceof \DOMElement ? self::directChildText($dteReceiver, 'RUTRecep') : null;

        if ($type === null || preg_match('/^\d+$/D', $type) !== 1 || $issuerRut === null || $receiverRut === null) {
            throw new SiiException('El DTE firmado no contiene los datos mínimos de emisor, receptor y tipo.');
        }

        $configuredIssuer = self::configuredRut($emisor, 'rut_emisor', 'dv_emisor');
        $configuredReceiver = self::configuredRut($receptor, 'rut', 'dv');
        $actualSender = RutHelper::validate($issuerRut) ? $issuerRut : '';
        $actualReceiver = RutHelper::validate($receiverRut) ? $receiverRut : '';
        if ($actualSender === '' || $actualReceiver === ''
            || self::normalizeRut($actualSender) !== self::normalizeRut($configuredIssuer)
            || self::normalizeRut($actualReceiver) !== self::normalizeRut($configuredReceiver)
        ) {
            throw new SiiException('Los RUT de emisor o receptor de la carátula no coinciden con el DTE firmado.');
        }

        $senderRut = $rutEnvia ?? $configuredIssuer;
        if (!RutHelper::validate($senderRut)) {
            throw new SiiException('El RUT del titular que transmite el sobre no es válido.');
        }
        $envelopeRecipient = $rutReceptorSobre ?? $configuredReceiver;
        if (!RutHelper::validate($envelopeRecipient)) {
            throw new SiiException('El RUT del destinatario del sobre no es válido.');
        }
        $envelopeRecipient = self::normalizeRut($envelopeRecipient);

        $resolutionDate = $emisor['fecha_resolucion'] ?? null;
        $resolutionNumber = $emisor['numero_resolucion'] ?? null;
        $parsedResolutionDate = is_string($resolutionDate)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $resolutionDate)
            : false;
        if (!is_string($resolutionDate)
            || $parsedResolutionDate === false
            || $parsedResolutionDate->format('Y-m-d') !== $resolutionDate
            || (!is_int($resolutionNumber) && !(is_string($resolutionNumber) && preg_match('/^\d+$/D', $resolutionNumber) === 1))
        ) {
            throw new SiiException('La fecha o el número de resolución del emisor no son válidos.');
        }

        $timestamp ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago'));
        $dteFragment = $dteDocument->saveXML($dteRoot);
        if ($dteFragment === false) {
            throw new SiiException('No se pudo conservar el nodo DTE firmado.');
        }
        $caf = XmlFragment::extract($dteFirmadoXml, ['DTE', $documentNode->localName, 'TED', 'DD', 'CAF']);

        $encoding = $dteDocument->encoding ?: 'UTF-8';
        // DOMDocument::saveXML($node) devuelve UTF-8 incluso cuando el documento declara otra codificación.
        $encodedFragment = @iconv('UTF-8', $encoding, $dteFragment);
        if ($encodedFragment === false) {
            throw new SiiException('No se pudo conservar la codificación del DTE firmado en el sobre.');
        }
        if ($caf !== null) {
            $encodedFragment = XmlFragment::replace(
                $encodedFragment,
                ['DTE', $documentNode->localName, 'TED', 'DD', 'CAF'],
                $caf
            );
        }

        $envelopeDocument = new \DOMDocument('1.0', $encoding);
        $envelopeDocument->preserveWhiteSpace = true;
        $envelopeDocument->formatOutput = false;
        $root = $envelopeDocument->createElementNS(self::SII_NAMESPACE, 'EnvioDTE');
        self::declareSchema($root, 'EnvioDTE_v10.xsd');
        $root->setAttribute('version', '1.0');
        $envelopeDocument->appendChild($root);

        $set = $envelopeDocument->createElementNS(self::SII_NAMESPACE, 'SetDTE');
        $set->setAttribute('ID', 'SetDoc');
        $root->appendChild($set);
        $cover = $envelopeDocument->createElementNS(self::SII_NAMESPACE, 'Caratula');
        $cover->setAttribute('version', '1.0');
        $set->appendChild($cover);

        foreach ([
            'RutEmisor' => $configuredIssuer,
            'RutEnvia' => $senderRut,
            'RutReceptor' => $envelopeRecipient,
            'FchResol' => $resolutionDate,
            'NroResol' => (string) $resolutionNumber,
            'TmstFirmaEnv' => $timestamp->format('Y-m-d\\TH:i:s'),
        ] as $elementName => $value) {
            $element = $envelopeDocument->createElementNS(self::SII_NAMESPACE, $elementName);
            $element->appendChild($envelopeDocument->createTextNode($value));
            $cover->appendChild($element);
        }

        $subtotal = $envelopeDocument->createElementNS(self::SII_NAMESPACE, 'SubTotDTE');
        $cover->appendChild($subtotal);
        foreach (['TpoDTE' => $type, 'NroDTE' => '1'] as $elementName => $value) {
            $element = $envelopeDocument->createElementNS(self::SII_NAMESPACE, $elementName);
            $element->appendChild($envelopeDocument->createTextNode($value));
            $subtotal->appendChild($element);
        }

        $xml = $envelopeDocument->saveXML();
        if ($xml === false) {
            throw new SiiException('No se pudo serializar el sobre EnvioDTE.');
        }

        $setEnd = strrpos($xml, '</SetDTE>');
        if ($setEnd === false) {
            throw new SiiException('No se pudo completar la estructura del sobre EnvioDTE.');
        }

        $xml = substr_replace($xml, $encodedFragment, $setEnd, 0);

        return $xml;
    }

    /**
     * Construye un sobre con uno o más DTE firmados del mismo emisor: EnvioDTE (facturas, notas,
     * guías, exportación, liquidaciones) o EnvioBOLETA (boletas 39/41).
     *
     * Las boletas y los demás documentos no pueden mezclarse en un sobre. El destinatario del
     * sobre es el SII (60803000-K) salvo que se indique otro.
     *
     * @param array<mixed> $dtesFirmados Lista con el XML de cada DTE ya timbrado y firmado
     * @param array<string, mixed> $emisor rut_emisor, dv_emisor, fecha_resolucion, numero_resolucion
     * @param string|null $rutEnvia RUT de quien transmite (por defecto, el emisor)
     * @throws SiiException Si los DTE no son consistentes con el emisor o el tipo de sobre
     */
    public static function buildSet(
        array $dtesFirmados,
        array $emisor,
        ?string $rutEnvia = null,
        ?\DateTimeImmutable $timestamp = null,
        string $rutReceptorSobre = '60803000-K',
    ): string {
        if ($dtesFirmados === [] || !array_is_list($dtesFirmados)) {
            throw new SiiException('Debe indicar al menos un DTE firmado.');
        }
        $configuredIssuer = self::configuredRut($emisor, 'rut_emisor', 'dv_emisor');
        $senderRut = $rutEnvia ?? $configuredIssuer;
        if (!RutHelper::validate($senderRut) || !RutHelper::validate($rutReceptorSobre)) {
            throw new SiiException('El RUT del remitente o del destinatario del sobre no es válido.');
        }

        $resolutionDate = $emisor['fecha_resolucion'] ?? null;
        $resolutionNumber = $emisor['numero_resolucion'] ?? null;
        $parsedResolutionDate = is_string($resolutionDate)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $resolutionDate)
            : false;
        if (!is_string($resolutionDate)
            || $parsedResolutionDate === false
            || $parsedResolutionDate->format('Y-m-d') !== $resolutionDate
            || (!is_int($resolutionNumber) && !(is_string($resolutionNumber) && preg_match('/^\d+$/D', $resolutionNumber) === 1))
            || (int) $resolutionNumber > 999999
        ) {
            throw new SiiException('La fecha o el número de resolución del emisor no son válidos.');
        }

        $fragments = '';
        $counts = [];
        $boletas = null;
        foreach ($dtesFirmados as $dteXml) {
            if (!is_string($dteXml)) {
                throw new SiiException('Cada DTE firmado debe ser una cadena XML.');
            }
            [$type, $issuer, $fragment] = self::dteFragment($dteXml);
            if (self::normalizeRut($issuer) !== self::normalizeRut($configuredIssuer)) {
                throw new SiiException('El emisor de un DTE no coincide con el emisor del sobre.');
            }
            $isBoleta = $type === '39' || $type === '41';
            if ($boletas !== null && $boletas !== $isBoleta) {
                throw new SiiException('Las boletas (39/41) no pueden enviarse en el mismo sobre que otros DTE.');
            }
            $boletas = $isBoleta;
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $fragments .= $fragment;
        }
        if ($boletas === true && (count($dtesFirmados) > 500 || count($counts) > 2)) {
            throw new SiiException('Un sobre de boletas admite hasta 500 boletas y 2 tipos.');
        }
        if ($boletas === false && count($dtesFirmados) > 2000) {
            throw new SiiException('Un sobre EnvioDTE admite hasta 2000 documentos.');
        }

        $rootName = $boletas === true ? 'EnvioBOLETA' : 'EnvioDTE';
        $timestamp ??= new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago'));

        $document = new \DOMDocument('1.0', 'ISO-8859-1');
        $document->formatOutput = false;
        $root = $document->createElementNS(self::SII_NAMESPACE, $rootName);
        self::declareSchema($root, $boletas === true ? 'EnvioBOLETA_v11.xsd' : 'EnvioDTE_v10.xsd');
        $root->setAttribute('version', '1.0');
        $document->appendChild($root);
        $set = $document->createElementNS(self::SII_NAMESPACE, 'SetDTE');
        $set->setAttribute('ID', $boletas === true ? 'SetBoleta' : 'SetDoc');
        $root->appendChild($set);
        $cover = $document->createElementNS(self::SII_NAMESPACE, 'Caratula');
        $cover->setAttribute('version', '1.0');
        $set->appendChild($cover);
        foreach ([
            'RutEmisor' => self::normalizeRut($configuredIssuer),
            'RutEnvia' => self::normalizeRut($senderRut),
            'RutReceptor' => self::normalizeRut($rutReceptorSobre),
            'FchResol' => $resolutionDate,
            'NroResol' => (string) $resolutionNumber,
            'TmstFirmaEnv' => $timestamp->format('Y-m-d\\TH:i:s'),
        ] as $name => $value) {
            $cover->appendChild($document->createElementNS(self::SII_NAMESPACE, $name))
                ->appendChild($document->createTextNode($value));
        }
        ksort($counts, SORT_NUMERIC);
        foreach ($counts as $type => $count) {
            $subtotal = $document->createElementNS(self::SII_NAMESPACE, 'SubTotDTE');
            $cover->appendChild($subtotal);
            $subtotal->appendChild($document->createElementNS(self::SII_NAMESPACE, 'TpoDTE'))
                ->appendChild($document->createTextNode((string) $type));
            $subtotal->appendChild($document->createElementNS(self::SII_NAMESPACE, 'NroDTE'))
                ->appendChild($document->createTextNode((string) $count));
        }

        $xml = $document->saveXML();
        $setEnd = is_string($xml) ? strrpos($xml, '</SetDTE>') : false;
        if (!is_string($xml) || $setEnd === false) {
            throw new SiiException('No se pudo serializar el sobre.');
        }

        return substr_replace($xml, $fragments, $setEnd, 0);
    }

    /**
     * @return array{0: string, 1: string, 2: string} tipo, RUT emisor y fragmento DTE en su codificación original
     */
    private static function dteFragment(string $dteXml): array
    {
        $document = self::loadSafeXml($dteXml);
        $root = $document->documentElement;
        if (!$root instanceof \DOMElement || $root->localName !== 'DTE' || $root->namespaceURI !== self::SII_NAMESPACE) {
            throw new SiiException('El XML firmado debe tener una raíz DTE del SII.');
        }
        $documentNode = self::documentNode($root);
        $header = $documentNode instanceof \DOMElement ? self::directChild($documentNode, 'Encabezado') : null;
        $idDoc = $header instanceof \DOMElement ? self::directChild($header, 'IdDoc') : null;
        $issuerNode = $header instanceof \DOMElement ? self::directChild($header, 'Emisor') : null;
        $type = $idDoc instanceof \DOMElement ? self::directChildText($idDoc, 'TipoDTE') : null;
        $issuer = $issuerNode instanceof \DOMElement ? self::directChildText($issuerNode, 'RUTEmisor') : null;
        if ($type === null || preg_match('/^\d+$/D', $type) !== 1 || $issuer === null || !RutHelper::validate($issuer)) {
            throw new SiiException('El DTE firmado no contiene los datos mínimos de emisor y tipo.');
        }
        $signed = false;
        foreach ($root->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'Signature'
                && $child->namespaceURI === 'http://www.w3.org/2000/09/xmldsig#'
            ) {
                $signed = true;
            }
        }
        if (!$signed) {
            throw new SiiException('El DTE debe estar firmado antes de armar el sobre.');
        }

        $fragment = $document->saveXML($root);
        if ($fragment === false) {
            throw new SiiException('No se pudo conservar el nodo DTE firmado.');
        }
        $encoding = $document->encoding ?: 'UTF-8';
        $encoded = @iconv('UTF-8', $encoding, $fragment);
        if ($encoded === false) {
            throw new SiiException('No se pudo conservar la codificación del DTE firmado en el sobre.');
        }
        $cafPath = ['DTE', $documentNode->localName, 'TED', 'DD', 'CAF'];
        $caf = XmlFragment::extract($dteXml, $cafPath);
        if ($caf !== null) {
            $encoded = XmlFragment::replace($encoded, $cafPath, $caf);
        }

        return [$type, $issuer, $encoded];
    }

    /**
     * El SII elige el XSD con el que valida el sobre a partir de xsi:schemaLocation; sin él responde
     * "SCH-00001: Invalid Schema Name" (STATUS 7 en DTEUpload, HTTP 400 en la API de boletas).
     */
    private static function declareSchema(\DOMElement $root, string $schemaFile): void
    {
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NAMESPACE);
        $root->setAttributeNS(self::XSI_NAMESPACE, 'xsi:schemaLocation', self::SII_NAMESPACE . ' ' . $schemaFile);
    }

    private static function loadSafeXml(string $xml): \DOMDocument
    {
        if ($xml === '' || strlen($xml) > self::MAX_XML_SIZE) {
            throw new SiiException('El XML del DTE está vacío o excede el límite permitido para el sobre.');
        }

        $document = new \DOMDocument();
        $document->preserveWhiteSpace = true;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new SiiException('El DTE firmado no es XML válido o contiene una declaración DTD.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        return $document;
    }

    private static function directChild(\DOMElement $parent, string $localName): ?\DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === self::SII_NAMESPACE
            ) {
                return $child;
            }
        }

        return null;
    }

    private static function documentNode(\DOMElement $root): ?\DOMElement
    {
        foreach (DteTypes::NODOS_DOCUMENTO as $name) {
            $node = self::directChild($root, $name);
            if ($node instanceof \DOMElement) {
                return $node;
            }
        }

        return null;
    }

    private static function directChildText(\DOMElement $parent, string $localName): ?string
    {
        $child = self::directChild($parent, $localName);

        return $child instanceof \DOMElement ? trim($child->textContent) : null;
    }

    /** @param array<string, mixed> $person */
    private static function configuredRut(array $person, string $rutKey, string $dvKey): string
    {
        $rut = $person[$rutKey] ?? null;
        $dv = $person[$dvKey] ?? null;
        if ((!is_string($rut) && !is_int($rut)) || (!is_string($dv) && !is_int($dv))) {
            throw new SiiException('Faltan datos de RUT para construir la carátula.');
        }

        $fullRut = RutHelper::format((string) $rut, (string) $dv);
        if (!RutHelper::validate($fullRut)) {
            throw new SiiException('El RUT indicado para construir la carátula no es válido.');
        }

        return $fullRut;
    }

    private static function normalizeRut(string $rut): string
    {
        $parts = RutHelper::parse($rut);

        return RutHelper::format($parts['rut'], $parts['dv']);
    }
}
