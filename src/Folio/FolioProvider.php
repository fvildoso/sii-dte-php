<?php

declare(strict_types=1);

namespace SiiDte\Folio;

use SiiDte\Exceptions\FolioAgotadoException;

/**
 * Contrato de reserva de folios. La implementación debe ser atómica: dos solicitudes
 * concurrentes para el mismo (RUT, ambiente, tipo) nunca pueden recibir el mismo folio.
 *
 * Implementaciones incluidas: PdoFolioProvider (MySQL/PostgreSQL/SQLite) e InMemoryFolioProvider.
 */
interface FolioProvider
{
    /**
     * Reserva el siguiente folio disponible y devuelve también su CAF.
     *
     * @param string $rutEmisor RUT con DV ("76123456-0")
     * @param 'certificacion'|'produccion' $ambiente
     * @throws FolioAgotadoException Si no queda ningún folio vigente para el tipo
     */
    public function reservar(string $rutEmisor, string $ambiente, int $tipoDte): FolioReservado;
}
