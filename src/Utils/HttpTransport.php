<?php

declare(strict_types=1);

namespace SiiDte\Utils;

interface HttpTransport
{
    /**
     * @param list<string> $headers
     */
    public function post(string $url, string $body, array $headers, int $timeoutSeconds): HttpResponse;
}
