<?php

declare(strict_types=1);

namespace SiiDte\Document;

use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\CafInspector;
use SiiDte\Utils\RutHelper;
use SiiDte\Utils\XmlFragment;

/**
 * Construye y verifica localmente el TED de una factura electrónica tipo 33.
 * Copia el CAF suministrado sin modificar sus bytes léxicos; FRMA no se verifica localmente.
 */
final class TedBuilder
{
    private const MAX_XML_SIZE = 5 * 1024 * 1024;
    private const SII_NAMESPACE = 'http://www.sii.cl/SiiDte';

    public function __construct(private readonly CafInspector $cafInspector)
    {
    }

    /**
     * Agrega TED y TmstFirma a un DTE 33 usando el folio reservado por el consumidor.
     *
     * @throws SiiException Si el CAF, el DTE, el folio o las llaves no son consistentes
     */
    public function addTedToDte(
        string $dteXml,
        string $authorizationXml,
        int $folio,
        string $tedTimestamp,
        string $documentSignatureTimestamp,
    ): string {
        $document = $this->loadSafeXml($dteXml, 'El XML del DTE es inválido.');
        $root = $document->documentElement;
        if (!$root instanceof \DOMElement
            || $root->localName !== 'DTE'
            || $root->namespaceURI !== self::SII_NAMESPACE
        ) {
            throw new SiiException('La raíz XML no corresponde a un DTE del SII.');
        }

        $documentNode = $this->requiredChild($root, 'Documento', self::SII_NAMESPACE);
        if ($this->hasDirectChildNamed($documentNode, 'TED')
            || $this->hasDirectChildNamed($documentNode, 'TmstFirma')
            || $this->hasDescendantNamed($root, 'Signature')
        ) {
            throw new SiiException('El DTE ya contiene TED, TmstFirma o XMLDSig; no se puede volver a timbrar.');
        }

        $fields = $this->dteFields($documentNode);
        if ($fields['tipo'] !== 33) {
            throw new SiiException('TedBuilder solo implementa localmente el DTE tipo 33.');
        }
        if ($folio <= 0 || $fields['folio'] !== $folio) {
            throw new SiiException('El folio externo no coincide con el folio del DTE.');
        }

        $tedTime = $this->parseTimestamp($tedTimestamp, 'TSTED');
        $signatureTime = $this->parseTimestamp($documentSignatureTimestamp, 'TmstFirma');
        if ($signatureTime < $tedTime) {
            throw new SiiException('TmstFirma no puede ser anterior a TSTED.');
        }

        $inspection = $this->cafInspector->validateFolio(
            $authorizationXml,
            $fields['rut_emisor'],
            33,
            $folio,
            $fields['fecha_emision']
        );
        if (($inspection['llave_timbrado_corresponde'] ?? false) !== true) {
            throw new SiiException('La llave privada de timbrado no corresponde a la llave pública del CAF.');
        }
        if (($inspection['vigencia'] ?? 'indeterminada') !== 'vigente') {
            throw new SiiException('La vigencia local del CAF no está confirmada para la fecha del DTE.');
        }

        $authorization = $this->loadSafeXml($authorizationXml, 'El archivo CAF es inválido.');
        $authorizationRoot = $authorization->documentElement;
        if (!$authorizationRoot instanceof \DOMElement || $authorizationRoot->localName !== 'AUTORIZACION') {
            throw new SiiException('La raíz del archivo CAF debe ser AUTORIZACION.');
        }
        $cafNode = $this->requiredChild($authorizationRoot, 'CAF', null);
        $rawCaf = XmlFragment::extract($authorizationXml, ['AUTORIZACION', 'CAF']);
        if ($rawCaf === null) {
            throw new SiiException('No se pudo conservar el fragmento CAF original.');
        }
        $this->assertRawCafEncoding($authorizationXml, $rawCaf);
        $privateKeyPem = trim($this->requiredChild($authorizationRoot, 'RSASK', null)->textContent);
        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            throw new SiiException('No se pudo utilizar la llave privada de timbrado del CAF.');
        }

