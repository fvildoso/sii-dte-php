<?php

declare(strict_types=1);

use SiiDte\Auth\TokenManager;
use SiiDte\Document\SiiSchemaValidator;
use SiiDte\Document\TedBuilder;
use SiiDte\Envelope\EnvelopeBuilder;
use SiiDte\Exceptions\TransportException;
use SiiDte\Folio\CafInspector;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;
use SiiDte\Utils\RutHelper;
use SiiDte\Utils\SiiWebService;

require dirname(__DIR__) . '/vendor/autoload.php';

function requiredEnv(string $name): string
{
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        throw new RuntimeException("Falta la variable de entorno requerida {$name}.");
    }

    return $value;
}

function privatePath(string $path): string
{
    $resolved = realpath($path);
    if ($resolved === false) {
        throw new RuntimeException('No se pudo resolver una ruta privada de entrada/salida.');
    }

    $segments = preg_split('~[\\\\/]+~', strtolower($resolved));
    if (is_array($segments) && array_intersect($segments, ['public', 'www', 'web']) !== []) {
        throw new RuntimeException('El ejemplo rechaza rutas bajo directorios web públicos.');
    }

    return $resolved;
}

function readPrivateFile(string $environmentVariable, int $maximumBytes): string
{
    $path = privatePath(requiredEnv($environmentVariable));
    $size = filesize($path);
    if ($size === false || $size < 1 || $size > $maximumBytes) {
        throw new RuntimeException("El archivo indicado por {$environmentVariable} está vacío o supera el límite.");
    }

    $contents = file_get_contents($path);
    if (!is_string($contents) || strlen($contents) !== $size) {
        throw new RuntimeException("No se pudo leer de forma completa el archivo indicado por {$environmentVariable}.");
    }

    return $contents;
}

function dteText(string $xml, string $localName): string
{
    if (strlen($xml) > 5 * 1024 * 1024 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
        throw new RuntimeException('El DTE de entrada está vacío, supera el tamaño permitido o declara entidades.');
    }

    $previousErrorMode = libxml_use_internal_errors(true);
    libxml_clear_errors();

    try {
        $document = new DOMDocument();
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT) || $document->doctype !== null) {
            throw new RuntimeException('El DTE de entrada no es XML seguro y válido.');
        }

        $root = $document->documentElement;
        if (!$root instanceof DOMElement || $root->localName !== 'DTE' || $root->namespaceURI !== 'http://www.sii.cl/SiiDte') {
            throw new RuntimeException('La entrada debe ser un DTE SII individual.');
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('s', 'http://www.sii.cl/SiiDte');
        $nodes = $xpath->query('/s:DTE/s:Documento/s:Encabezado/s:Receptor/s:' . $localName);
        if ($nodes === false || $nodes->length !== 1 || trim($nodes->item(0)?->textContent ?? '') === '') {
            throw new RuntimeException("El DTE no contiene exactamente un campo {$localName} requerido.");
        }

        return trim($nodes->item(0)->textContent);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
    }
}

function writePrivateFile(string $path, string $contents): void
{
    $directory = realpath(dirname($path));
    if ($directory === false) {
        throw new RuntimeException('El directorio privado de salida debe existir.');
    }
    $fullPath = $directory . DIRECTORY_SEPARATOR . basename($path);
    $segments = preg_split('~[\\\\/]+~', strtolower($fullPath));
    if (is_array($segments) && array_intersect($segments, ['public', 'www', 'web']) !== []) {
        throw new RuntimeException('El sobre firmado no se puede guardar bajo directorios web públicos.');
    }

    $handle = @fopen($fullPath, 'x+b');
    if (!is_resource($handle)) {
        throw new RuntimeException('No se creó el archivo de salida; el destino debe ser privado y no existir previamente.');
    }

    $written = 0;

    try {
        while ($written < strlen($contents)) {
            $count = fwrite($handle, substr($contents, $written));
            if ($count === false || $count === 0) {
                throw new RuntimeException('No se pudo guardar completo el sobre firmado.');
            }
            $written += $count;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('No se pudo confirmar la escritura del sobre firmado.');
        }
    } catch (Throwable $exception) {
        fclose($handle);
        @unlink($fullPath);

        throw $exception;
    }

    fclose($handle);
    @chmod($fullPath, 0600);
}

$action = $argv[1] ?? '';
if (!in_array($action, ['prepare', 'send'], true)) {
    throw new RuntimeException('Uso: php examples/certificacion_factura33.php prepare|send');
}

$ambiente = 'certificacion';
$xmlPath = requiredEnv('SII_CERTIFICACION_XML_FIRMADO_PATH');
$validator = new SiiSchemaValidator();

