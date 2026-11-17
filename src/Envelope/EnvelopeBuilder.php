<?php

declare(strict_types=1);

namespace SiiDte\Envelope;

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

        $documentNode = self::directChild($dteRoot, 'Documento');
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
        $caf = XmlFragment::extract($dteFirmadoXml, ['DTE', 'Documento', 'TED', 'DD', 'CAF']);

        $encoding = $dteDocument->encoding ?: 'UTF-8';
        // DOMDocument::saveXML($node) devuelve UTF-8 incluso cuando el documento declara otra codificación.
        $encodedFragment = @iconv('UTF-8', $encoding, $dteFragment);
        if ($encodedFragment === false) {
            throw new SiiException('No se pudo conservar la codificación del DTE firmado en el sobre.');
        }
        if ($caf !== null) {
            $encodedFragment = XmlFragment::replace(
                $encodedFragment,
                ['DTE', 'Documento', 'TED', 'DD', 'CAF'],
                $caf
            );
        }

        $envelopeDocument = new \DOMDocument('1.0', $encoding);
        $envelopeDocument->preserveWhiteSpace = true;
        $envelopeDocument->formatOutput = false;
        $root = $envelopeDocument->createElementNS(self::SII_NAMESPACE, 'EnvioDTE');
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
