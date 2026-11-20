<?php

declare(strict_types=1);

namespace SiiDte\Storage;

use SiiDte\Exceptions\SiiException;

/**
 * Gestiona el almacenamiento SEGURO del certificado digital.
 *
 * ─── ¿POR QUÉ IMPORTA DÓNDE SE GUARDA EL CERTIFICADO? ─────────────────────
 *
 * El certificado digital (.p12) contiene tu clave privada. Si alguien lo
 * obtiene + la contraseña, puede firmar documentos tributarios a tu nombre.
 * Un certificado comprometido podría usarse para emitir facturas falsas.
 *
 * ─── OPCIONES DE ALMACENAMIENTO (de más a menos segura) ────────────────────
 *
 * OPCIÓN A — Variables de entorno (recomendada para desarrollo/staging)
 *   El certificado en base64 y la contraseña se guardan como env vars.
 *   Ventaja: No está en disco, no se versiona en Git.
 *   Desventaja: Aún visible en `printenv` si el servidor está comprometido.
 *
 * OPCIÓN B — Archivo fuera del webroot (mínimo recomendado para producción)
 *   El archivo .p12 vive en /var/secure/certs/, nunca en /var/www/html/.
 *   La contraseña viene de variable de entorno.
 *   Ventaja: Simple, funciona en cualquier VPS.
 *
 * OPCIÓN C — AWS Secrets Manager / HashiCorp Vault (recomendado para SaaS)
 *   El .p12 y contraseña viven en un vault cifrado, con auditoría de accesos.
 *   Ventaja: Rotación automática, logs de quién accedió y cuándo.
 *   Desventaja: Requiere infraestructura adicional.
 *
 * OPCIÓN D — Hardware Security Module (HSM) (para grandes volúmenes)
 *   La clave privada nunca sale del dispositivo físico. Máxima seguridad.
 *
 * ─── LO QUE ESTA CLASE HACE ────────────────────────────────────────────────
 *   ✅ Carga el certificado desde cualquiera de las opciones A o B
 *   ✅ Valida que el archivo exista, sea legible y no esté vencido
 *   ✅ Verifica que la contraseña sea correcta
 *   ✅ Previene que el path apunte al webroot
 *
 * ─── LO QUE DEBES HACER TÚ ─────────────────────────────────────────────────
 *   ❌ Configurar los permisos del archivo: chmod 600 cert.p12
 *   ❌ Asegurarte que el archivo NO está en el directorio público (public/ o www/)
 *   ❌ NO commitearlo al repositorio Git (agregar *.p12 al .gitignore)
 *   ❌ Para Opción C/D: implementar el adaptador a tu vault
 */
class CertificateStore
{
    private ?string $certPath = null;
    private string $certPassword;
    private string $pkcs12Data;
    /** @var array<string, mixed>|null */
    private ?array $certificateInfo = null;
    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $clock;

    /**
     * OPCIÓN B: Carga el certificado desde un archivo en disco.
     *
     * @param string $certPath Ruta absoluta al .p12 (FUERA del webroot)
     * @param string $certPassword Contraseña del certificado
     * @throws SiiException Si el archivo no existe, no es legible, es inseguro o ha vencido
     *
     * Ejemplo de uso correcto:
     *   $store = CertificateStore::fromFile('/var/secure/certs/empresa.p12', getenv('CERT_PASS'));
     *
     * Ejemplo de uso INCORRECTO (¡nunca hacer esto!):
     *   $store = CertificateStore::fromFile('/var/www/html/public/cert.p12', '12345');
     * @return self Instancia de CertificateStore
     */
    public static function fromFile(string $certPath, string $certPassword, ?\Closure $clock = null): self
    {
        if (!is_file($certPath) || !is_readable($certPath)) {
            throw new SiiException('Certificado no encontrado o no legible.');
        }

        $resolvedPath = realpath($certPath);
        if ($resolvedPath === false) {
            throw new SiiException('No se pudo resolver la ruta del certificado.');
        }
        self::assertPrivatePath($certPath);
        self::assertPrivatePath($resolvedPath);

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            throw new SiiException('No se pudo leer el certificado.');
        }