if ($action === 'prepare') {
    $folioValue = requiredEnv('SII_FOLIO_RESERVADO');
    $folio = filter_var($folioValue, FILTER_VALIDATE_INT);
    if (!is_int($folio) || $folio <= 0) {
        throw new RuntimeException('SII_FOLIO_RESERVADO debe ser un entero positivo previamente asignado.');
    }

    $sourceDte = readPrivateFile('SII_DTE33_SIN_TED_PATH', 5 * 1024 * 1024);
    $cafXml = readPrivateFile('SII_CAF_PATH', 2 * 1024 * 1024);
    $certificate = CertificateStore::fromFile(
        privatePath(requiredEnv('SII_CERTIFICADO_PATH')),
        requiredEnv('SII_CERTIFICADO_PASS')
    );

    $now = new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
    $ted = new TedBuilder(new CafInspector());
    $stampedDte = $ted->addTedToDte(
        $sourceDte,
        $cafXml,
        $folio,
        $now->format('Y-m-d\TH:i:s'),
        $now->modify('+1 second')->format('Y-m-d\TH:i:s')
    );
    $tedResult = $ted->verify($stampedDte);
    if ($tedResult['frmt_verificada'] !== true || $tedResult['datos_coinciden'] !== true
        || $tedResult['llave_timbrado_corresponde'] !== true
    ) {
        throw new RuntimeException('El TED no superó las verificaciones locales; no se generó un sobre.');
    }

    $signer = new XmlSigner($certificate);
    $signedDte = $signer->signDte($stampedDte);
    $validator->validate($signedDte, 'dte');

    $receiverRut = dteText($signedDte, 'RUTRecep');
    $receiver = RutHelper::parse($receiverRut);
    if (!RutHelper::validate($receiverRut)) {
        throw new RuntimeException('El RUT del receptor del DTE no es válido.');
    }

    $issuerRut = requiredEnv('SII_RUT_EMISOR');
    $issuerDv = requiredEnv('SII_DV_EMISOR');
    $senderRut = requiredEnv('SII_RUT_ENVIADOR');
    if (!RutHelper::validate($senderRut)) {
        throw new RuntimeException('SII_RUT_ENVIADOR debe ser un RUT completo válido del titular que transmite.');
    }
    $emisor = [
        'rut_emisor'        => $issuerRut,
        'dv_emisor'         => $issuerDv,
        'razon_social'      => requiredEnv('SII_RAZON_SOCIAL'),
        'giro'              => requiredEnv('SII_GIRO'),
        'codigo_actividad'  => requiredEnv('SII_CODIGO_ACTIVIDAD'),
        'direccion'         => requiredEnv('SII_DIRECCION'),
        'ciudad'            => requiredEnv('SII_CIUDAD'),
        'fecha_resolucion'  => requiredEnv('SII_FECHA_RESOLUCION'),
        'numero_resolucion' => (int) requiredEnv('SII_NUMERO_RESOLUCION'),
    ];
    $receptor = ['rut' => $receiver['rut'], 'dv' => $receiver['dv']];
    $envelope = EnvelopeBuilder::build(
        $signedDte,
        $emisor,
        $receptor,
        $senderRut,
        $now,
        requiredEnv('SII_RUT_RECEPTOR_SOBRE')
    );
    $signedEnvelope = $signer->signEnvelope($envelope);
    $validator->validate($signedEnvelope, 'envio_dte');

    writePrivateFile($xmlPath, $signedEnvelope);
    fwrite(STDOUT, "Sobre firmado y validado localmente; no se contactó al SII.\n");
    fwrite(STDOUT, 'SHA-256=' . hash('sha256', $signedEnvelope) . "\n");
    fwrite(STDOUT, "Guarda el sobre y su hash en la persistencia/auditoría de la aplicación antes de ejecutar send.\n");
    fwrite(STDOUT, "FRMA se copió del CAF sin verificarla localmente; la aceptación de Maullín no se ha probado.\n");

    exit(0);
}

if (requiredEnv('SII_CERTIFICACION_CONFIRMAR_ENVIO') !== 'MAULLIN'
    || requiredEnv('SII_CERTIFICACION_INTENTO_PERSISTIDO') !== 'SI'
    || !hash_equals(
        strtolower(requiredEnv('SII_CERTIFICACION_XML_SHA256')),
        strtolower(hash_file('sha256', privatePath($xmlPath)) ?: '')
    )
) {
    throw new RuntimeException(
        'Para send se exige confirmar MAULLIN, persistir antes la intención y proporcionar el SHA-256 previamente registrado.'
    );
}

$signedEnvelope = readPrivateFile('SII_CERTIFICACION_XML_FIRMADO_PATH', 5 * 1024 * 1024);
$validator->validate($signedEnvelope, 'envio_dte');
$issuerRut = requiredEnv('SII_RUT_EMISOR');
$issuerDv = requiredEnv('SII_DV_EMISOR');
$senderRut = requiredEnv('SII_RUT_ENVIADOR');
if (!RutHelper::validate($senderRut)) {
    throw new RuntimeException('SII_RUT_ENVIADOR debe ser un RUT completo válido del titular que transmite.');
}
$sender = RutHelper::parse($senderRut);
$certificate = CertificateStore::fromFile(
    privatePath(requiredEnv('SII_CERTIFICADO_PATH')),
    requiredEnv('SII_CERTIFICADO_PASS')
);
$signer = new XmlSigner($certificate);
$timeoutValue = filter_var(getenv('SII_TIMEOUT_SECONDS') ?: '30', FILTER_VALIDATE_INT);
if (!is_int($timeoutValue) || $timeoutValue < 1 || $timeoutValue > 120) {
    throw new RuntimeException('SII_TIMEOUT_SECONDS debe estar entre 1 y 120.');
}
$tokenManager = new TokenManager($signer, $ambiente, timeoutSeconds: $timeoutValue);
$service = new SiiWebService($ambiente, timeoutSeconds: $timeoutValue);
$token = $tokenManager->getToken($issuerRut, $issuerDv);

try {
    $trackId = $service->uploadDte(
        $issuerRut,
        $issuerDv,
        $signedEnvelope,
        $token,
        $sender['rut'],
        $sender['dv']
    );
} catch (TransportException $exception) {
    if ($exception->outcomeMayBeUnknown) {
        fwrite(STDERR, "Resultado remoto desconocido. No vuelvas a enviar; concilia con el SII antes de recuperar.\n");
        exit(2);
    }

    throw $exception;
}

fwrite(STDOUT, "Recepción confirmada por el endpoint de certificación.\n");
fwrite(STDOUT, "Track ID={$trackId}\n");
fwrite(STDOUT, "Registra el Track ID y consulta por separado el estado del sobre y el DTE.\n");
