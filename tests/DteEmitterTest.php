<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Emission\DteEmitter;
use SiiDte\Exceptions\FolioAgotadoException;
use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\TransportException;
use SiiDte\Folio\InMemoryFolioProvider;
use SiiDte\Storage\InMemoryDteStore;
use SiiDte\Tests\Support\ScriptedTransport;
use SiiDte\Tests\Support\SyntheticSii;
use SiiDte\Utils\HttpResponse;

final class DteEmitterTest extends TestCase
{
    private const RECEPTOR = [
        'rut' => '8414240', 'dv' => '9', 'razon_social' => 'Cliente SpA',
        'giro' => 'Comercio', 'direccion' => 'Calle 2', 'comuna' => 'Santiago',
    ];

    private function config(string $ambiente = 'certificacion'): array
    {
        return SyntheticSii::emisorConfig() + ['ambiente' => $ambiente, 'ciudad' => 'Santiago'];
    }

    private function emisor(?ScriptedTransport $transport, ?InMemoryFolioProvider $folios = null, ?InMemoryDteStore $store = null, string $ambiente = 'certificacion'): DteEmitter
    {
        $synth = SyntheticSii::get();
        if ($folios === null) {
            $folios = new InMemoryFolioProvider();
            foreach ([33, 39] as $tipo) {
                $folios->agregarCaf($synth->caf($tipo, SyntheticSii::RUT_EMISOR, 10, 11), SyntheticSii::RUT_EMISOR, $ambiente, $tipo);
            }
        }

        return new DteEmitter(
            $this->config($ambiente),
            $synth->certificateStore(),
            $folios,
            $store,
            $transport,
            30,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-11-20T12:00:00', new \DateTimeZone('America/Santiago')),
        );
    }

    private function facturaDoc(): array
    {
        return ['tipo' => 33, 'receptor' => self::RECEPTOR, 'fecha' => '2026-11-20',
            'detalle' => [['nombre' => 'Servicio', 'cantidad' => 1, 'precio' => 10000]]];
    }

    public function testEmiteFacturaPorSoapGuardaIntentoYDevuelveTrackId(): void
    {
        $transport = new ScriptedTransport([
            ScriptedTransport::soap('getSeedReturn', ScriptedTransport::sii('SEMILLA', '000123')),
            ScriptedTransport::soap('getTokenReturn', ScriptedTransport::sii('TOKEN', 'token-de-prueba')),
            new HttpResponse(200, '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>987654</TRACKID></RECEPCIONDTE>'),
        ]);
        $store = new InMemoryDteStore();
        $resultado = $this->emisor($transport, null, $store)->emitir($this->facturaDoc());

        self::assertSame('987654', $resultado->trackId);
        self::assertSame('enviado', $resultado->estado);
        self::assertSame(10, $resultado->folio);
        self::assertStringStartsWith('<TED version="1.0">', $resultado->ted);
        self::assertStringContainsString('<Folio>10</Folio>', $resultado->dteXml);
        self::assertSame('enviado', $store->registros['97975000-5|certificacion|33|10']['estado']);
        self::assertSame('987654', $store->registros['97975000-5|certificacion|33|10']['track_id']);
        self::assertSame('https://maullin.sii.cl/cgi_dte/UPL/DTEUpload', $transport->requests[2]['url']);
        self::assertStringContainsString('<EnvioDTE', (string) $transport->requests[2]['body']);
    }

    public function testFoliosConsecutivosYAgotamiento(): void
    {
        $folios = new InMemoryFolioProvider();
        $folios->agregarCaf(SyntheticSii::get()->caf(33, SyntheticSii::RUT_EMISOR, 10, 11), SyntheticSii::RUT_EMISOR, 'certificacion', 33);
        $emisor = $this->emisor(null, $folios);

        self::assertSame(10, $emisor->preparar($this->facturaDoc(), $folios->reservar(SyntheticSii::RUT_EMISOR, 'certificacion', 33)->folio, SyntheticSii::get()->caf(33, SyntheticSii::RUT_EMISOR, 10, 11))->folio);
        self::assertSame(11, $folios->reservar(SyntheticSii::RUT_EMISOR, 'certificacion', 33)->folio);
        $this->expectException(FolioAgotadoException::class);
        $folios->reservar(SyntheticSii::RUT_EMISOR, 'certificacion', 33);
    }

