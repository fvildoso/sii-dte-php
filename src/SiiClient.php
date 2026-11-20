<?php

declare(strict_types=1);

namespace SiiDte;

use SiiDte\Auth\TokenManager;
use SiiDte\Document\DteDocumentBuilder;
use SiiDte\Emission\DteEmitter;
use SiiDte\Emission\EmisionResultado;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\FolioProvider;
use SiiDte\Pdf\PdfGenerator;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteStore;
use SiiDte\Utils\HttpTransport;
use SiiDte\Utils\SiiWebService;

/**
 * Cliente principal para integración con el SII de Chile: emisión de DTE (facturas, notas, guías,
 * liquidaciones, exportación y boletas), consulta de estado y utilidades.
 *
 * @phpstan-type SiiConfigShape array{
 *     rut_emisor: string,
 *     dv_emisor: string,
 *     razon_social: string,
 *     giro: string,
 *     direccion: string,
 *     ciudad: string,
 *     comuna?: string,
 *     codigo_actividad?: string|int|list<string|int>,
 *     telefono?: string,
 *     email?: string,
 *     numero_resolucion?: int,
 *     fecha_resolucion?: string,
 *     rut_envia?: string,
 *     ambiente: 'certificacion'|'produccion'
 * }
 */
class SiiClient
{
    private TokenManager  $tokenManager;
    private XmlSigner     $signer;
    private SiiWebService $webService;
    private ?DteEmitter   $emitter = null;
    /** @var SiiConfigShape */
    private array         $config;

    /**
     * @param SiiConfigShape $config
     */
    public function __construct(
        array $config,
        private readonly CertificateStore $certStore,
        private readonly ?HttpTransport $transport = null,
        private readonly int $timeoutSeconds = 60,
        private ?FolioProvider $folios = null,
        private ?DteStore $store = null,
    ) {
        $this->validateConfig($config);
        $this->config       = $config;
        $this->signer       = new XmlSigner($certStore);
        $this->tokenManager = new TokenManager($this->signer, $config['ambiente'], $transport, $timeoutSeconds);
        $this->webService   = new SiiWebService($config['ambiente'], $transport, $timeoutSeconds);
    }

    /**
     * Crea e inicializa una instancia de SiiClient a partir de variables de entorno.
     *
     * Variables soportadas:
     * - SII_RUT_EMISOR, SII_DV_EMISOR
     * - SII_RAZON_SOCIAL, SII_GIRO, SII_DIRECCION, SII_CIUDAD
     * - SII_CODIGO_ACTIVIDAD, SII_NUMERO_RESOLUCION, SII_FECHA_RESOLUCION
     * - SII_AMBIENTE ('certificacion' o 'produccion')
     * - SII_CERT_PATH y SII_CERT_PASS (o SII_CERT_B64 y SII_CERT_PASS)
     *
     * @param array<string, mixed> $overrides Valores para sobreescribir las variables de entorno
     * @throws SiiException
     */
    public static function fromEnv(
        array $overrides = [],
        ?HttpTransport $transport = null,
        int $timeoutSeconds = 60,
        ?FolioProvider $folios = null,
        ?DteStore $store = null,
    ): self {
        $getEnv = static function (string $key, mixed $default = null): mixed {
            $val = getenv($key);
            if ($val !== false && $val !== '') {
                return $val;
            }
            if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
                return $_ENV[$key];
            }
            if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
                return $_SERVER[$key];
            }