        $this->assertLatin1Representable($document);
        $this->assertLatin1Representable($authorization);
        $signaturePayload = $this->buildSignaturePayload($fields, $cafNode, $tedTimestamp, $rawCaf);
        $signature = '';
        if (!openssl_sign($signaturePayload, $signature, $privateKey, OPENSSL_ALGO_SHA1)) {
            throw new SiiException('No se pudo generar la firma FRMT del TED.');
        }

        $ted = $document->createElementNS(self::SII_NAMESPACE, 'TED');
        $ted->setAttribute('version', '1.0');
        $dd = $this->cloneElement($document, $this->signatureDd($fields, $cafNode, $tedTimestamp), self::SII_NAMESPACE);
        $ted->appendChild($dd);

        $frmt = $document->createElementNS(self::SII_NAMESPACE, 'FRMT', base64_encode($signature));
        $frmt->setAttribute('algoritmo', 'SHA1withRSA');
        $ted->appendChild($frmt);
        $documentNode->appendChild($ted);
        $documentNode->appendChild($document->createElementNS(self::SII_NAMESPACE, 'TmstFirma', $documentSignatureTimestamp));

        $document->encoding = 'ISO-8859-1';
        $xml = $document->saveXML();
        if (!is_string($xml)) {
            throw new SiiException('No se pudo serializar el DTE con su timbre.');
        }

        if (!str_starts_with($xml, '<?xml')) {
            $xml = '<?xml version="1.0" encoding="ISO-8859-1"?>' . $xml;
        }

