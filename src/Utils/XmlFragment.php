<?php

declare(strict_types=1);

namespace SiiDte\Utils;

use SiiDte\Exceptions\SiiException;

/**
 * Extrae y reemplaza elementos XML conservando exactamente sus bytes léxicos.
 * Los llamadores deben validar previamente el documento con un parser XML seguro.
 */
final class XmlFragment
{
    /**
     * Extrae un elemento identificado por la ruta de nombres locales desde la raíz.
     *
     * @param list<string> $path
     */
    public static function extract(string $xml, array $path): ?string
    {
        $fragments = self::extractAll($xml, $path);
        if ($fragments === []) {
            return null;
        }
        if (count($fragments) !== 1) {
            throw new SiiException('El XML contiene más de un fragmento en la ruta léxica esperada.');
        }

        return $fragments[0];
    }

    /**
     * Extrae todos los elementos de la ruta en el orden en que aparecen.
     *
     * @param list<string> $path
     * @return list<string>
     */
    public static function extractAll(string $xml, array $path): array
    {
        $spans = self::elementSpans($xml, $path);
        if ($spans === []) {
            return [];
        }

        return array_map(
            static fn (array $span): string => substr($xml, $span['start'], $span['length']),
            $spans
        );
    }

    /**
     * Reemplaza el único elemento de la ruta conservando el resto de bytes XML.
     *
     * @param list<string> $path
     */
    public static function replace(string $xml, array $path, string $replacement): string
    {
        $spans = self::elementSpans($xml, $path);
        if (count($spans) !== 1) {
            throw new SiiException('No se pudo identificar de forma única el fragmento XML que se debe conservar.');
        }

        $span = $spans[0];

        return substr_replace($xml, $replacement, $span['start'], $span['length']);
    }

    /**
     * Restaura varios elementos de la misma ruta en su orden original.
     *
     * @param list<string> $path
     * @param list<string> $replacements
     */
    public static function replaceAll(string $xml, array $path, array $replacements): string
    {
        $spans = self::elementSpans($xml, $path);
        if (count($spans) !== count($replacements)) {
            throw new SiiException('La cantidad de fragmentos XML no coincide al restaurar el contenido original.');
        }

        for ($index = count($spans) - 1; $index >= 0; --$index) {
            $span = $spans[$index];
            $xml = substr_replace($xml, $replacements[$index], $span['start'], $span['length']);
        }

        return $xml;
    }

    /**
     * @param list<string> $path
     * @return list<array{start: int, length: int}>
     */
    private static function elementSpans(string $xml, array $path): array
    {
        if ($path === [] || array_filter($path, static fn (string $name): bool => $name === '') !== []) {
            throw new SiiException('La ruta XML para conservar un fragmento no es válida.');
        }

        $stack = [];
        $spans = [];
        $offset = 0;
        $xmlLength = strlen($xml);

        while (($start = strpos($xml, '<', $offset)) !== false) {
            if (substr($xml, $start, 4) === '<!--') {
                $end = strpos($xml, '-->', $start + 4);
                if ($end === false) {
                    throw new SiiException('El XML contiene un comentario incompleto al conservar un fragmento.');
                }
                $offset = $end + 3;

                continue;
            }

            if (substr($xml, $start, 9) === '<![CDATA[') {
                $end = strpos($xml, ']]>', $start + 9);
                if ($end === false) {
                    throw new SiiException('El XML contiene una sección CDATA incompleta al conservar un fragmento.');
                }
                $offset = $end + 3;

                continue;
            }

            if (substr($xml, $start, 2) === '<?') {
                $end = strpos($xml, '?>', $start + 2);
                if ($end === false) {
                    throw new SiiException('El XML contiene una instrucción de procesamiento incompleta.');
                }
                $offset = $end + 2;

                continue;
            }

            if (substr($xml, $start, 2) === '<!') {
                throw new SiiException('No se permiten declaraciones XML al conservar un fragmento.');
            }

            $end = self::tagEnd($xml, $start + 1);
            if ($end === null) {
                throw new SiiException('El XML contiene una etiqueta incompleta al conservar un fragmento.');
            }
            $tag = substr($xml, $start, $end - $start + 1);
            $offset = $end + 1;

            if (preg_match('/^<\/\s*([A-Za-z_][A-Za-z0-9_.:-]*)\s*>$/D', $tag, $closing) === 1) {
                $entry = array_pop($stack);
                if (!is_array($entry) || $entry['qualified'] !== $closing[1]) {
                    throw new SiiException('Las etiquetas XML no están balanceadas al conservar un fragmento.');
                }
                if ($entry['capture_start'] !== null) {
                    $spans[] = [
                        'start'  => $entry['capture_start'],
                        'length' => $end + 1 - $entry['capture_start'],
                    ];
                }

                continue;
            }

            if (preg_match('/^<([A-Za-z_][A-Za-z0-9_.:-]*)(?=\s|\/?>)/D', $tag, $opening) !== 1) {
                throw new SiiException('El XML contiene una etiqueta no reconocida al conservar un fragmento.');
            }

            $qualifiedName = $opening[1];
            $parts = explode(':', $qualifiedName);
            $localName = $parts[array_key_last($parts)];
            $pathSoFar = [...array_column($stack, 'local'), $localName];
            $captureStart = $pathSoFar === $path ? $start : null;
            $selfClosing = preg_match('/\/\s*>$/D', $tag) === 1;

            if ($selfClosing) {
                if ($captureStart !== null) {
                    $spans[] = ['start' => $start, 'length' => $end + 1 - $start];
                }

                continue;
            }

            $stack[] = [
                'qualified'     => $qualifiedName,
                'local'         => $localName,
                'capture_start' => $captureStart,
            ];
        }

        if ($offset > $xmlLength || $stack !== []) {
            throw new SiiException('El XML no está completo al conservar un fragmento.');
        }

        return $spans;
    }

    private static function tagEnd(string $xml, int $offset): ?int
    {
        $quote = null;
        $xmlLength = strlen($xml);
        for ($index = $offset; $index < $xmlLength; ++$index) {
            $character = $xml[$index];
            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
            } elseif ($character === '>') {
                return $index;
            }
        }

        return null;
    }
}
