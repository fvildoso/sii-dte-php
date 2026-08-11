<?php

declare(strict_types=1);

namespace SiiDte\Exceptions;

/**
 * Se lanza cuando no hay folios disponibles para un tipo de DTE.
 * Tu aplicación debe capturar esta excepción y alertar al administrador
 * para que solicite un nuevo CAF al SII.
 */
class FolioAgotadoException extends SiiException {}
