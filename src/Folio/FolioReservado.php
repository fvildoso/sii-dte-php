<?php

declare(strict_types=1);

namespace SiiDte\Folio;

/**
 * Folio reservado junto con el CAF (archivo AUTORIZACION completo) que lo ampara.
 * El CAF contiene la llave privada de timbrado: tratar como secreto.
 */
final readonly class FolioReservado
{
    public function __construct(
        public int $folio,
        public string $cafXml,
    ) {}

    public function __debugInfo(): array
    {
        return ['folio' => $this->folio, 'cafXml' => '[reservado]'];
    }
}
