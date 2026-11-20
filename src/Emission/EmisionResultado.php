<?php

declare(strict_types=1);

namespace SiiDte\Emission;

/**
 * Resultado de preparar o emitir un DTE.
 *
 * `ted` es el nodo <TED> listo para codificarse en PDF417 al imprimir el documento.
 */
final readonly class EmisionResultado
{
    public function __construct(
        public int $tipo,
        public int $folio,
        public ?string $trackId,
        public string $estado,
        public string $dteXml,
        public string $sobreXml,
        public string $ted,
    ) {}
}
