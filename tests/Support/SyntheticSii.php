<?php

declare(strict_types=1);

namespace SiiDte\Tests\Support;

use SiiDte\Document\SiiSchemaValidator;
use SiiDte\Document\TedBuilder;
use SiiDte\Envelope\EnvelopeBuilder;
use SiiDte\Folio\CafInspector;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;

/**
 * Material criptográfico sintético para pruebas: CAF ficticio (FRMA dummy), certificado efímero
 * y el flujo timbre → firma → sobre → XSD. Nada de esto es válido ante el SII.
 */
final class SyntheticSii
{
    public const RUT_EMISOR = '97975000-5';

    private static ?self $instance = null;

    private function __construct(
        private readonly \OpenSSLAsymmetricKey $key,
        private readonly string $privatePem,
        private readonly string $publicPem,
        private readonly array $rsa,
        private readonly string $configPath,
    ) {}

    public static function get(): self
    {
        if (self::$instance === null) {
            $config = tempnam(sys_get_temp_dir(), 'sii-synth-');
            file_put_contents($config, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $config]);
            if ($key === false) {
                throw new \RuntimeException('No se pudo generar la llave RSA sintética.');
            }
            $private = '';
            openssl_pkey_export($key, $private, null, ['config' => $config]);
            $details = openssl_pkey_get_details($key);
            self::$instance = new self($key, $private, $details['key'], $details['rsa'], $config);
        }

        return self::$instance;
    }

    public function caf(int $tipo, string $rut = self::RUT_EMISOR, int $desde = 1, int $hasta = 500, string $fa = '2026-11-01'): string
    {
        $modulus = chunk_split(base64_encode($this->rsa['n']), 64, "\n");
        $exponent = base64_encode($this->rsa['e']);

        return '<AUTORIZACION><CAF version="1.0"><DA>'
            . "<RE>{$rut}</RE><RS>Empresa de prueba</RS><TD>{$tipo}</TD>"
            . "<RNG><D>{$desde}</D><H>{$hasta}</H></RNG><FA>{$fa}</FA>"
            . "<RSAPK><M>{$modulus}</M><E>{$exponent}</E></RSAPK><IDK>100</IDK>"
            . '</DA><FRMA algoritmo="SHA1withRSA">' . base64_encode(str_repeat('A', 32)) . '</FRMA></CAF>'
            . '<RSASK>' . htmlspecialchars($this->privatePem, ENT_XML1, 'UTF-8') . '</RSASK>'
            . '<RSAPUBK>' . htmlspecialchars($this->publicPem, ENT_XML1, 'UTF-8') . '</RSAPUBK></AUTORIZACION>';
    }

    public function certificateStore(): CertificateStore
    {
        $csr = openssl_csr_new(['commonName' => 'synthetic.invalid'], $this->key, ['config' => $this->configPath]);
        $cert = openssl_csr_sign($csr, null, $this->key, 2, ['config' => $this->configPath]);
        $pkcs12 = '';
        openssl_pkcs12_export($cert, $pkcs12, $this->key, 'synthetic', ['config' => $this->configPath]);

        return CertificateStore::fromBytes($pkcs12, 'synthetic');
    }

    public function signer(): XmlSigner
    {
        return new XmlSigner($this->certificateStore());
    }

    /** @return array<string, mixed> */
    public static function emisorConfig(): array
    {
        return [
            'rut_emisor' => '97975000', 'dv_emisor' => '5', 'razon_social' => 'Empresa de prueba',
            'giro' => 'Servicios de prueba', 'codigo_actividad' => '620900',
            'direccion' => 'Av. Ejemplo 100', 'comuna' => 'Santiago',
            'fecha_resolucion' => '2019-10-18', 'numero_resolucion' => 0,
        ];
    }

    /**
     * Timbra, firma y envuelve un DTE sin TED, y valida el sobre contra el XSD oficial.
     *
     * @return array{dte: string, sobre: string}
     */
    public function emitir(string $dteXml, int $tipo, int $folio): array
    {
        $ted = new TedBuilder(new CafInspector());
        $stamped = $ted->addTedToDte($dteXml, $this->caf($tipo), $folio, '2026-11-20T12:00:00', '2026-11-20T12:00:01');
        $signer = $this->signer();
        $signed = $signer->signDte($stamped);
        $sobre = $signer->signEnvelope(EnvelopeBuilder::buildSet(
            [$signed],
            self::emisorConfig(),
            null,
            new \DateTimeImmutable('2026-11-20T12:00:02-03:00'),
        ));
        new SiiSchemaValidator()->validate($sobre, $tipo === 39 || $tipo === 41 ? 'envio_boleta' : 'envio_dte');

        return ['dte' => $signed, 'sobre' => $sobre];
    }
}
