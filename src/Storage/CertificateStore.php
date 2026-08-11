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
    private string $certPath;
    private string $certPassword;
    private ?array $parsedCert = null;

    /**
     * OPCIÓN B: Carga el certificado desde un archivo en disco.
     *
     * @param string $certPath     Ruta absoluta al .p12 (FUERA del webroot)
     * @param string $certPassword Contraseña del certificado
     *
     * Ejemplo de uso correcto:
     *   $store = CertificateStore::fromFile('/var/secure/certs/empresa.p12', getenv('CERT_PASS'));
     *
     * Ejemplo de uso INCORRECTO (¡nunca hacer esto!):
     *   $store = CertificateStore::fromFile('/var/www/html/public/cert.p12', '12345');
     */
    public static function fromFile(string $certPath, string $certPassword): self
    {
        $store = new self();
        $store->certPath     = $certPath;
        $store->certPassword = $certPassword;
        $store->validate();
        return $store;
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
     * @param string $b64EnvVar   Nombre de la env var con el cert en base64 (default: SII_CERT_B64)
     * @param string $passEnvVar  Nombre de la env var con la contraseña (default: SII_CERT_PASS)
     */
    public static function fromEnv(
        string $b64EnvVar  = 'SII_CERT_B64',
        string $passEnvVar = 'SII_CERT_PASS'
    ): self {
        $b64  = getenv($b64EnvVar);
        $pass = getenv($passEnvVar);

        if (!$b64 || !$pass) {
            throw new SiiException(
                "Las variables de entorno {$b64EnvVar} y/o {$passEnvVar} no están definidas. "
                . "Agrégalas a tu .env o al entorno del servidor."
            );
        }

        $certContent = base64_decode($b64, strict: true);
        if ($certContent === false) {
            throw new SiiException("La variable {$b64EnvVar} no contiene un base64 válido.");
        }

        // Escribir temporalmente en /tmp con permisos 600
        $tmpPath = sys_get_temp_dir() . '/sii_cert_' . md5($b64) . '.p12';
        if (!file_exists($tmpPath)) {
            file_put_contents($tmpPath, $certContent);
            chmod($tmpPath, 0600);
        }

        $store = new self();
        $store->certPath     = $tmpPath;
        $store->certPassword = $pass;
        $store->validate();
        return $store;
    }

    /**
     * Retorna la ruta al archivo .p12 en disco.
     */
    public function getPath(): string
    {
        return $this->certPath;
    }

    /**
     * Retorna la contraseña del certificado.
     */
    public function getPassword(): string
    {
        return $this->certPassword;
    }

    /**
     * Retorna información del certificado (RUT, razón social, vencimiento).
     * Útil para mostrar en el panel de administración.
     */
    public function getInfo(): array
    {
        if ($this->parsedCert !== null) {
            return $this->parsedCert;
        }

        $content = file_get_contents($this->certPath);
        openssl_pkcs12_read($content, $certs, $this->certPassword);

        $certInfo = openssl_x509_parse($certs['cert'] ?? '');

        $this->parsedCert = [
            'subject'      => $certInfo['subject'] ?? [],
            'issuer'       => $certInfo['issuer'] ?? [],
            'valid_from'   => date('Y-m-d H:i:s', $certInfo['validFrom_time_t'] ?? 0),
            'valid_to'     => date('Y-m-d H:i:s', $certInfo['validTo_time_t'] ?? 0),
            'dias_vigencia' => (int) (($certInfo['validTo_time_t'] - time()) / 86400),
            'vencido'      => time() > ($certInfo['validTo_time_t'] ?? 0),
        ];

        return $this->parsedCert;
    }

    /**
     * Devuelve true si el certificado vence en menos de $dias días.
     * Usa esto para enviar alertas de renovación.
     */
    public function venceProximo(int $dias = 30): bool
    {
        $info = $this->getInfo();
        return $info['dias_vigencia'] < $dias;
    }


    private function __construct() {}

    private function validate(): void
    {
        if (!file_exists($this->certPath)) {
            throw new SiiException("Certificado no encontrado en: {$this->certPath}");
        }

        if (!is_readable($this->certPath)) {
            throw new SiiException(
                "El certificado no tiene permisos de lectura: {$this->certPath}\n"
                . "Ejecuta: chmod 640 {$this->certPath}"
            );
        }

        // Advertencia si está en una ruta pública común
        $publicPaths = ['/public/', '/www/', '/html/', '/htdocs/', '/webroot/'];
        foreach ($publicPaths as $pub) {
            if (str_contains($this->certPath, $pub)) {
                throw new SiiException(
                    "⚠️  RIESGO DE SEGURIDAD: El certificado está en una ruta pública ({$this->certPath}). "
                    . "Muévelo fuera del webroot, por ejemplo a /var/secure/certs/"
                );
            }
        }

        $content = file_get_contents($this->certPath);
        if (!openssl_pkcs12_read($content, $certs, $this->certPassword)) {
            throw new SiiException(
                "No se pudo abrir el certificado. Verifica que:\n"
                . "  - El archivo sea un .p12 / .pfx válido\n"
                . "  - La contraseña sea correcta\n"
                . "Error OpenSSL: " . openssl_error_string()
            );
        }

        $certInfo = openssl_x509_parse($certs['cert']);
        if (time() > ($certInfo['validTo_time_t'] ?? 0)) {
            $vencio = date('Y-m-d', $certInfo['validTo_time_t']);
            throw new SiiException(
                "El certificado digital venció el {$vencio}. "
                . "Renuévalo con tu proveedor (E-CERT Chile, E-Sign, etc.)."
            );
        }
    }
}
