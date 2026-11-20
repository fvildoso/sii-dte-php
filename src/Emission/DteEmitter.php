<?php

declare(strict_types=1);

namespace SiiDte\Emission;

use SiiDte\Auth\TokenManager;
use SiiDte\Boleta\BoletaApi;
use SiiDte\Document\DteDocumentBuilder;
use SiiDte\Document\DteTypes;
use SiiDte\Document\SiiSchemaValidator;
use SiiDte\Document\TedBuilder;
use SiiDte\Envelope\EnvelopeBuilder;
use SiiDte\Exceptions\SiiException;
use SiiDte\Exceptions\TransportException;
use SiiDte\Folio\CafInspector;
use SiiDte\Folio\FolioProvider;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteStore;
use SiiDte\Utils\HttpRequestTransport;
use SiiDte\Utils\HttpTransport;
use SiiDte\Utils\RutHelper;
use SiiDte\Utils\SiiWebService;
use SiiDte\Utils\XmlFragment;

/**
 * Emite un DTE de punta a punta: reserva folio → arma XML → timbra (TED) → firma → sobre → valida XSD
 * → persiste el intento → envía al SII (SOAP para facturas y afines, REST para boletas).
 *
 * El ambiente (certificacion|produccion) solo cambia los endpoints; el flujo es idéntico.
 */
final class DteEmitter
{
    private const RUT_SII = '60803000-K';

    private readonly XmlSigner $signer;
    private readonly DteDocumentBuilder $builder;
    private readonly TedBuilder $tedBuilder;
    private readonly SiiSchemaValidator $validator;
    private readonly SiiWebService $webService;
    private readonly TokenManager $tokenManager;
    private ?BoletaApi $boletaApi = null;
    /** @var \Closure(): \DateTimeImmutable */
    private readonly \Closure $clock;
    private readonly string $rutEmisor;
    private readonly string $rutEnvia;

    /**
     * @param array<string, mixed> $config rut_emisor, dv_emisor, razon_social, giro, direccion, ciudad|comuna,
     *        codigo_actividad, numero_resolucion, fecha_resolucion, ambiente, y opcionalmente rut_envia ("12345678-5")
     */
    public function __construct(
        private readonly array $config,
        CertificateStore $certificado,
        private readonly ?FolioProvider $folios = null,
        private readonly ?DteStore $store = null,
        private readonly ?HttpTransport $transport = null,
        private readonly int $timeoutSeconds = 60,
        ?\Closure $clock = null,
    ) {
        foreach (['rut_emisor', 'dv_emisor', 'razon_social', 'giro', 'direccion', 'ambiente'] as $key) {
            if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
                throw new SiiException("Config requerida faltante: '{$key}'");
            }
        }
        if (!in_array($config['ambiente'], ['certificacion', 'produccion'], true)) {
            throw new SiiException("El ambiente debe ser 'certificacion' o 'produccion'");
        }
        $this->rutEmisor = RutHelper::format((string) $config['rut_emisor'], (string) $config['dv_emisor']);
        if (!RutHelper::validate($this->rutEmisor)) {
            throw new SiiException('El RUT del emisor no es válido.');
        }
        $this->rutEnvia = isset($config['rut_envia']) ? (string) $config['rut_envia'] : $this->rutEmisor;

