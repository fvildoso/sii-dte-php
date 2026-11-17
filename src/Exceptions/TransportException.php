<?php

declare(strict_types=1);

namespace SiiDte\Exceptions;

final class TransportException extends SiiException
{
    public function __construct(
        string $message,
        public readonly bool $outcomeMayBeUnknown,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
