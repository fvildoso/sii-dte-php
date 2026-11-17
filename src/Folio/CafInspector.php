<?php

declare(strict_types=1);

namespace SiiDte\Folio;

use SiiDte\Document\DteTypes;
use SiiDte\Exceptions\SiiException;
use SiiDte\Utils\RutHelper;

/**
 * Inspecciona la estructura y los metadatos de un CAF sin verificar FRMA.
 * FRMA se conserva como parte del CAF y no requiere una llave SII local para preparar un TED.
 */
class CafInspector
{
    /**
     * Inspecciona un CAF y compara emisor y tipo con los valores esperados.
     *
     * @return array{
     *     rut_emisor: string,
     *     razon_social_autorizacion: string,
     *     tipo_dte: int,
     *     folio_desde: int,
     *     folio_hasta: int,
     *     fecha_autorizacion: string,
     *     fecha_vencimiento_calculada: string|null,
     *     vigencia: 'vigente'|'vencido'|'indeterminada',
     *     idk_sii: string,
     *     algoritmo_frma: string,
     *     firma_sii: 'no_verificada',
     *     llave_publica_coincide: true,
     *     llave_timbrado_corresponde: bool|null,
     *     ambiente_demostrado: false,
     *     emision_habilitada: false,
     *     bloqueos: list<string>
     * }
     */
    public function inspect(
        string $cafXml,
        string $expectedRut,
        int $expectedType,
        ?string $asOfDate = null,
    ): array {
        if (strlen($cafXml) > 2_000_000) {
            throw new SiiException('El CAF supera el tamaño máximo admitido para inspección.');
        }

        if (
            str_contains($cafXml, "\0")
            || stripos($cafXml, '<!DOCTYPE') !== false
            || stripos($cafXml, '<!ENTITY') !== false
        ) {
            throw new SiiException('El CAF no puede contener DTD ni declaraciones de entidades.');
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $document = new \DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $document->validateOnParse = false;

        try {
            $loaded = $document->loadXML($cafXml, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT);
            if (!$loaded || $document->doctype !== null || !$document->documentElement instanceof \DOMElement) {
                throw new SiiException('El CAF no tiene una estructura XML segura y válida.');
            }

            $authorization = $document->documentElement;
            if ($authorization->localName !== 'AUTORIZACION' || $authorization->namespaceURI !== null) {
                throw new SiiException('La raíz del CAF debe ser AUTORIZACION sin namespace.');
            }

            $caf = $this->requiredChild($authorization, 'CAF');
            $data = $this->requiredChild($caf, 'DA');
            $issuerRut = $this->normalizeRut($this->requiredText($data, 'RE'), 'RUT emisor del CAF');
            $authorizedName = $this->requiredText($data, 'RS');
            $expectedRut = $this->normalizeRut($expectedRut, 'RUT esperado');
            if ($issuerRut !== $expectedRut) {
                throw new SiiException('El RUT emisor del CAF no coincide con el emisor esperado.');
            }

            $type = $this->positiveInteger($this->requiredText($data, 'TD'), 'Tipo DTE');
            if (!in_array($type, DteTypes::todos(), true)) {
                throw new SiiException("El CAF declara un tipo DTE no anunciado por la biblioteca ({$type}).");
            }
            if ($type !== $expectedType) {
                throw new SiiException('El tipo DTE del CAF no coincide con el tipo esperado.');
            }

            $range = $this->requiredChild($data, 'RNG');
            $start = $this->positiveInteger($this->requiredText($range, 'D'), 'Folio inicial');
            $end = $this->positiveInteger($this->requiredText($range, 'H'), 'Folio final');
            if ($start > $end) {
                throw new SiiException('El rango de folios del CAF es inválido.');
            }

            $authorizationDate = $this->parseDate($this->requiredText($data, 'FA'), 'FA');
            $today = $this->parseDate($asOfDate ?? gmdate('Y-m-d'), 'fecha de inspección');
            if ($authorizationDate > $today) {
                throw new SiiException('La fecha FA del CAF es posterior a la fecha de inspección.');
            }

            $expirationDate = in_array($type, [33, 43, 46, 56, 61], true)
                ? $this->expirationDate($authorizationDate)
                : null;
            $cafPublicKey = $this->requiredChild($data, 'RSAPK');
            $modulus = $this->decodeBase64($this->requiredText($cafPublicKey, 'M'), 'módulo RSA del CAF');
            $exponent = $this->decodeBase64($this->requiredText($cafPublicKey, 'E'), 'exponente RSA del CAF');
            $publicKeyMatches = $this->publicKeyMatchesCafPublicKey($authorization, $modulus, $exponent);
            if (!$publicKeyMatches) {
                throw new SiiException('La llave pública RSAPUBK no coincide con RSAPK del CAF.');
            }
            $keyId = $this->requiredText($data, 'IDK');
            if (!preg_match('/^\d+$/', $keyId)) {
                throw new SiiException('El identificador IDK del CAF no es válido.');
            }

            $signature = $this->requiredChild($caf, 'FRMA');
            $this->decodeBase64(trim($signature->textContent), 'firma FRMA del CAF');
            $signatureAlgorithm = trim($signature->getAttribute('algoritmo'));
            if ($signatureAlgorithm === '') {
                throw new SiiException('La firma FRMA del CAF no declara su algoritmo.');
            }

            $keyMatches = $this->privateKeyMatchesPublicKey(
                $authorization,
                $modulus,
                $exponent
            );

            return [
                'rut_emisor'                   => $issuerRut,
                'razon_social_autorizacion'    => $authorizedName,
                'tipo_dte'                     => $type,
                'folio_desde'                  => $start,
                'folio_hasta'                  => $end,
                'fecha_autorizacion'           => $authorizationDate->format('Y-m-d'),
                'fecha_vencimiento_calculada'  => $expirationDate?->format('Y-m-d'),
                'vigencia'                     => $this->validity($today, $expirationDate),
                'idk_sii'                      => $keyId,
                'algoritmo_frma'               => $signatureAlgorithm,
                'firma_sii'                    => 'no_verificada',
                'llave_publica_coincide'       => true,
                'llave_timbrado_corresponde'    => $keyMatches,
                'ambiente_demostrado'          => false,
                'emision_habilitada'           => false,
                'bloqueos'                     => [
                    'La inspección local no autentica el origen del CAF ni la autorización efectiva del firmante.',
                    'El CAF no demuestra por sí solo si procede de certificación o producción.',
                    'La autorización efectiva del firmante y la revocación del certificado no se comprobaron.',
                    ...($expirationDate === null
                        ? ['La regla de vigencia de CAF consultada no está documentada para este tipo DTE.']
                        : ($today == $expirationDate
                            ? ['La fuente oficial no precisa si el día límite de vigencia se incluye; la vigencia en esa fecha es indeterminada.']
                            : [])),
                ],
            ];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /**
     * Inspecciona un CAF y confirma que un folio reservado externamente está en su rango.
     *
     * Esta verificación no confirma autenticidad del CAF ni autoriza la emisión.
     *
     * @return array<string, mixed> Inspección del CAF más el folio comprobado
     */
    public function validateFolio(
        string $cafXml,
        string $expectedRut,
        int $expectedType,
        int $folio,
        ?string $asOfDate = null,
    ): array {
        if ($folio <= 0) {
            throw new SiiException('El folio reservado externamente debe ser un entero positivo.');
        }

        $inspection = $this->inspect($cafXml, $expectedRut, $expectedType, $asOfDate);
        if ($folio < $inspection['folio_desde'] || $folio > $inspection['folio_hasta']) {
            throw new SiiException('El folio reservado externamente está fuera del rango del CAF.');
        }

        $inspection['folio_verificado'] = $folio;

        return $inspection;
    }

    private function requiredChild(\DOMElement $parent, string $name): \DOMElement
    {
        $matches = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                if ($child->namespaceURI !== null) {
                    throw new SiiException("El elemento {$name} del CAF no debe usar namespace.");
                }
                $matches[] = $child;
            }
        }

        if (count($matches) !== 1) {
            throw new SiiException("El CAF debe contener exactamente un elemento {$name} en su ubicación esperada.");
        }

        return $matches[0];
    }

    private function requiredText(\DOMElement $parent, string $name): string
    {
        $value = trim($this->requiredChild($parent, $name)->textContent);
        if ($value === '') {
            throw new SiiException("El elemento {$name} del CAF está vacío.");
        }

        return $value;
    }

    private function normalizeRut(string $rut, string $field): string
    {
        if (!RutHelper::validate($rut)) {
            throw new SiiException("{$field} no es un RUT válido.");
        }

        $parts = RutHelper::parse($rut);

        return $parts['rut'] . '-' . $parts['dv'];
    }

    private function positiveInteger(string $value, string $field): int
    {
        if (!preg_match('/^[1-9]\d*$/', $value)) {
            throw new SiiException("{$field} debe ser un entero positivo.");
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);
        if ($integer === false || $integer <= 0) {
            throw new SiiException("{$field} excede el rango entero admitido.");
        }

        return $integer;
    }

    private function parseDate(string $value, string $field): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (
            $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new SiiException("La fecha {$field} del CAF debe tener formato AAAA-MM-DD válido.");
        }

        return $date;
    }

