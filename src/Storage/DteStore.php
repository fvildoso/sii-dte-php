<?php

declare(strict_types=1);

namespace SiiDte\Storage;

/**
 * Persistencia opcional del ciclo de vida de cada DTE emitido. DteEmitter guarda el intento ANTES
 * de enviar al SII (para poder conciliar un envío incierto) y registra después el resultado.
 *
 * Estados usados por la biblioteca: 'preparado', 'enviado', 'envio_incierto', 'rechazado'.
 */
interface DteStore
{
    public function guardarIntento(string $rutEmisor, string $ambiente, int $tipoDte, int $folio, string $dteXml, string $sobreXml): void;

    public function registrarResultado(
        string $rutEmisor,
        string $ambiente,
        int $tipoDte,
        int $folio,
        string $estado,
        ?string $trackId = null,
        ?string $detalle = null,
    ): void;
}