            return $default;
        };

        $certPass = (string) ($overrides['cert_pass'] ?? $getEnv('SII_CERT_PASS', ''));
        $certPath = (string) ($overrides['cert_path'] ?? $getEnv('SII_CERT_PATH', ''));
        $certB64  = (string) ($overrides['cert_b64'] ?? $getEnv('SII_CERT_B64', ''));

        if ($certPath !== '') {
            $certStore = CertificateStore::fromFile($certPath, $certPass);
        } elseif ($certB64 !== '') {
            $certStore = CertificateStore::fromBase64($certB64, $certPass);
        } else {
            throw new SiiException('No se especificó certificado digital en variables de entorno (SII_CERT_PATH o SII_CERT_B64).');
        }

        /** @var SiiConfigShape $config */
        $config = array_merge([
            'rut_emisor'        => (string) $getEnv('SII_RUT_EMISOR', ''),
            'dv_emisor'         => (string) $getEnv('SII_DV_EMISOR', ''),
            'razon_social'      => (string) $getEnv('SII_RAZON_SOCIAL', ''),
            'giro'              => (string) $getEnv('SII_GIRO', ''),
            'direccion'         => (string) $getEnv('SII_DIRECCION', ''),
            'ciudad'            => (string) $getEnv('SII_CIUDAD', ''),
            'codigo_actividad'  => (string) $getEnv('SII_CODIGO_ACTIVIDAD', '620200'),
            'numero_resolucion' => (int) $getEnv('SII_NUMERO_RESOLUCION', 0),
            'fecha_resolucion'  => (string) $getEnv('SII_FECHA_RESOLUCION', '2019-10-18'),
            'ambiente'          => (string) $getEnv('SII_AMBIENTE', 'certificacion'),
        ], $overrides);

        return new self($config, $certStore, $transport, $timeoutSeconds, $folios, $store);
    }

    // Emisión

    /**
     * Configura la fuente de folios (PdoFolioProvider, InMemoryFolioProvider u otra implementación).
     */
    public function usarFolios(FolioProvider $folios): static
    {
        $this->folios = $folios;
        $this->emitter = null;

        return $this;
    }

    /**
     * Configura la persistencia opcional del ciclo de vida de cada DTE (se guarda antes de enviar).
     */
    public function usarAlmacen(DteStore $store): static
    {
        $this->store = $store;
        $this->emitter = null;

        return $this;
    }

    /**
     * Emite un DTE: reserva folio, arma, timbra, firma, valida contra el XSD y envía al SII.
     *
     * @param array<string, mixed> $doc Ver DteDocumentBuilder; incluye "tipo" y no incluye "folio"
     * @throws \SiiDte\Exceptions\FolioAgotadoException Si no quedan folios
     * @throws \SiiDte\Exceptions\TransportException Si el resultado del envío es incierto
     */
    public function emitir(array $doc): EmisionResultado
    {
        return $this->emitter()->emitir($doc);
    }

    /**
     * Variante de emitir() con tipo y datos separados; devuelve un arreglo.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array<string, mixed> $datos Datos del documento (sin tipo ni folio)
     * @return array{trackId: string|null, folio: int, estado: string, xml: string, sobre: string, ted: string}
     */
    public function enviarDte(int $tipoDte, array $datos): array
    {
        $r = $this->emitir(['tipo' => $tipoDte] + $datos);

        return ['trackId' => $r->trackId, 'folio' => $r->folio, 'estado' => $r->estado, 'xml' => $r->dteXml, 'sobre' => $r->sobreXml, 'ted' => $r->ted];
    }

    /**
     * Genera, timbra, firma y valida un DTE con un folio y CAF propios, sin contactar al SII.
     *
     * @param array<string, mixed> $doc
     */
    public function preparar(array $doc, int $folio, string $cafXml): EmisionResultado
    {
        return $this->emitter()->preparar($doc, $folio, $cafXml);
    }

    /**
     * Genera el XML sin timbre ni firma (vista previa). Requiere un folio explícito.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array<string, mixed> $datos Datos del documento, con "folio"
     * @return string XML ISO-8859-1 sin TED ni firma
     */
    public function previsualizarXml(int $tipoDte, array $datos): string
    {
        if (!isset($datos['folio']) || !is_int($datos['folio']) || $datos['folio'] <= 0) {
            throw new SiiException('La vista previa requiere un folio explícito; no reserva folios.');
        }

        return new DteDocumentBuilder($this->config)->build(['tipo' => $tipoDte] + $datos);
    }

    /**
     * @deprecated Use previsualizarXml().
     */
    public function generarXml(int $tipoDte, array $datos): string
    {
        return $this->previsualizarXml($tipoDte, $datos);
    }

    /**
     * Genera HTML renderizable a PDF.
     *
     * Uso con dompdf (composer require dompdf/dompdf):
     *   $html = $client->generarHtmlPdf($xml, ['logo_url' => 'data:image/png;base64,...']);
     *   $pdf = new \Dompdf\Dompdf();
     *   $pdf->loadHtml($html);
     *   $pdf->setPaper('letter', 'portrait');
     *   $pdf->render();
     *   file_put_contents('factura.pdf', $pdf->output());
     *
     * @param string $xmlFirmado XML del sobre firmado
     * @param array $opciones Opciones de visualización (logo, colores, etc.)
     * @return string HTML generado
     */
    public function generarHtmlPdf(string $xmlFirmado, array $opciones = []): string
    {
        $gen = new PdfGenerator($this->config['ambiente'], $this->getToken());

        return $gen->toHtml($xmlFirmado, $opciones);
    }

    // Consulta y procesamiento

    /**
     * Consulta el estado de un envío por Track ID.
     * El SII puede tardar de 2 a 15 minutos en procesar.
     *
     * Códigos de estado:
     *   EPR = Procesado correctamente
     *   RSC = Rechazado
     *   SOK = Recibido, en proceso
     *   DNK = No encontrado aún
     *
     * @param string $trackId ID de seguimiento del envío
     * @param bool $boleta true si el envío fue de boletas (39/41): usa la API REST de boletas
     * @return array<string, mixed> Resultado de la consulta al SII
     */
    public function consultarEstado(string $trackId, bool $boleta = false): array
    {
        // El estado de envío es de nivel sobre y no acredita el resultado individual de cada DTE.
        return $this->emitter()->consultarEstado($trackId, $boleta);
    }

    /**
     * Consulta si un DTE específico fue aceptado por el SII.
     *
     * @param string $rutReceptor RUT del receptor sin DV
     * @param string $dvReceptor Dígito verificador del receptor
     * @param int $tipoDte Código del tipo de DTE
     * @param int $folio Número de folio del documento
     * @param string $fechaEmision Fecha de emisión (AAAA-MM-DD)
     * @param int $montoPesos Monto total del documento en pesos
     * @return array Resultado de la consulta puntual
     */
    public function consultarDte(
        string $rutReceptor,
        string $dvReceptor,
        int $tipoDte,
        int $folio,
        string $fechaEmision,
        int $montoPesos,
        ?string $rutConsultante = null,
        ?string $dvConsultante = null,
    ): array {
        return $this->webService->queryDte(
            $this->config['rut_emisor'],
            $this->config['dv_emisor'],
            $rutReceptor,
            $dvReceptor,
            $tipoDte,
            $folio,
            $fechaEmision,
            $montoPesos,
            $this->getToken(),
            $rutConsultante,
            $dvConsultante,
        );
    }

    /**
     * Obtiene o renueva el token de sesión SII.
     *
     * @return string Token de sesión vigente
     */
    public function getToken(): string
    {
        return $this->tokenManager->getToken($this->config['rut_emisor'], $this->config['dv_emisor']);
    }

    private function emitter(): DteEmitter
    {
        return $this->emitter ??= new DteEmitter(
            $this->config,
            $this->certStore,
            $this->folios,
            $this->store,
            $this->transport,
            $this->timeoutSeconds,
        );
    }

    /**
     * Valida la configuración inicial del cliente.
     *
     * @param array $config Datos de configuración
     * @throws SiiException Si falta algún parámetro obligatorio
     */
    private function validateConfig(array $config): void
    {
        foreach (['rut_emisor', 'dv_emisor', 'razon_social', 'giro', 'direccion', 'ciudad', 'ambiente'] as $key) {
            if (!isset($config[$key]) || trim((string) $config[$key]) === '') {
                throw new SiiException("Config requerida faltante: '{$key}'");
            }
        }
        if (!in_array($config['ambiente'], ['certificacion', 'produccion'])) {
            throw new SiiException("El ambiente debe ser 'certificacion' o 'produccion'");
        }
    }
}