    private function expirationDate(\DateTimeImmutable $authorizationDate): \DateTimeImmutable
    {
        $cutoff = new \DateTimeImmutable('2018-07-01', new \DateTimeZone('UTC'));
        if ($authorizationDate < $cutoff) {
            $legacyExpiration = $this->addCalendarMonths($authorizationDate, 18);
            $maximumExpiration = new \DateTimeImmutable('2018-12-31', new \DateTimeZone('UTC'));

            return min($legacyExpiration, $maximumExpiration);
        }

        return $this->addCalendarMonths($authorizationDate, 6);
    }

    /**
     * @return 'vigente'|'vencido'|'indeterminada'
     */
    private function validity(\DateTimeImmutable $asOfDate, ?\DateTimeImmutable $expirationDate): string
    {
        if ($expirationDate === null || $asOfDate == $expirationDate) {
            return 'indeterminada';
        }

        return $asOfDate < $expirationDate ? 'vigente' : 'vencido';
    }

    private function addCalendarMonths(\DateTimeImmutable $date, int $months): \DateTimeImmutable
    {
        $year = (int) $date->format('Y');
        $month = (int) $date->format('n') + $months;
        $day = (int) $date->format('j');
        $year += intdiv($month - 1, 12);
        $month = (($month - 1) % 12) + 1;

        $lastDay = (int) new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month))->format('t');

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, min($day, $lastDay)), new \DateTimeZone('UTC'));
    }

    private function decodeBase64(string $value, string $field): string
    {
        $normalized = preg_replace('/[\x09\x0A\x0D\x20]+/', '', $value);
        $decoded = $normalized === null ? false : base64_decode($normalized, true);
        if ($decoded === false || $decoded === '') {
            throw new SiiException("El campo {$field} del CAF no contiene Base64 válido.");
        }

        return $decoded;
    }

    private function privateKeyMatchesPublicKey(\DOMElement $authorization, string $modulus, string $exponent): ?bool
    {
        $privateKeyNode = null;
        foreach ($authorization->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'RSASK') {
                if ($privateKeyNode !== null || $child->namespaceURI !== null) {
                    return false;
                }
                $privateKeyNode = $child;
            }
        }

        if ($privateKeyNode === null) {
            return null;
        }

        $privateKey = @openssl_pkey_get_private(trim($privateKeyNode->textContent));
        if ($privateKey === false) {
            return false;
        }

        $details = openssl_pkey_get_details($privateKey);
        if (
            $details === false
            || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || !isset($details['rsa']['n'], $details['rsa']['e'])
        ) {
            return false;
        }

        return $this->unsignedBinary($details['rsa']['n']) === $this->unsignedBinary($modulus)
            && $this->unsignedBinary($details['rsa']['e']) === $this->unsignedBinary($exponent);
    }

    private function publicKeyMatchesCafPublicKey(\DOMElement $authorization, string $modulus, string $exponent): bool
    {
        $publicKeyNode = $this->requiredChild($authorization, 'RSAPUBK');
        $publicKey = @openssl_pkey_get_public(trim($publicKeyNode->textContent));
        if ($publicKey === false) {
            return false;
        }

        $details = openssl_pkey_get_details($publicKey);
        if (
            $details === false
            || $details['type'] !== OPENSSL_KEYTYPE_RSA
            || !isset($details['rsa']['n'], $details['rsa']['e'])
        ) {
            return false;
        }

        return $this->unsignedBinary($details['rsa']['n']) === $this->unsignedBinary($modulus)
            && $this->unsignedBinary($details['rsa']['e']) === $this->unsignedBinary($exponent);
    }

    private function unsignedBinary(string $value): string
    {
        return ltrim($value, "\0");
    }
}