        $this->signer = new XmlSigner($certificado);
        $this->builder = new DteDocumentBuilder($config);
        $this->tedBuilder = new TedBuilder(new CafInspector());
        $this->validator = new SiiSchemaValidator();
        $this->tokenManager = new TokenManager($this->signer, (string) $config['ambiente'], $transport, $timeoutSeconds);
        $this->webService = new SiiWebService((string) $config['ambiente'], $transport, $timeoutSeconds);
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('America/Santiago'));
    }

    /**
     * Reserva un folio, genera el documento y lo envía al SII.
     *
     * @param array<string, mixed> $doc Ver DteDocumentBuilder (sin "folio": lo asigna el FolioProvider)
     * @throws \SiiDte\Exceptions\FolioAgotadoException Si no hay folios
     * @throws TransportException Si el envío pudo llegar al SII pero no se conoce el resultado:
     *         el intento queda como 'envio_incierto' en el DteStore; concílielo antes de reenviar
     */
    public function emitir(array $doc): EmisionResultado
    {
        if ($this->folios === null) {
            throw new SiiException('Para emitir debe configurar un FolioProvider (por ejemplo, PdoFolioProvider).');
        }
        $tipo = $doc['tipo'] ?? null;
        if (!is_int($tipo)) {
            throw new SiiException('El documento debe indicar su "tipo" (entero).');
        }
        if (isset($doc['folio'])) {
            throw new SiiException('No indique "folio": lo asigna el FolioProvider. Use preparar() para un folio propio.');
        }

        $reserva = $this->folios->reservar($this->rutEmisor, (string) $this->config['ambiente'], $tipo);
        $resultado = $this->preparar($doc, $reserva->folio, $reserva->cafXml);

        return $this->enviarPreparado($resultado);
    }

    /**
     * Genera, timbra, firma y valida un DTE con un folio y CAF dados, sin contactar al SII ni usar el
     * FolioProvider. Úselo para vistas previas firmadas, pruebas o para integrar su propia reserva.
     *
     * @param array<string, mixed> $doc
     */
    public function preparar(array $doc, int $folio, string $cafXml): EmisionResultado
    {
        $doc['folio'] = $folio;
        $tipo = $doc['tipo'] ?? null;
        if (!is_int($tipo)) {
            throw new SiiException('El documento debe indicar su "tipo" (entero).');
        }

        $xml = $this->builder->build($doc);
        $now = $this->clock->__invoke();
        $timbrado = $this->tedBuilder->addTedToDte(
            $xml,
            $cafXml,
            $folio,
            $now->format('Y-m-d\TH:i:s'),
            $now->format('Y-m-d\TH:i:s'),
        );
        $firmado = $this->signer->signDte($timbrado);
        $sobre = $this->signer->signEnvelope(EnvelopeBuilder::buildSet(
            [$firmado],
            $this->config,
            $this->rutEnvia,
            $now,
            self::RUT_SII,
        ));
        $this->validator->validate($sobre, $this->esBoleta($tipo) ? 'envio_boleta' : 'envio_dte');

        $nodo = $tipo === 43 ? 'Liquidacion' : ($tipo >= 110 ? 'Exportaciones' : 'Documento');
        $ted = XmlFragment::extract($firmado, ['DTE', $nodo, 'TED']) ?? '';

        return new EmisionResultado($tipo, $folio, null, 'preparado', $firmado, $sobre, $ted);
    }

    /**
     * Envía un documento ya preparado (persistiendo antes el intento si hay DteStore).
     */
    public function enviarPreparado(EmisionResultado $preparado): EmisionResultado
    {
        $ambiente = (string) $this->config['ambiente'];
        $this->store?->guardarIntento($this->rutEmisor, $ambiente, $preparado->tipo, $preparado->folio, $preparado->dteXml, $preparado->sobreXml);

        try {
            $trackId = $this->esBoleta($preparado->tipo)
                ? $this->boletaApi()->enviar($preparado->sobreXml, $this->rutEmisor, $this->rutEnvia)['trackId']
                : $this->enviarFactura($preparado->sobreXml);
        } catch (TransportException $e) {
            $this->store?->registrarResultado($this->rutEmisor, $ambiente, $preparado->tipo, $preparado->folio, 'envio_incierto', null, $e->getMessage());

            throw $e;
        } catch (SiiException $e) {
            $this->store?->registrarResultado($this->rutEmisor, $ambiente, $preparado->tipo, $preparado->folio, 'rechazado', null, $e->getMessage());

            throw $e;
        }

        $this->store?->registrarResultado($this->rutEmisor, $ambiente, $preparado->tipo, $preparado->folio, 'enviado', $trackId);

        return new EmisionResultado($preparado->tipo, $preparado->folio, $trackId, 'enviado', $preparado->dteXml, $preparado->sobreXml, $preparado->ted);
    }

    /**
     * Consulta el estado de un envío por Track ID. El estado es del sobre, no de cada documento.
     *
     * @return array<string, mixed>
     */
    public function consultarEstado(string $trackId, bool $boleta = false): array
    {
        if ($boleta) {
            return $this->boletaApi()->estado($this->rutEmisor, $trackId);
        }
        $parts = RutHelper::parse($this->rutEmisor);

        return $this->webService->getStatus($parts['rut'], $parts['dv'], $trackId, $this->tokenManager->getToken($parts['rut'], $parts['dv']));
    }

    private function enviarFactura(string $sobre): string
    {
        $emisor = RutHelper::parse($this->rutEmisor);
        $envia = RutHelper::parse($this->rutEnvia);
        $token = $this->tokenManager->getToken($emisor['rut'], $emisor['dv']);

        return $this->webService->uploadDte($emisor['rut'], $emisor['dv'], $sobre, $token, $envia['rut'], $envia['dv']);
    }

    private function boletaApi(): BoletaApi
    {
        if ($this->transport !== null && !$this->transport instanceof HttpRequestTransport) {
            throw new SiiException('Las boletas requieren un transporte que implemente HttpRequestTransport (GET y POST).');
        }

        return $this->boletaApi ??= new BoletaApi($this->signer, (string) $this->config['ambiente'], $this->transport, $this->timeoutSeconds);
    }

    private function esBoleta(int $tipo): bool
    {
        return $tipo === DteTypes::BOLETA_ELECTRONICA || $tipo === DteTypes::BOLETA_NO_AFECTA_ELECTRONICA;
    }
}