        return self::fromPkcs12Content($content, $certPassword, $clock, $resolvedPath);
    }

    /**
     * Carga y valida el PKCS#12 directamente desde memoria, sin crear archivos temporales.
     *
     * @throws SiiException Si el contenido, la contraseña o la vigencia no son válidos
     */
    public static function fromBytes(string $pkcs12Data, string $certPassword, ?\Closure $clock = null): self
    {
        return self::fromPkcs12Content($pkcs12Data, $certPassword, $clock);
    }

    /**
     * Decodifica y carga un PKCS#12 codificado en Base64 sin escribirlo a disco.
     *
     * @throws SiiException Si el Base64 no es válido o el certificado no puede validarse
     */
    public static function fromBase64(string $base64, string $certPassword, ?\Closure $clock = null): self
    {
        $pkcs12Data = base64_decode($base64, true);
        if ($pkcs12Data === false) {
            throw new SiiException('El certificado no contiene un base64 válido.');
        }

        return self::fromBytes($pkcs12Data, $certPassword, $clock);
    }

    /**
     * OPCIÓN A: Carga el certificado desde variables de entorno.
     *
     * En tu .env:
     *   SII_CERT_B64=<contenido del .p12 en base64>
     *   SII_CERT_PASS=mi_contraseña_segura
     *
     * Para generar el base64 del .p12:
     *   base64 -w 0 mi_certificado.p12
     *
     * @param string $b64EnvVar Nombre de la env var con el cert en base64 (default: SII_CERT_B64)
     * @param string $passEnvVar Nombre de la env var con la contraseña (default: SII_CERT_PASS)
     * @throws SiiException Si las variables de entorno no están definidas o el base64 es inválido
     * @return self Instancia de CertificateStore
     */
    public static function fromEnv(
        string $b64EnvVar  = 'SII_CERT_B64',
        string $passEnvVar = 'SII_CERT_PASS',
        ?\Closure $clock = null,
    ): self {
        $b64  = getenv($b64EnvVar);
        $pass = getenv($passEnvVar);

        if ($b64 === false || $b64 === '' || $pass === false) {
            throw new SiiException(
                "Las variables de entorno {$b64EnvVar} y/o {$passEnvVar} no están definidas. "
                . 'Agrégalas a tu .env o al entorno del servidor.'
            );
        }

        return self::fromBase64($b64, $pass, $clock);
    }

    /**
     * Retorna la ruta al archivo .p12 en disco.
     *
     * @return string Ruta al certificado
     */
    public function getPath(): string
    {
        if ($this->certPath === null) {
            throw new SiiException('El certificado se cargó en memoria y no tiene una ruta de archivo.');
        }

        return $this->certPath;
    }

    /**
     * Entrega la copia PKCS#12 validada que debe mantenerse confidencial.
     * Se usa internamente para inicializar el firmador sin volver a leer el archivo.
     */
    public function getPkcs12Data(): string
    {
        return $this->pkcs12Data;
    }

    /**
     * Retorna la contraseña del certificado.
     *
     * @return string Contraseña del certificado
     */
    public function getPassword(): string
    {
        return $this->certPassword;
    }

    /**
     * Retorna información del certificado (RUT, razón social, vencimiento).
     * Útil para mostrar en el panel de administración.
     *
     * @return array Información detallada del certificado
     */
    public function getInfo(): array
    {
        $certInfo = $this->certificateInfo;
        if ($certInfo === null) {
            throw new SiiException('No hay información validada del certificado.');
        }

        $now = $this->clock()->getTimestamp();
        $validFrom = (int) $certInfo['validFrom_time_t'];
        $validTo = (int) $certInfo['validTo_time_t'];

        return [
            'subject'      => $certInfo['subject'] ?? [],
            'issuer'       => $certInfo['issuer'] ?? [],
            'valid_from'   => date('Y-m-d H:i:s', $validFrom),
            'valid_to'     => date('Y-m-d H:i:s', $validTo),
            'dias_vigencia' => (int) (($validTo - $now) / 86400),
            'vigente_desde' => $now >= $validFrom,
            'aun_no_vigente' => $now < $validFrom,
            'vencido'      => $now > $validTo,
            'vigente'      => $now >= $validFrom && $now <= $validTo,
        ];
    }

    /**
     * Devuelve true si el certificado vence en menos de $dias días.
     * Usa esto para enviar alertas de renovación.
     *
     * @param int $dias Cantidad de días para considerar vencimiento próximo
     * @return bool True si vence dentro del plazo especificado
     */
    public function venceProximo(int $dias = 30): bool
    {
        $info = $this->getInfo();

        return $info['dias_vigencia'] < $dias;
    }

    private function __construct(?\Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * Valida la integridad y seguridad del certificado.
     *
     * @throws SiiException Si el certificado no es válido o es inseguro
     */
    private static function fromPkcs12Content(
        string $content,
        string $certPassword,
        ?\Closure $clock,
        ?string $certPath = null,
    ): self {
        $store = new self($clock);
        $store->certPath = $certPath;
        $store->certPassword = $certPassword;
        $store->pkcs12Data = $content;
        $store->validatePkcs12();

        return $store;
    }

    /**
     * @throws SiiException Si el PKCS#12, la clave privada o sus fechas no son válidas
     */
    private function validatePkcs12(): void
    {
        self::clearOpenSslErrors();

        if (!@openssl_pkcs12_read($this->pkcs12Data, $certs, $this->certPassword)) {
            self::clearOpenSslErrors();

            throw new SiiException('No se pudo abrir el certificado PKCS#12. Verifica el archivo y la contraseña.');
        }

        $certificate = $certs['cert'] ?? null;
        $privateKey = $certs['pkey'] ?? null;
        if (!is_string($certificate) || !is_string($privateKey)) {
            throw new SiiException('El PKCS#12 no contiene un certificado y una clave privada utilizables.');
        }

        self::assertMatchingKey($certificate, $privateKey);

        $certInfo = @openssl_x509_parse($certificate);
        if ($certInfo === false
            || !isset($certInfo['validFrom_time_t'], $certInfo['validTo_time_t'])
            || !is_int($certInfo['validFrom_time_t'])
            || !is_int($certInfo['validTo_time_t'])
            || $certInfo['validTo_time_t'] < $certInfo['validFrom_time_t']
        ) {
            throw new SiiException('No se pudieron validar las fechas del certificado.');
        }

        $this->certificateInfo = $certInfo;
        $now = $this->clock()->getTimestamp();
        if ($now < $certInfo['validFrom_time_t']) {
            throw new SiiException('El certificado digital aún no está vigente.');
        }
        if ($now > $certInfo['validTo_time_t']) {
            throw new SiiException('El certificado digital ha vencido.');
        }
    }

    private function clock(): \DateTimeImmutable
    {
        return ($this->clock)();
    }

    private static function assertMatchingKey(string $certificate, string $privateKey): void
    {
        if (!@openssl_x509_check_private_key($certificate, $privateKey)) {
            self::clearOpenSslErrors();

            throw new SiiException('La clave privada no corresponde al certificado del PKCS#12.');
        }
    }

    private static function clearOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
        }
    }

    private static function assertPrivatePath(string $resolvedPath): void
    {
        $segments = preg_split('~[\\\\/]+~', strtolower($resolvedPath));
        $publicDirectoryNames = ['public', 'www', 'web', 'html', 'htdocs', 'webroot'];

        foreach ($segments ?: [] as $segment) {
            if (in_array($segment, $publicDirectoryNames, true)) {
                throw new SiiException('El certificado está ubicado en un directorio público; almacénalo fuera del webroot.');
            }
        }
    }
}
