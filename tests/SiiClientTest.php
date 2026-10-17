<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\FolioManager;
use SiiDte\SiiClient;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\DteRepository;

class SiiClientTest extends TestCase
{
    private static ?string $openSslConfigPath = null;
    private static ?string $tempCertPath      = null;
    private static string $certPassword       = 'password-test-123';
    private static string $certBase64         = '';

    public static function setUpBeforeClass(): void
    {
        $conf = tempnam(sys_get_temp_dir(), 'sii_ssl_cnf_');
        if ($conf !== false) {
            file_put_contents($conf, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
            self::$openSslConfigPath = $conf;
        }

        $args = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ];
        if (self::$openSslConfigPath !== null) {
            $args['config'] = self::$openSslConfigPath;
        }

        $privateKey = openssl_pkey_new($args);
        if ($privateKey === false) {
            self::fail('No se pudo generar clave privada RSA para tests.');
        }

        $csrArgs = self::$openSslConfigPath !== null ? ['config' => self::$openSslConfigPath] : [];
        $csr     = openssl_csr_new(['commonName' => 'test.local'], $privateKey, $csrArgs);
        if ($csr === false) {
            self::fail('No se pudo generar CSR para tests.');
        }

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, $csrArgs);
        if ($certificate === false) {
            self::fail('No se pudo firmar certificado para tests.');
        }

        $pkcs12 = '';
        openssl_pkcs12_export($certificate, $pkcs12, $privateKey, self::$certPassword);

        $certFile = tempnam(sys_get_temp_dir(), 'sii-cert-test-');
        if ($certFile !== false) {
            file_put_contents($certFile, $pkcs12);
            self::$tempCertPath = $certFile;
        }

