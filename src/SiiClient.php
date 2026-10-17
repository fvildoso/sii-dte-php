<?php

declare(strict_types=1);

namespace SiiDte;

use SiiDte\Auth\TokenManager;
use SiiDte\Document\DteBuilder;
use SiiDte\Document\DteTypes;
use SiiDte\Envelope\EnvelopeBuilder;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\FolioManager;
use SiiDte\Pdf\PdfGenerator;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteRepository;
use SiiDte\Utils\SiiWebService;

/**
 * Cliente principal para integración con el SII de Chile.
 *
 * @phpstan-type SiiConfigShape array{
 *     rut_emisor: string,
 *     dv_emisor: string,
 *     razon_social: string,
 *     giro: string,
 *     direccion: string,
 *     ciudad: string,
 *     codigo_actividad?: string|int,
 *     telefono?: string,
 *     email?: string,
 *     numero_resolucion?: int,
 *     fecha_resolucion?: string,
 *     ambiente: 'certificacion'|'produccion'
 * }
 *
 * @phpstan-type ReceptorShape array{
 *     rut: string,
 *     dv: string,
 *     razon_social?: string,
 *     giro?: string,
 *     direccion?: string,
 *     ciudad?: string,
 *     contacto?: string,
 *     correo?: string
 * }
 *
 * @phpstan-type DetalleItemShape array{
 *     codigo?: string,
 *     nombre: string,
 *     descripcion?: string,
 *     cantidad: int|float,
 *     unidad_medida?: string,
 *     precio_unitario: int|float,
 *     descuento_porcentaje?: int|float,
 *     descuento_monto?: int|float,
 *     recargo_porcentaje?: int|float,
 *     recargo_monto?: int|float,
 *     exento?: bool
 * }
 *
 * @phpstan-type DteDataShape array{
 *     fecha: string,
 *     folio?: int,
 *     receptor: ReceptorShape,
 *     detalle: list<DetalleItemShape>|array<int, DetalleItemShape>,
 *     referencias?: list<array<string, mixed>>|array<int, array<string, mixed>>,
 *     descuento_global?: array<string, mixed>,
 *     recargo_global?: array<string, mixed>,
 *     medio_pago?: string,
 *     forma_pago?: string,
 *     observaciones?: string,
 *     ind_traslado?: int,
 *     tipo_despacho?: int
 * }
 *
 * @phpstan-type EnvioResultado array{
 *     trackId: string,
 *     folio: int,
 *     xml: string,
 *     dte_id: int|null
 * }
 */
class SiiClient
{
    private TokenManager  $tokenManager;
    private XmlSigner     $signer;
    private SiiWebService $webService;
    private DteBuilder    $builder;
    /** @var SiiConfigShape */
    private array         $config;
    private ?FolioManager  $folioManager  = null;
    private ?DteRepository $dteRepository = null;

