<?php

declare(strict_types=1);

namespace SiiDte\Utils;

/**
 * Transporte HTTP con método arbitrario (GET/POST). Lo necesitan los servicios REST de boletas.
 */
interface HttpRequestTransport extends HttpTransport
{
    /**
     * @param string $method "GET" o "POST"
     * @param list<string> $headers
     */
    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse;
}
