<?php

declare(strict_types=1);

namespace SiiDte\Tests\Support;

use SiiDte\Utils\HttpRequestTransport;
use SiiDte\Utils\HttpResponse;

/** Transporte HTTP simulado: responde en orden y registra cada solicitud. */
final class ScriptedTransport implements HttpRequestTransport
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: ?string}> */
    public array $requests = [];

    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses) {}

    public function post(string $url, string $body, array $headers, int $timeoutSeconds): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body, $timeoutSeconds);
    }

    public function request(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (!$next instanceof HttpResponse) {
            throw new \LogicException('No quedan respuestas simuladas.');
        }

        return $next;
    }

    public static function soap(string $returnElement, string $innerXml): HttpResponse
    {
        return new HttpResponse(200, '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            . '<soapenv:Body><ns1:response xmlns:ns1="http://DefaultNamespace"><' . $returnElement . '>'
            . htmlspecialchars($innerXml, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            . '</' . $returnElement . '></ns1:response></soapenv:Body></soapenv:Envelope>');
    }

    public static function sii(string $field, string $value): string
    {
        return '<SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema"><SII:RESP_HDR><ESTADO>00</ESTADO></SII:RESP_HDR>'
            . '<SII:RESP_BODY><' . $field . '>' . $value . '</' . $field . '></SII:RESP_BODY></SII:RESPUESTA>';
    }
}