    /**
     * @param SiiConfigShape $config
     * @param CertificateStore $certStore
     */
    public function __construct(array $config, CertificateStore $certStore)
    {
        $this->validateConfig($config);
        $this->config       = $config;
        $this->signer       = new XmlSigner($certStore->getPath(), $certStore->getPassword());
        $this->tokenManager = new TokenManager($this->signer, $config['ambiente']);
        $this->webService   = new SiiWebService($config['ambiente']);
        $this->builder      = new DteBuilder($config);
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
     * @return self
     * @throws SiiException
     */
    public static function fromEnv(array $overrides = []): self
    {
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
            $certStore = CertificateStore::fromEnv('SII_CERT_B64', 'SII_CERT_PASS');
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

        return new self($config, $certStore);
    }

    /**
     * Inyecta FolioManager para asignación automática de folios.
     *
     * @param FolioManager $fm Instancia del administrador de folios
     * @return static La misma instancia para encadenamiento
     */
    public function usarFolioManager(FolioManager $fm): static
    {
        $this->folioManager = $fm;
        return $this;
    }

    /**
     * Inyecta DteRepository para persistir los DTE en BD.
     *
     * @param DteRepository $repo Instancia del repositorio de DTE
     * @return static La misma instancia para encadenamiento
     */
    public function usarRepositorio(DteRepository $repo): static
    {
        $this->dteRepository = $repo;
        return $this;
    }

    // Emisión

    /**
     * Genera, firma y envía un DTE al SII.
     *
     * Si se inyectó FolioManager, el folio se asigna automáticamente.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array $datos Datos del documento a emitir
     * @return array {trackId: string, folio: int, xml: string, dte_id: int|null}
     * @throws SiiException Si falta el folio o hay error en el envío
     */
    public function enviarDte(int $tipoDte, array $datos): array
    {
        if ($this->folioManager !== null && empty($datos['folio'])) {
            $folioData      = $this->folioManager->siguienteFolio($tipoDte);
            $datos['folio'] = $folioData['folio'];
        }
        if (empty($datos['folio'])) {
            throw new SiiException(
                "Falta el folio. Inyecta FolioManager o pasa 'folio' en los datos."
            );
        }

        $dteXml = $this->builder->build($tipoDte, $datos);

        $dteFirmado = $this->signer->signDte($dteXml);

        $envioXml     = EnvelopeBuilder::build($dteFirmado, $this->config, $datos['receptor']);
        $envioFirmado = $this->signer->signEnvelope($envioXml);

        $token   = $this->getToken();
        $trackId = $this->webService->uploadDte(
            $this->config['rut_emisor'],
            $this->config['dv_emisor'],
            $envioFirmado,
            $token
        );

        $dteId   = null;
        $totales = $this->builder->calcularTotales($tipoDte, $datos['detalle']);
        if ($this->dteRepository !== null) {
            $dteId = $this->dteRepository->guardar([
                'tipo_dte'              => $tipoDte,
                'folio'                 => $datos['folio'],
                'rut_emisor'            => $this->config['rut_emisor'] . '-' . $this->config['dv_emisor'],
                'rut_receptor'          => $datos['receptor']['rut'] . '-' . $datos['receptor']['dv'],
                'razon_social_receptor' => $datos['receptor']['razon_social'] ?? '',
                'fecha_emision'         => $datos['fecha'],
                'monto_neto'            => $totales['neto'] ?? 0,
                'monto_iva'             => $totales['iva'] ?? 0,
                'monto_exento'          => $totales['exento'] ?? 0,
                'monto_total'           => $totales['total'],
                'track_id'              => $trackId,
                'xml_firmado'           => $envioFirmado,
                'estado'                => 'pendiente',
            ]);
        }

        return [
            'trackId' => $trackId,
            'folio'   => (int) $datos['folio'],
            'xml'     => $envioFirmado,
            'dte_id'  => $dteId,
        ];
    }

    /**
     * Genera el XML firmado sin enviarlo (para preview o integración externa).
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array $datos Datos del documento
     * @return string XML del sobre firmado
     */
    public function generarXml(int $tipoDte, array $datos): string
    {
        if ($this->folioManager !== null && empty($datos['folio'])) {
            $folioData      = $this->folioManager->siguienteFolio($tipoDte);
            $datos['folio'] = $folioData['folio'];
        }
        $dteXml     = $this->builder->build($tipoDte, $datos);
        $dteFirmado = $this->signer->signDte($dteXml);
        $envioXml   = EnvelopeBuilder::build($dteFirmado, $this->config, $datos['receptor']);
        return $this->signer->signEnvelope($envioXml);
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
     * @return array Resultado de la consulta al SII
     */
    public function consultarEstado(string $trackId): array
    {
        $estado = $this->webService->getStatus(
            $this->config['rut_emisor'],
            $this->config['dv_emisor'],
            $trackId,
            $this->getToken()
        );

        // Actualizar BD si tenemos repositorio y el estado es final
        if ($this->dteRepository !== null && in_array($estado['estado'], ['EPR', 'RSC'])) {
            $estadoBd = $estado['estado'] === 'EPR' ? 'aceptado' : 'rechazado';
            $this->dteRepository->actualizarEstado($trackId, $estadoBd, $estado['glosa']);
        }

        return $estado;
    }

    /**
     * Procesa todos los DTE pendientes y actualiza su estado.
     * Llama desde un cron job cada 15 minutos.
     * Requiere DteRepository inyectado.
     *
     * @return array Resumen de resultados del procesamiento
     * @throws SiiException Si no se ha inyectado el repositorio
     */
    public function procesarPendientes(): array
    {
        if ($this->dteRepository === null) {
            throw new SiiException('Necesitas inyectar DteRepository con usarRepositorio().');
        }
        $pendientes = $this->dteRepository->pendientes();
        $resultados = [];
        foreach ($pendientes as $dte) {
            try {
                $estado = $this->consultarEstado($dte['track_id']);
                $resultados[] = ['folio' => $dte['folio'], 'tipo' => $dte['tipo_dte'], 'estado' => $estado['estado'], 'glosa' => $estado['glosa']];
            } catch (\Throwable $e) {
                $resultados[] = ['folio' => $dte['folio'], 'tipo' => $dte['tipo_dte'], 'error' => $e->getMessage()];
            }
        }
        return $resultados;
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
    public function consultarDte(string $rutReceptor, string $dvReceptor, int $tipoDte, int $folio, string $fechaEmision, int $montoPesos): array
    {
        return $this->webService->queryDte(
            $this->config['rut_emisor'],
            $this->config['dv_emisor'],
            $rutReceptor,
            $dvReceptor,
            $tipoDte,
            $folio,
            $fechaEmision,
            $montoPesos,
            $this->getToken()
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

    /**
     * Valida la configuración inicial del cliente.
     *
     * @param array $config Datos de configuración
     * @throws SiiException Si falta algún parámetro obligatorio
     * @return void
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