    public function testEmiteBoletaPorRestConTokenPropio(): void
    {
        $transport = new ScriptedTransport([
            new HttpResponse(200, ScriptedTransport::sii('SEMILLA', '030530912644')),
            new HttpResponse(200, ScriptedTransport::sii('TOKEN', 'TOKENBOLETA123')),
            new HttpResponse(200, '{"rut_emisor":"97975000-5","trackid":4455,"estado":"REC"}'),
        ]);
        $resultado = $this->emisor($transport)->emitir([
            'tipo' => 39, 'fecha' => '2026-11-20',
            'detalle' => [['nombre' => 'Café', 'cantidad' => 2, 'precio' => 1500]],
        ]);

        self::assertSame('4455', $resultado->trackId);
        [$seed, $token, $upload] = $transport->requests;
        self::assertSame('GET', $seed['method']);
        self::assertSame('https://apicert.sii.cl/recursos/v1/boleta.electronica.semilla', $seed['url']);
        self::assertSame('POST', $token['method']);
        self::assertStringContainsString('<getToken>', (string) $token['body']);
        self::assertSame('https://pangal.sii.cl/recursos/v1/boleta.electronica.envio', $upload['url']);
        self::assertContains('Cookie: TOKEN=TOKENBOLETA123', $upload['headers']);
        self::assertStringContainsString('<EnvioBOLETA', (string) $upload['body']);
        self::assertStringContainsString("name=\"rutCompany\"\r\n\r\n97975000\r\n", (string) $upload['body']);
    }

    public function testBoletaEnProduccionUsaLosHostsDeProduccion(): void
    {
        $transport = new ScriptedTransport([
            new HttpResponse(200, ScriptedTransport::sii('SEMILLA', '030530912644')),
            new HttpResponse(200, ScriptedTransport::sii('TOKEN', 'TOKENPROD')),
            new HttpResponse(200, '{"trackid":9,"estado":"REC"}'),
        ]);
        $this->emisor($transport, null, null, 'produccion')->emitir(['tipo' => 39, 'fecha' => '2026-11-20',
            'detalle' => [['nombre' => 'X', 'cantidad' => 1, 'precio' => 1000]]]);

        self::assertSame('https://api.sii.cl/recursos/v1/boleta.electronica.semilla', $transport->requests[0]['url']);
        self::assertSame('https://rahue.sii.cl/recursos/v1/boleta.electronica.envio', $transport->requests[2]['url']);
    }

    public function testEnvioInciertoMarcaElIntentoYPropagaLaExcepcion(): void
    {
        $transport = new ScriptedTransport([
            ScriptedTransport::soap('getSeedReturn', ScriptedTransport::sii('SEMILLA', '000123')),
            ScriptedTransport::soap('getTokenReturn', ScriptedTransport::sii('TOKEN', 'token-de-prueba')),
            new TransportException('timeout', true),
        ]);
        $store = new InMemoryDteStore();
        try {
            $this->emisor($transport, null, $store)->emitir($this->facturaDoc());
            self::fail('Debía propagar TransportException.');
        } catch (TransportException $e) {
            self::assertTrue($e->outcomeMayBeUnknown);
        }
        self::assertSame('envio_incierto', $store->registros['97975000-5|certificacion|33|10']['estado']);
    }

    public function testEmitirRechazaFolioExplicitoYTipoAusente(): void
    {
        $emisor = $this->emisor(null);
        try {
            $emisor->emitir(['folio' => 5] + $this->facturaDoc());
            self::fail();
        } catch (SiiException $e) {
            self::assertStringContainsString('FolioProvider', $e->getMessage());
        }
        $this->expectException(SiiException::class);
        $emisor->emitir(['receptor' => self::RECEPTOR]);
    }

    public function testConsultaEstadoDeBoletaPorRest(): void
    {
        $transport = new ScriptedTransport([
            new HttpResponse(200, ScriptedTransport::sii('SEMILLA', '030530912644')),
            new HttpResponse(200, ScriptedTransport::sii('TOKEN', 'TOKENBOLETA123')),
            new HttpResponse(200, '{"estado":"EPR","estadistica":[{"tipo":39,"informados":1,"aceptados":1,"rechazados":0,"reparos":0}],"detalle_rep_rech":[]}'),
        ]);
        $estado = $this->emisor($transport)->consultarEstado('4455', true);

        self::assertSame('EPR', $estado['estado']);
        self::assertSame(1, $estado['estadistica'][0]['aceptados']);
        self::assertSame('https://apicert.sii.cl/recursos/v1/boleta.electronica.envio/97975000-5-4455', $transport->requests[2]['url']);
    }
}
