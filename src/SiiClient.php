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
 * ════════════════════════════════════════════════════════════
 *  LO QUE HACE ESTA LIBRERÍA:
 *    ✅ Autenticación SII (semilla → token, renovación automática)
 *    ✅ Generación XML DTE todos los tipos (33,34,39,41,46,52,56,61,110,111,112)
 *    ✅ Cálculo automático de neto, IVA 19% y total
 *    ✅ Firma digital XMLDSig del DTE y del sobre EnvioDTE
 *    ✅ Envío al SII (certificación y producción)
 *    ✅ Consulta de estado por Track ID
 *    ✅ Administración de folios CAF con bloqueo de concurrencia
 *    ✅ Validación del certificado digital (vencimiento, path seguro)
 *    ✅ Persistencia del XML firmado, Track ID y estado en la BD
 *    ✅ Generación de HTML renderizable a PDF
 *
 *  LO QUE DEBE HACER TU APLICACIÓN:
 *    ❌ Ejecutar database/migrations.sql para crear las tablas
 *    ❌ Importar el CAF: FolioManager::importarCaf()
 *    ❌ Alertar cuando los folios estén por agotarse
 *    ❌ Renovar el certificado digital antes de que venza
 *    ❌ Cron de actualización: $client->procesarPendientes()
 *    ❌ Instalar dompdf para generar el PDF final
 *    ❌ Solicitar nuevos CAF al SII cuando se agoten
 * ════════════════════════════════════════════════════════════
 */
class SiiClient
{
    private TokenManager  $tokenManager;
    private XmlSigner     $signer;
    private SiiWebService $webService;
    private DteBuilder    $builder;
    private array         $config;
    private ?FolioManager  $folioManager  = null;
    private ?DteRepository $dteRepository = null;

    public function __construct(array $config, CertificateStore $certStore)
    {
        $this->validateConfig($config);
        $this->config       = $config;
        $this->signer       = new XmlSigner($certStore->getPath(), $certStore->getPassword());
        $this->tokenManager = new TokenManager($this->signer, $config['ambiente']);
        $this->webService   = new SiiWebService($config['ambiente']);
        $this->builder      = new DteBuilder($config);
    }

    /** Inyecta FolioManager para asignación automática de folios. */
    public function usarFolioManager(FolioManager $fm): static
    {
        $this->folioManager = $fm;
        return $this;
    }

    /** Inyecta DteRepository para persistir los DTE en BD. */
    public function usarRepositorio(DteRepository $repo): static
    {
        $this->dteRepository = $repo;
        return $this;
    }

    // =========================================================================
    // EMISIÓN
    // =========================================================================

    /**
     * Genera, firma y envía un DTE al SII.
     *
     * Si se inyectó FolioManager, el folio se asigna automáticamente.
     *
     * @return array {trackId, folio, xml, dte_id}
     */
    public function enviarDte(int $tipoDte, array $datos): array
    {
        // 1. Asignar folio automáticamente si hay FolioManager
        if ($this->folioManager !== null && empty($datos['folio'])) {
            $folioData      = $this->folioManager->siguienteFolio($tipoDte);
            $datos['folio'] = $folioData['folio'];
        }
        if (empty($datos['folio'])) {
            throw new SiiException(
                "Falta el folio. Inyecta FolioManager o pasa 'folio' en los datos."
            );
        }

        // 2. Generar XML
        $dteXml = $this->builder->build($tipoDte, $datos);

        // 3. Firmar DTE
        $dteFirmado = $this->signer->signDte($dteXml);

        // 4. Construir y firmar sobre EnvioDTE
        $envioXml     = EnvelopeBuilder::build($dteFirmado, $this->config, $datos['receptor']);
        $envioFirmado = $this->signer->signEnvelope($envioXml);

        // 5. Enviar al SII
        $token   = $this->getToken();
        $trackId = $this->webService->uploadDte(
            $this->config['rut_emisor'],
            $this->config['dv_emisor'],
            $envioFirmado,
            $token
        );

        // 6. Persistir en BD
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
     */
    public function generarHtmlPdf(string $xmlFirmado, array $opciones = []): string
    {
        $gen = new PdfGenerator($this->config['ambiente'], $this->getToken());
        return $gen->toHtml($xmlFirmado, $opciones);
    }

    // =========================================================================
    // CONSULTAS
    // =========================================================================

    /**
     * Consulta el estado de un envío por Track ID.
     * El SII puede tardar de 2 a 15 minutos en procesar.
     *
     * Códigos de estado:
     *   EPR = Procesado correctamente   RSC = Rechazado
     *   SOK = Recibido, en proceso      DNK = No encontrado aún
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

    /** Obtiene o renueva el token de sesión SII. */
    public function getToken(): string
    {
        return $this->tokenManager->getToken($this->config['rut_emisor'], $this->config['dv_emisor']);
    }

    private function validateConfig(array $config): void
    {
        foreach (['rut_emisor', 'dv_emisor', 'razon_social', 'giro', 'direccion', 'ciudad', 'ambiente'] as $key) {
            if (empty($config[$key])) {
                throw new SiiException("Config requerida faltante: '{$key}'");
            }
        }
        if (!in_array($config['ambiente'], ['certificacion', 'produccion'])) {
            throw new SiiException("El ambiente debe ser 'certificacion' o 'produccion'");
        }
    }
}
