<?php

declare(strict_types=1);

namespace SiiDte\Utils;

final readonly class HttpResponse
{
    public function __construct(
        public int $statusCode,
        public string $body,
    ) {}
}
