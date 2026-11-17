<?php

declare(strict_types=1);

namespace SiiDte\Document;

use SiiDte\Exceptions\SiiException;

/**
 * Valida DTE y sobres contra los esquemas SII fijados por SHA-256.
 *
 * La validación XSD no verifica firmas, timbres, autenticidad del CAF ni reglas tributarias.
 */
final class SiiSchemaValidator
{
    private const MAX_XML_BYTES = 5242880;

    /** @var array<string, array{directory: string, schema: string, root: string, namespace: string, files: list<string>}> */
    private const PROFILES = [
        'dte' => [
            'directory' => 'dte',
            'schema'    => 'DTE_v10.xsd',
            'root'      => 'DTE',
            'namespace' => 'http://www.sii.cl/SiiDte',
            'files'     => ['DTE_v10.xsd', 'SiiTypes_v10.xsd', 'xmldsignature_v10.xsd'],
        ],
        'envio_dte' => [
            'directory' => 'dte',
            'schema'    => 'EnvioDTE_v10.xsd',
            'root'      => 'EnvioDTE',
            'namespace' => 'http://www.sii.cl/SiiDte',
            'files'     => ['EnvioDTE_v10.xsd', 'DTE_v10.xsd', 'SiiTypes_v10.xsd', 'xmldsignature_v10.xsd'],
        ],
        'envio_boleta' => [
            'directory' => 'envio_bol',
            'schema'    => 'EnvioBOLETA_v11.xsd',
            'root'      => 'EnvioBOLETA',
            'namespace' => 'http://www.sii.cl/SiiDte',
            'files'     => ['EnvioBOLETA_v11.xsd', 'xmldsignature_v10.xsd'],
        ],
    ];

    /** @var array<string, string> */
    private const SCHEMA_SHA256 = [
        'DTE_v10.xsd'           => '85518401b7dddac1bc0b7b99646a1ce353a4afa645344ea5f10af3a2e6676b37',
        'EnvioDTE_v10.xsd'      => '33ea8dd38c895c359dddbcd21feb5acf8a4717f7f67524a6b0dd9a83d76920eb',
        'SiiTypes_v10.xsd'      => 'ce9a84bde70aa9d0f9269d99acd3b0ea81d868022517ac31064b6cdfa0c45bdf',
        'xmldsignature_v10.xsd' => '427e3225cd379ae92bae464b892dbf964665af92d453ac61774cffab38b95edb',
        'EnvioBOLETA_v11.xsd'  => 'e0fbbaf70b0ac8ea2c0ac2310edac41bdd3773cef20fccf6d97e5b92ebeedaa9',
    ];

    private readonly string $schemaDirectory;

    public function __construct(?string $schemaDirectory = null)
    {
        $this->schemaDirectory = $schemaDirectory
            ?? dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'schemas' . DIRECTORY_SEPARATOR . 'sii';
    }

    /** @param string $profile Perfil XSD: dte, envio_dte o envio_boleta. */
    public function validate(string $xml, string $profile): void
    {
        $definition = self::PROFILES[$profile] ?? null;
        if ($definition === null) {
            throw new SiiException('Perfil XSD SII no reconocido.');
        }

        if (strlen($xml) > self::MAX_XML_BYTES) {
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

            if (!$document->loadXML($xml, LIBXML_NONET)) {
                throw new SiiException($this->diagnostic('El XML está mal formado'));
            }

            if ($document->doctype !== null) {
                throw new SiiException('No se permiten declaraciones DTD en el XML.');
            }

            $root = $document->documentElement;
            if ($root === null || $root->localName !== $definition['root'] || $root->namespaceURI !== $definition['namespace']) {
                throw new SiiException('La raíz XML no corresponde al perfil XSD seleccionado.');
            }

            $schemaPath = $this->verifiedSchemaPath($definition);
            if (!$document->schemaValidate($schemaPath, LIBXML_NONET)) {
                throw new SiiException($this->diagnostic('El XML no cumple el esquema XSD SII fijado'));
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /**
     * @param array{directory: string, schema: string, root: string, namespace: string, files: list<string>} $definition
     */
    private function verifiedSchemaPath(array $definition): string
    {
        if (is_link($this->schemaDirectory)) {
            throw new SiiException('Los esquemas XSD SII no están disponibles o su integridad no pudo verificarse.');
        }

        $directory = realpath($this->schemaDirectory);
        if ($directory === false || !is_dir($directory)) {
            throw new SiiException('Los esquemas XSD SII no están disponibles o su integridad no pudo verificarse.');
        }

        $directoryPrefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $profilePath = $directoryPrefix . $definition['directory'];
        if (is_link($profilePath)) {
            throw new SiiException('Los esquemas XSD SII no están disponibles o su integridad no pudo verificarse.');
        }

        $profileDirectory = realpath($profilePath);
        if ($profileDirectory === false || !is_dir($profileDirectory)) {
            throw new SiiException('Los esquemas XSD SII no están disponibles o su integridad no pudo verificarse.');
        }

        $profilePrefix = rtrim($profileDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strncasecmp($profilePrefix, $directoryPrefix, strlen($directoryPrefix)) !== 0) {
            throw new SiiException('Los esquemas XSD SII no están disponibles o su integridad no pudo verificarse.');
        }

        foreach ($definition['files'] as $file) {
            $path = $profilePrefix . $file;
            $realPath = realpath($path);
            $expectedHash = self::SCHEMA_SHA256[$file] ?? null;

            if (
                $expectedHash === null
                || !is_file($path)
                || is_link($path)
                || $realPath === false
                || strncasecmp($realPath, $profilePrefix, strlen($profilePrefix)) !== 0
                || !hash_equals($expectedHash, (string) hash_file('sha256', $realPath))
            ) {
                throw new SiiException('Los esquemas XSD SII no están disponibles o su integridad no pudo verificarse.');
            }
        }

        return $profilePrefix . $definition['schema'];
    }

    private function diagnostic(string $summary): string
    {
        $error = libxml_get_errors()[0] ?? null;
        if (!$error instanceof \LibXMLError) {
            return $summary . '.';
        }

        return sprintf('%s (línea %d, columna %d, código %d).', $summary, $error->line, $error->column, $error->code);
    }
}