        self::$certBase64 = base64_encode($pkcs12);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$openSslConfigPath !== null && file_exists(self::$openSslConfigPath)) {
            unlink(self::$openSslConfigPath);
        }

        if (self::$tempCertPath !== null && file_exists(self::$tempCertPath)) {
            unlink(self::$tempCertPath);
        }
    }

    public function testInicializacionDirectaConConfigValida(): void
    {
        $this->assertNotNull(self::$tempCertPath);
        $certStore = CertificateStore::fromFile(self::$tempCertPath, self::$certPassword);

        $client = new SiiClient([
            'rut_emisor'        => '76123456',
            'dv_emisor'         => '0',
            'razon_social'      => 'Empresa de Prueba SpA',
            'giro'              => 'Servicios Informáticos',
            'direccion'         => 'Av. Test 123',
            'ciudad'            => 'Santiago',
            'codigo_actividad'  => '620200',
            'numero_resolucion' => 0,
            'fecha_resolucion'  => '2019-10-18',
            'ambiente'          => 'certificacion',
        ], $certStore);

        $this->assertInstanceOf(SiiClient::class, $client);
    }

    public function testRechazaConfiguracionIncompleta(): void
    {
        $this->assertNotNull(self::$tempCertPath);
        $certStore = CertificateStore::fromFile(self::$tempCertPath, self::$certPassword);

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage("Config requerida faltante: 'giro'");

        new SiiClient([
            'rut_emisor'   => '76123456',
            'dv_emisor'    => '0',
            'razon_social' => 'Empresa Incompleta',
            // Faltan giro, direccion, ciudad
            'giro'      => '',
            'direccion' => '',
            'ciudad'    => '',
            'ambiente'  => 'certificacion',
        ], $certStore);
    }

    public function testFromEnvConCertPath(): void
    {
        $this->assertNotNull(self::$tempCertPath);

        putenv('SII_RUT_EMISOR=76123456');
        putenv('SII_DV_EMISOR=0');
        putenv('SII_RAZON_SOCIAL=Empresa Env SpA');
        putenv('SII_GIRO=Giro Env');
        putenv('SII_DIRECCION=Direccion Env 123');
        putenv('SII_CIUDAD=Santiago');
        putenv('SII_CODIGO_ACTIVIDAD=620200');
        putenv('SII_NUMERO_RESOLUCION=0');
        putenv('SII_FECHA_RESOLUCION=2019-10-18');
        putenv('SII_AMBIENTE=certificacion');
        putenv('SII_CERT_PATH=' . self::$tempCertPath);
        putenv('SII_CERT_PASS=' . self::$certPassword);

        try {
            $client = SiiClient::fromEnv();
            $this->assertInstanceOf(SiiClient::class, $client);
        } finally {
            putenv('SII_RUT_EMISOR');
            putenv('SII_DV_EMISOR');
            putenv('SII_RAZON_SOCIAL');
            putenv('SII_GIRO');
            putenv('SII_DIRECCION');
            putenv('SII_CIUDAD');
            putenv('SII_CODIGO_ACTIVIDAD');
            putenv('SII_NUMERO_RESOLUCION');
            putenv('SII_FECHA_RESOLUCION');
            putenv('SII_AMBIENTE');
            putenv('SII_CERT_PATH');
            putenv('SII_CERT_PASS');
        }
    }

    public function testFromEnvConCertBase64(): void
    {
        putenv('SII_RUT_EMISOR=76123456');
        putenv('SII_DV_EMISOR=0');
        putenv('SII_RAZON_SOCIAL=Empresa B64 SpA');
        putenv('SII_GIRO=Giro B64');
        putenv('SII_DIRECCION=Direccion B64 456');
        putenv('SII_CIUDAD=Valparaíso');
        putenv('SII_AMBIENTE=certificacion');
        putenv('SII_CERT_PATH'); // Limpiar
        putenv('SII_CERT_B64=' . self::$certBase64);
        putenv('SII_CERT_PASS=' . self::$certPassword);

        try {
            $client = SiiClient::fromEnv();
            $this->assertInstanceOf(SiiClient::class, $client);
        } finally {
            putenv('SII_RUT_EMISOR');
            putenv('SII_DV_EMISOR');
            putenv('SII_RAZON_SOCIAL');
            putenv('SII_GIRO');
            putenv('SII_DIRECCION');
            putenv('SII_CIUDAD');
            putenv('SII_AMBIENTE');
            putenv('SII_CERT_B64');
            putenv('SII_CERT_PASS');
        }
    }

    public function testFromEnvConOverrides(): void
    {
        putenv('SII_RUT_EMISOR=76123456');
        putenv('SII_DV_EMISOR=0');
        putenv('SII_RAZON_SOCIAL=Empresa Base');
        putenv('SII_GIRO=Giro Base');
        putenv('SII_DIRECCION=Direccion Base');
        putenv('SII_CIUDAD=Concepción');
        putenv('SII_AMBIENTE=certificacion');

        try {
            $client = SiiClient::fromEnv([
                'razon_social' => 'Empresa Sobrescrita SpA',
                'cert_path'    => self::$tempCertPath,
                'cert_pass'    => self::$certPassword,
            ]);
            $this->assertInstanceOf(SiiClient::class, $client);
        } finally {
            putenv('SII_RUT_EMISOR');
            putenv('SII_DV_EMISOR');
            putenv('SII_RAZON_SOCIAL');
            putenv('SII_GIRO');
            putenv('SII_DIRECCION');
            putenv('SII_CIUDAD');
            putenv('SII_AMBIENTE');
        }
    }

    public function testFromEnvLanzaExcepcionSinCertificado(): void
    {
        putenv('SII_RUT_EMISOR=76123456');
        putenv('SII_DV_EMISOR=0');
        putenv('SII_RAZON_SOCIAL=Empresa');
        putenv('SII_GIRO=Giro');
        putenv('SII_DIRECCION=Direccion');
        putenv('SII_CIUDAD=Ciudad');
        putenv('SII_AMBIENTE=certificacion');
        putenv('SII_CERT_PATH');
        putenv('SII_CERT_B64');
        putenv('SII_CERT_PASS');

        try {
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('No se especificó certificado digital en variables de entorno');

            SiiClient::fromEnv();
        } finally {
            putenv('SII_RUT_EMISOR');
            putenv('SII_DV_EMISOR');
            putenv('SII_RAZON_SOCIAL');
            putenv('SII_GIRO');
            putenv('SII_DIRECCION');
            putenv('SII_CIUDAD');
            putenv('SII_AMBIENTE');
        }
    }

    public function testInyeccionDeFolioManagerYRepositorio(): void
    {
        $this->assertNotNull(self::$tempCertPath);
        $certStore = CertificateStore::fromFile(self::$tempCertPath, self::$certPassword);

        $client = new SiiClient([
            'rut_emisor'        => '76123456',
            'dv_emisor'         => '0',
            'razon_social'      => 'Empresa Inyeccion SpA',
            'giro'              => 'Servicios Informáticos',
            'direccion'         => 'Av. Test 123',
            'ciudad'            => 'Santiago',
            'codigo_actividad'  => '620200',
            'numero_resolucion' => 0,
            'fecha_resolucion'  => '2019-10-18',
            'ambiente'          => 'certificacion',
        ], $certStore);

        $pdo = $this->createMock(PDO::class);
        $folioManager = new FolioManager($pdo);
        $dteRepo = new DteRepository($pdo);

        $ret1 = $client->usarFolioManager($folioManager);
        $this->assertSame($client, $ret1);

        $ret2 = $client->usarRepositorio($dteRepo);
        $this->assertSame($client, $ret2);
    }
}
