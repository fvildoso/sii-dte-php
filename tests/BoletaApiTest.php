<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Boleta\BoletaApi;
use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\TransportException;
use SiiDte\Tests\Support\ScriptedTransport;
use SiiDte\Tests\Support\SyntheticSii;
use SiiDte\Utils\HttpResponse;

final class BoletaApiTest extends TestCase
{
    private function api(array $responses, string $ambiente = 'certificacion'): array
    {
        $transport = new ScriptedTransport(array_merge([
            new HttpResponse(200, ScriptedTransport::sii('SEMILLA', '030530912644')),
            new HttpResponse(200, ScriptedTransport::sii('TOKEN', 'TOKEN-ABC.123')),
        ], $responses));

        return [new BoletaApi(SyntheticSii::get()->signer(), $ambiente, $transport, 10), $transport];
    }

    public function testElTokenSeCacheaEntreLlamadas(): void
    {
        [$api, $transport] = $this->api([new HttpResponse(200, '{"estado":"EPR"}'), new HttpResponse(200, '{"estado":"EPR"}')]);
        $api->estado('97975000-5', '1');
        $api->estado('97975000-5', '2');

        self::assertCount(4, $transport->requests); // semilla, token, estado, estado
    }

    public function testRechazoHttp4xxEsSiiExceptionConDetalleLimpio(): void
    {
        [$api] = $this->api([new HttpResponse(400, '{"mensaje":"Sobre inválido\u0007"}')]);
        try {
            $api->enviar('<EnvioBOLETA/>', '97975000-5');
            self::fail();
        } catch (SiiException $e) {
            self::assertNotInstanceOf(TransportException::class, $e);
            self::assertStringContainsString('HTTP 400', $e->getMessage());
            self::assertStringContainsString('Sobre inválido', $e->getMessage());
            self::assertStringNotContainsString("\x07", $e->getMessage());
        }
    }

    public function testRechazo4xxSinCampoMensajeMuestraLaRespuestaDelSii(): void
    {
        foreach ([
            '{"codigo":"BOL-001","descripcion":"Caratula invalida"}' => '"descripcion":"Caratula invalida"',
            '<html><body>Bad Request: archivo invalido</body></html>' => 'Bad Request: archivo invalido',
        ] as $body => $esperado) {
            [$api] = $this->api([new HttpResponse(400, $body)]);
            try {
                $api->enviar('<EnvioBOLETA/>', '97975000-5');
                self::fail();
            } catch (SiiException $e) {
                self::assertNotInstanceOf(TransportException::class, $e);
                self::assertStringContainsString('HTTP 400', $e->getMessage());
                self::assertStringContainsString($esperado, $e->getMessage());
            }
        }
    }

    public function testRespuestaIlegibleEnElEnvioEsResultadoIncierto(): void
    {
        foreach ([new HttpResponse(200, 'no es json'), new HttpResponse(502, '<html>bad gateway</html>')] as $respuesta) {
            [$api] = $this->api([$respuesta]);
            try {
                $api->enviar('<EnvioBOLETA/>', '97975000-5');
                self::fail();
            } catch (TransportException $e) {
                self::assertTrue($e->outcomeMayBeUnknown);
            }
        }
    }

    public function testFalloDeRedEnElEnvioEsResultadoIncierto(): void
    {
        [$api] = $this->api([new \RuntimeException('conexión cortada')]);
        $this->expectException(TransportException::class);
        $api->enviar('<EnvioBOLETA/>', '97975000-5');
    }

    public function testSemillaOTokenInvalidosFallan(): void
    {
        $transport = new ScriptedTransport([new HttpResponse(200, ScriptedTransport::sii('SEMILLA', 'abc'))]);
        $api = new BoletaApi(SyntheticSii::get()->signer(), 'certificacion', $transport, 10);
        $this->expectException(SiiException::class);
        $api->getToken();
    }

    public function testTokenRechazadoInformaEstadoYGlosaDelSii(): void
    {
        $rechazo = '<?xml version="1.0" encoding="UTF-8"?><SII:RESPUESTA xmlns:SII="http://www.sii.cl/XMLSchema">'
            . '<SII:RESP_HDR><ESTADO>-07</ESTADO><GLOSA>Firma del documento no valida</GLOSA></SII:RESP_HDR></SII:RESPUESTA>';
        $transport = new ScriptedTransport([
            new HttpResponse(200, ScriptedTransport::sii('SEMILLA', '030530912644')),
            new HttpResponse(200, $rechazo),
        ]);
        $api = new BoletaApi(SyntheticSii::get()->signer(), 'certificacion', $transport, 10);
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('El SII no entregó el token de boletas (estado -07: Firma del documento no valida).');
        $api->getToken();
    }

    public function testTokenConFormatoInesperadoNoExponeElToken(): void
    {
        $transport = new ScriptedTransport([
            new HttpResponse(200, ScriptedTransport::sii('SEMILLA', '030530912644')),
            new HttpResponse(200, ScriptedTransport::sii('TOKEN', 'abc def secreto')),
        ]);
        $api = new BoletaApi(SyntheticSii::get()->signer(), 'certificacion', $transport, 10);
        try {
            $api->getToken();
            self::fail();
        } catch (SiiException $e) {
            self::assertStringContainsString('formato inesperado', $e->getMessage());
            self::assertStringNotContainsString('secreto', $e->getMessage());
        }
    }

    public function testValidaEntradas(): void
    {
        [$api] = $this->api([]);
        foreach ([fn() => $api->estado('97975000-5', 'abc'), fn() => $api->estado('11111111-1', '5'), fn() => $api->enviar('', '97975000-5')] as $call) {
            try {
                $call();
                self::fail();
            } catch (SiiException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAmbienteInvalido(): void
    {
        $this->expectException(SiiException::class);
        new BoletaApi(SyntheticSii::get()->signer(), 'staging');
    }
}