        return XmlFragment::replace($xml, ['DTE', 'Documento', 'TED', 'DD', 'CAF'], $rawCaf);
    }

    /**
     * Verifica localmente que FRMT sea válida y que DD coincida con los datos del DTE.
     *
     * @return array{
     *     frmt_verificada: bool,
     *     datos_coinciden: bool,
     *     llave_timbrado_corresponde: bool,
     *     frma_verificada: false,
     *     emision_habilitada: false,
     *     bloqueos: list<string>
     * }
     */
    public function verify(string $dteXml): array
    {
        $document = $this->loadSafeXml($dteXml, 'El XML del DTE es inválido.');
        $root = $document->documentElement;
        if (!$root instanceof \DOMElement
            || $root->localName !== 'DTE'
            || $root->namespaceURI !== self::SII_NAMESPACE
        ) {
            throw new SiiException('La raíz XML no corresponde a un DTE del SII.');
        }

        $documentNode = $this->requiredChild($root, 'Documento', self::SII_NAMESPACE);
        $fields = $this->dteFields($documentNode);
        $ted = $this->requiredChild($documentNode, 'TED', self::SII_NAMESPACE);
        $dd = $this->requiredChild($ted, 'DD', self::SII_NAMESPACE);
        $frmt = $this->requiredChild($ted, 'FRMT', self::SII_NAMESPACE);
        if ($fields['tipo'] !== 33 || $ted->getAttribute('version') !== '1.0'
            || $frmt->getAttribute('algoritmo') !== 'SHA1withRSA'
        ) {
            throw new SiiException('El TED no corresponde al formato local implementado para factura 33.');
        }

        $ddTime = $this->requiredChild($dd, 'TSTED', self::SII_NAMESPACE)->textContent;
        $tedTime = $this->parseTimestamp($ddTime, 'TSTED');
        $signatureTime = $this->parseTimestamp(
            $this->requiredChild($documentNode, 'TmstFirma', self::SII_NAMESPACE)->textContent,
            'TmstFirma'
        );
        if ($signatureTime < $tedTime) {
            throw new SiiException('TmstFirma no puede ser anterior a TSTED.');
        }

        $cafNode = $this->requiredChild($dd, 'CAF', self::SII_NAMESPACE);
        $rawCaf = XmlFragment::extract($dteXml, ['DTE', 'Documento', 'TED', 'DD', 'CAF']);
        if ($rawCaf === null) {
            throw new SiiException('No se pudo leer el fragmento CAF original del TED.');
        }
        $cafFields = $this->cafFields($cafNode);
        $keyMatches = $cafFields['rut_emisor'] === $fields['rut_emisor']
            && $cafFields['tipo'] === $fields['tipo']
            && $fields['folio'] >= $cafFields['desde']
            && $fields['folio'] <= $cafFields['hasta']
            && $cafFields['fecha_autorizacion'] <= $fields['fecha_emision'];

        $expectedPayload = $this->buildSignaturePayload($fields, $cafNode, $ddTime, $rawCaf);
        $actualDd = $this->cloneElement(new \DOMDocument('1.0', 'ISO-8859-1'), $dd, null);
        $actualPayload = XmlFragment::replace($this->serializeNode($actualDd), ['DD', 'CAF'], $rawCaf);
        $dataMatches = hash_equals($expectedPayload, $actualPayload) && $keyMatches;

        $publicKey = $this->publicKeyFromCaf($cafNode);
        $signature = $this->decodeBase64($frmt->textContent, 'FRMT');
        $frmtVerified = openssl_verify($actualPayload, $signature, $publicKey, OPENSSL_ALGO_SHA1) === 1;

        return [
            'frmt_verificada'             => $frmtVerified,
            'datos_coinciden'             => $dataMatches,
            'llave_timbrado_corresponde'  => $keyMatches && $frmtVerified,
            'frma_verificada'             => false,
            'emision_habilitada'          => false,
            'bloqueos'                    => ['La preparación completa y la aceptación del flujo de certificación no se comprueban aquí.'],
        ];
    }

    /**
     * @param array{
     *     tipo: int,
     *     folio: int,
     *     fecha_emision: string,
     *     rut_emisor: string,
     *     rut_receptor: string,
     *     razon_social_receptor: string,
     *     monto_total: string,
     *     primer_item: string
     * } $fields
     */
    private function buildSignaturePayload(
        array $fields,
        \DOMElement $caf,
        string $timestamp,
        string $rawCaf,
    ): string {
        return XmlFragment::replace(
            $this->serializeNode($this->signatureDd($fields, $caf, $timestamp)),
            ['DD', 'CAF'],
            $rawCaf
        );
    }

    /**
     * @param array{
     *     tipo: int,
     *     folio: int,
     *     fecha_emision: string,
     *     rut_emisor: string,
     *     rut_receptor: string,
     *     razon_social_receptor: string,
     *     monto_total: string,
     *     primer_item: string
     * } $fields
     */
    private function signatureDd(array $fields, \DOMElement $caf, string $timestamp): \DOMElement
    {
        $document = new \DOMDocument('1.0', 'ISO-8859-1');
        $document->preserveWhiteSpace = false;
        $document->formatOutput = false;
        $dd = $document->createElement('DD');
        $document->appendChild($dd);

        foreach ([
            'RE'  => $fields['rut_emisor'],
            'TD'  => (string) $fields['tipo'],
            'F'   => (string) $fields['folio'],
            'FE'  => $fields['fecha_emision'],
            'RR'  => $fields['rut_receptor'],
            'RSR' => $fields['razon_social_receptor'],
            'MNT' => $fields['monto_total'],
            'IT1' => $fields['primer_item'],
        ] as $name => $value) {
            $dd->appendChild($document->createElement($name))->appendChild($document->createTextNode($value));
        }

        $dd->appendChild($this->cloneElement($document, $caf, null));
        $dd->appendChild($document->createElement('TSTED', $timestamp));

        return $dd;
    }

    /**
     * @return array{
     *     tipo: int,
     *     folio: int,
     *     fecha_emision: string,
     *     rut_emisor: string,
     *     rut_receptor: string,
     *     razon_social_receptor: string,
     *     monto_total: string,
     *     primer_item: string
     * }
     */
    private function dteFields(\DOMElement $documentNode): array
    {
        $header = $this->requiredChild($documentNode, 'Encabezado', self::SII_NAMESPACE);
        $id = $this->requiredChild($header, 'IdDoc', self::SII_NAMESPACE);
        $issuer = $this->requiredChild($header, 'Emisor', self::SII_NAMESPACE);
        $recipient = $this->requiredChild($header, 'Receptor', self::SII_NAMESPACE);
        $totals = $this->requiredChild($header, 'Totales', self::SII_NAMESPACE);

        $type = $this->positiveInteger($this->requiredText($id, 'TipoDTE', self::SII_NAMESPACE), 'TipoDTE');
        $folio = $this->positiveInteger($this->requiredText($id, 'Folio', self::SII_NAMESPACE), 'Folio');
        $issueDate = $this->requiredText($id, 'FchEmis', self::SII_NAMESPACE);
        $this->parseDate($issueDate);
        $issuerRut = $this->normalizeRut($this->requiredText($issuer, 'RUTEmisor', self::SII_NAMESPACE));
        $recipientRut = $this->normalizeRut($this->requiredText($recipient, 'RUTRecep', self::SII_NAMESPACE));
        $recipientName = $this->requiredText($recipient, 'RznSocRecep', self::SII_NAMESPACE, false);
        $this->assertMaxCharacters($recipientName, 40, 'RznSocRecep');
        $total = $this->unsignedLong($this->requiredText($totals, 'MntTotal', self::SII_NAMESPACE), 'MntTotal');

        $details = $this->directChildren($documentNode, 'Detalle', self::SII_NAMESPACE);
        if ($details === []) {
            throw new SiiException('El DTE debe contener al menos un detalle para generar IT1.');
        }
        $firstItem = $this->requiredText($details[0], 'NmbItem', self::SII_NAMESPACE, false);
        $this->assertMaxCharacters($firstItem, 40, 'IT1');

        return [
            'tipo'                    => $type,
            'folio'                   => $folio,
            'fecha_emision'           => $issueDate,
            'rut_emisor'              => $issuerRut,
            'rut_receptor'            => $recipientRut,
            'razon_social_receptor'   => $recipientName,
            'monto_total'             => $total,
            'primer_item'             => $firstItem,
        ];
    }

    /** @return array{rut_emisor: string, tipo: int, desde: int, hasta: int, fecha_autorizacion: string} */
    private function cafFields(\DOMElement $caf): array
    {
        if ($caf->getAttribute('version') !== '1.0') {
            throw new SiiException('La versión del CAF incluido en el TED no es compatible.');
        }
        $da = $this->requiredChild($caf, 'DA', self::SII_NAMESPACE);
        $issuerRut = $this->normalizeRut($this->requiredText($da, 'RE', self::SII_NAMESPACE));
        $this->requiredText($da, 'RS', self::SII_NAMESPACE);
        $type = $this->positiveInteger($this->requiredText($da, 'TD', self::SII_NAMESPACE), 'CAF TD');
        $authorizationDate = $this->requiredText($da, 'FA', self::SII_NAMESPACE);
        $this->parseDate($authorizationDate);
        $range = $this->requiredChild($da, 'RNG', self::SII_NAMESPACE);
        $start = $this->positiveInteger($this->requiredText($range, 'D', self::SII_NAMESPACE), 'CAF D');
        $end = $this->positiveInteger($this->requiredText($range, 'H', self::SII_NAMESPACE), 'CAF H');
        if ($end < $start) {
            throw new SiiException('El rango de folios del CAF es inválido.');
        }
        $frma = $this->requiredChild($caf, 'FRMA', self::SII_NAMESPACE);
        if ($frma->getAttribute('algoritmo') !== 'SHA1withRSA') {
            throw new SiiException('El CAF declara un algoritmo FRMA distinto al formato local esperado.');
        }
        $this->decodeBase64($frma->textContent, 'FRMA');
        if (!preg_match('/^\d+$/', $this->requiredText($da, 'IDK', self::SII_NAMESPACE))) {
            throw new SiiException('El IDK del CAF no tiene un formato válido.');
        }
        $rsa = $this->requiredChild($da, 'RSAPK', self::SII_NAMESPACE);
        $this->requiredChild($rsa, 'M', self::SII_NAMESPACE);
        $this->requiredChild($rsa, 'E', self::SII_NAMESPACE);

        return [
            'rut_emisor'         => $issuerRut,
            'tipo'               => $type,
            'desde'              => $start,
            'hasta'              => $end,
            'fecha_autorizacion' => $authorizationDate,
        ];
    }

    private function publicKeyFromCaf(\DOMElement $caf): \OpenSSLAsymmetricKey
    {
        $da = $this->requiredChild($caf, 'DA', self::SII_NAMESPACE);
        $rsa = $this->requiredChild($da, 'RSAPK', self::SII_NAMESPACE);
        $modulus = $this->decodeBase64($this->requiredText($rsa, 'M', self::SII_NAMESPACE), 'RSAPK M');
        $exponent = $this->decodeBase64($this->requiredText($rsa, 'E', self::SII_NAMESPACE), 'RSAPK E');
        if ($modulus === '' || $exponent === '') {
            throw new SiiException('La llave pública RSA del CAF está incompleta.');
        }

        $rsaKey = $this->derSequence($this->derInteger($modulus) . $this->derInteger($exponent));
        $pem = "-----BEGIN RSA PUBLIC KEY-----\n"
            . chunk_split(base64_encode($rsaKey), 64, "\n")
            . "-----END RSA PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if ($key === false) {
            throw new SiiException('La llave pública RSA del CAF no es utilizable.');
        }

        return $key;
    }

    private function cloneElement(\DOMDocument $targetDocument, \DOMElement $source, ?string $namespace): \DOMElement
    {
        $qualifiedName = $source->localName;
        $clone = $namespace === null
            ? $targetDocument->createElement($qualifiedName)
            : $targetDocument->createElementNS($namespace, $qualifiedName);

        foreach ($source->attributes as $attribute) {
            if (!$attribute->namespaceURI) {
                $clone->setAttribute($attribute->name, $attribute->value);
            }
        }

        foreach ($source->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $clone->appendChild($this->cloneElement($targetDocument, $child, $namespace));
            } elseif ($child instanceof \DOMText) {
                if (preg_match('/^\s*$/u', $child->textContent) === 1) {
                    continue;
                }
                $clone->appendChild($targetDocument->createTextNode($child->textContent));
            }
        }

        return $clone;
    }

    private function serializeNode(\DOMElement $node): string
    {
        $xml = $node->ownerDocument?->saveXML($node);
        if (!is_string($xml)) {
            throw new SiiException('No se pudo serializar el DD para la firma del TED.');
        }

        return $xml;
    }

    private function assertRawCafEncoding(string $authorizationXml, string $rawCaf): void
    {
        if (preg_match('/[\x80-\xFF]/', $rawCaf) !== 1) {
            return;
        }

        if (preg_match('/<\?xml\b[^>]*\bencoding\s*=\s*["\']([^"\']+)["\']/i', $authorizationXml, $match) !== 1
            || strtoupper($match[1]) !== 'ISO-8859-1'
        ) {
            throw new SiiException(
                'El CAF contiene bytes no ASCII que no pueden copiarse sin cambio a la codificación ISO-8859-1 del DTE.'
            );
        }
    }

    private function loadSafeXml(string $xml, string $errorMessage): \DOMDocument
    {
        if (strlen($xml) > self::MAX_XML_SIZE) {
            throw new SiiException('El XML supera el límite local de 5 MiB.');
        }
        if (preg_match('/<!\s*DOCTYPE\b/i', $xml) === 1) {
            throw new SiiException('No se permiten declaraciones DTD en el XML.');
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new \DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($xml, LIBXML_NONET) || $document->doctype !== null) {
                throw new SiiException($errorMessage);
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    private function assertLatin1Representable(\DOMDocument $document): void
    {
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//@* | //text()') ?: [] as $node) {
            if (@iconv('UTF-8', 'ISO-8859-1', $node->nodeValue ?? '') === false) {
                throw new SiiException('El XML contiene caracteres que no se pueden representar en ISO-8859-1.');
            }
        }
    }

    private function requiredChild(\DOMElement $parent, string $name, ?string $namespace): \DOMElement
    {
        $matches = $this->directChildren($parent, $name, $namespace);
        if (count($matches) !== 1) {
            throw new SiiException("El XML debe contener exactamente un elemento {$name} en su ubicación esperada.");
        }

        return $matches[0];
    }

    /** @return list<\DOMElement> */
    private function directChildren(\DOMElement $parent, string $name, ?string $namespace): array
    {
        $matches = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                if ($child->namespaceURI !== $namespace) {
                    throw new SiiException("El elemento {$name} tiene un namespace inesperado.");
                }
                $matches[] = $child;
            }
        }

        return $matches;
    }

    private function hasDirectChildNamed(\DOMElement $parent, string $name): bool
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                return true;
            }
        }

        return false;
    }

    private function hasDescendantNamed(\DOMElement $parent, string $name): bool
    {
        foreach ($parent->getElementsByTagName('*') as $element) {
            if ($element->localName === $name) {
                return true;
            }
        }

        return false;
    }

    private function requiredText(\DOMElement $parent, string $name, ?string $namespace, bool $trim = true): string
    {
        $value = $this->requiredChild($parent, $name, $namespace)->textContent;
        if (trim($value) === '') {
            throw new SiiException("El elemento {$name} del XML está vacío.");
        }

        return $trim ? trim($value) : $value;
    }

    private function normalizeRut(string $rut): string
    {
        $clean = preg_replace('/[.\s]/', '', strtoupper(trim($rut)));
        if ($clean === null || !RutHelper::validate($clean)) {
            throw new SiiException('El XML contiene un RUT inválido.');
        }
        $parts = RutHelper::parse($clean);

        return $parts['rut'] . '-' . $parts['dv'];
    }

    private function positiveInteger(string $value, string $field): int
    {
        if (!preg_match('/^[1-9]\d*$/', $value)) {
            throw new SiiException("El campo {$field} debe ser un entero positivo.");
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer <= 0) {
            throw new SiiException("El campo {$field} excede el rango entero admitido.");
        }

        return $integer;
    }

    private function unsignedLong(string $value, string $field): string
    {
        $maximum = '18446744073709551615';
        if (!preg_match('/^(0|[1-9]\d*)$/', $value)
            || strlen($value) > strlen($maximum)
            || (strlen($value) === strlen($maximum) && strcmp($value, $maximum) > 0)
        ) {
            throw new SiiException("El campo {$field} no es un xs:unsignedLong válido.");
        }

        return $value;
    }

    private function assertMaxCharacters(string $value, int $maximum, string $field): void
    {
        $characters = preg_match_all('/./us', $value);
        if ($characters === false || $characters < 1 || $characters > $maximum) {
            throw new SiiException("El campo {$field} debe contener entre 1 y {$maximum} caracteres.");
        }
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new SiiException('FchEmis debe ser una fecha válida en formato AAAA-MM-DD.');
        }

        return $date;
    }

    private function parseTimestamp(string $value, string $field): \DateTimeImmutable
    {
        $dateTime = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($dateTime === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $dateTime->format('Y-m-d\TH:i:s') !== $value
        ) {
            throw new SiiException("{$field} debe tener formato válido AAAA-MM-DDTHH:MM:SS.");
        }

        return $dateTime;
    }

    private function decodeBase64(string $value, string $field): string
    {
        $normalized = preg_replace('/[\x09\x0A\x0D\x20]+/', '', $value);
        $decoded = $normalized === null ? false : base64_decode($normalized, true);
        if ($decoded === false) {
            throw new SiiException("El campo {$field} no contiene Base64 válido.");
        }

        return $decoded;
    }

    private function derInteger(string $integer): string
    {
        $integer = ltrim($integer, "\0");
        if ($integer === '') {
            $integer = "\0";
        }
        if ((ord($integer[0]) & 0x80) !== 0) {
            $integer = "\0" . $integer;
        }

        return "\x02" . $this->derLength(strlen($integer)) . $integer;
    }

    private function derSequence(string $value): string
    {
        return "\x30" . $this->derLength(strlen($value)) . $value;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $encoded = '';
        while ($length > 0) {
            $encoded = chr($length & 0xff) . $encoded;
            $length >>= 8;
        }

        return chr(0x80 | strlen($encoded)) . $encoded;
    }
}
