<?php

declare(strict_types=1);

namespace SiiDte\Utils;

use SiiDte\Exceptions\TransportException;

final class CurlHttpTransport implements HttpTransport
{
    public function post(string $url, string $body, array $headers, int $timeoutSeconds): HttpResponse
    {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('El timeout HTTP debe ser positivo.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new TransportException('No se pudo inicializar el transporte HTTP.', true);
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeoutSeconds),
            CURLOPT_TIMEOUT        => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $response = curl_exec($handle);
        $errno = curl_errno($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($response === false || $errno !== 0) {
            throw new TransportException(
                'La solicitud HTTP falló; el resultado remoto puede ser desconocido. No reintente automáticamente.',
                true,
                $errno,
            );
        }

        return new HttpResponse($statusCode, $response);
    }
}
