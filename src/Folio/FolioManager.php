<?php

declare(strict_types=1);

namespace SiiDte\Folio;

use PDO;
use SiiDte\Exceptions\SiiException;

/**
 * API heredada de folios locales, deshabilitada para importación y asignación.
 *
 * El esquema anterior no separa CAF por RUT y ambiente, y no verifica FRMA.
 * La aplicación consumidora debe reservar folios externamente en su almacén
 * transaccional compartido y pasar el folio explícitamente al cliente.
 */
class FolioManager
{
    private \PDO $pdo;

    /**
     * @param \PDO $pdo Conexión PDO usada por las consultas de compatibilidad heredadas
     */
    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        // Asegurar que los errores PDO sean excepciones
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * La importación heredada está deshabilitada hasta verificar FRMA y migrar
     * el almacenamiento a una clave compuesta por RUT y ambiente.
     *
     * @param string $cafXml Contenido del archivo CAF (.xml)
     * @param int $tipoDte Tipo de DTE (debe coincidir con el CAF)
     * @throws SiiException Siempre: la importación local está deshabilitada
     */
    public function importarCaf(string $cafXml, int $tipoDte): void
    {
        throw new SiiException(
            'La importación local de CAF está deshabilitada: FRMA no se verifica y el esquema heredado no separa RUT/ambiente. '
            . 'Inspeccione el CAF con CafInspector y gestione CAF/folios en el almacén transaccional de la aplicación.'
        );
    }

    /**
     * La reserva local heredada está deshabilitada; no ofrece aislamiento por RUT/ambiente.
     *
     * @throws SiiException Siempre: la reserva local está deshabilitada
     */
    public function siguienteFolio(int $tipoDte): array
    {
        throw new SiiException(
            'La asignación local de folios está deshabilitada. Reserve el folio transaccionalmente por RUT, ambiente y tipo en la aplicación.'
        );
    }

    /**
     * La consulta del contador heredado está deshabilitada porque mezcla emisores/ambientes.
     *
     * @throws SiiException Siempre: no hay aislamiento por RUT/ambiente
     */
    public function foliosDisponibles(int $tipoDte): int
    {
        throw new SiiException(
            'El contador local heredado no confirma disponibilidad ante el SII ni está aislado por RUT/ambiente.'
        );
    }

    /**
     * El registro heredado de anulaciones está deshabilitado.
     *
     * @throws SiiException Siempre: el esquema no aísla folios por RUT/ambiente
     */
    public function anularFolio(int $tipoDte, int $folio, string $motivo = ''): void
    {
        throw new SiiException(
            'El registro local de anulaciones está deshabilitado: el esquema heredado no aísla los folios por RUT/ambiente.'
        );
    }

    /**
     * La consulta heredada de rangos está deshabilitada para evitar exponer datos entre emisores.
     *
     * @throws SiiException Siempre: no hay aislamiento por RUT/ambiente
     */
    public function estadoCafs(?int $tipoDte = null): array
    {
        throw new SiiException(
            'La consulta de rangos heredados está deshabilitada para evitar exponer datos sin aislamiento por RUT/ambiente.'
        );
    }

}
