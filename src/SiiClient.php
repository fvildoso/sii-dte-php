<?php

declare(strict_types=1);

namespace SiiDte;

use SiiDte\Auth\TokenManager;
use SiiDte\Document\DteBuilder;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\FolioManager;
use SiiDte\Pdf\PdfGenerator;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteRepository;
use SiiDte\Utils\HttpTransport;
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

    /**
     * @param SiiConfigShape $config
     */
    public function __construct(
        array $config,
        CertificateStore $certStore,
        ?HttpTransport $transport = null,
        int $timeoutSeconds = 60,
    ) {
        $this->validateConfig($config);
        $this->config       = $config;
        $this->signer       = new XmlSigner($certStore);
        $this->tokenManager = new TokenManager($this->signer, $config['ambiente'], $transport, $timeoutSeconds);
        $this->webService   = new SiiWebService($config['ambiente'], $transport, $timeoutSeconds);
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
     * @throws SiiException
     */
    public static function fromEnv(
        array $overrides = [],
        ?HttpTransport $transport = null,
        int $timeoutSeconds = 60,
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

        return new self($config, $certStore, $transport, $timeoutSeconds);
    }

    /**
     * @deprecated La asignación automática está deshabilitada; la aplicación reserva el folio.
     * @throws SiiException Siempre
     */
    public function usarFolioManager(FolioManager $fm): static
    {
        throw new SiiException(
            'FolioManager ya no puede inyectarse: reserve el folio en el almacén transaccional de la aplicación.'
        );
    }

    /**
     * @deprecated La emisión está bloqueada y no persiste DTE a través de SiiClient.
     * @throws SiiException Siempre
     */
    public function usarRepositorio(DteRepository $repo): static
    {
        throw new SiiException(
            'DteRepository no se usa por SiiClient mientras la emisión esté deshabilitada; persista el flujo desde la aplicación.'
        );
    }

    // Emisión

    /**
     * La emisión permanece deshabilitada hasta completar y verificar el flujo oficial.
     *
     * No reserva folios, obtiene tokens ni realiza solicitudes de red.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array $datos Datos del documento a emitir
     * @throws SiiException Siempre, mientras no exista evidencia local y de certificación
     */
    public function enviarDte(int $tipoDte, array $datos): array
    {
        throw new SiiException(
            'La emisión integrada está deshabilitada: el constructor factura 33 disponible produce un XML parcial, '
            . 'no un DTE completo validado para el SII. '
            . 'Los componentes TED, firma, XSD y transporte probados por separado no constituyen un flujo integrado. '
            . 'No se reservó ningún folio ni se contactó al SII.'
        );
    }

    /**
     * Genera una vista previa XML no firmada y no apta para emisión.
     *
     * @param int $tipoDte Código del tipo de DTE
     * @param array $datos Datos del documento
     * @return string XML de borrador, sin TED, firma, esquema ni validación integral
     */
    public function previsualizarXml(int $tipoDte, array $datos): string
    {
        if (!isset($datos['folio']) || !is_int($datos['folio']) || $datos['folio'] <= 0) {
            throw new SiiException(
                'La vista previa requiere un folio explícito suministrado por la aplicación; no reserva folios.'
            );
        }

        return $this->builder->build($tipoDte, $datos);
    }

    /**
     * @deprecated Use previsualizarXml(); this method no longer returns a signed envelope.
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

        // El estado de envío es de nivel sobre y no acredita el resultado individual de cada DTE.
        return $estado;
    }

    /**
     * El procesamiento automático heredado está deshabilitado.
     *
     * La aplicación debe conciliar resultados individuales con su propio repositorio.
     *
     * @throws SiiException Siempre
     */
    public function procesarPendientes(): array
    {
        throw new SiiException(
            'El procesamiento automático de pendientes está deshabilitado: SiiClient no persiste ni concilia DTE.'
        );
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
