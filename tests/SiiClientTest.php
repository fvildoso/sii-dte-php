<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\SiiException;
use SiiDte\Folio\InMemoryFolioProvider;
use SiiDte\SiiClient;
use SiiDte\Storage\CertificateStore;
use SiiDte\Storage\InMemoryDteStore;
use SiiDte\Tests\Support\ScriptedTransport;
use SiiDte\Tests\Support\SyntheticSii;
use SiiDte\Utils\HttpResponse;
use SiiDte\Utils\HttpTransport;

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

    public function testFromEnvAceptaCertificadoBase64EnOverridesSinArchivoTemporal(): void
    {
        putenv('SII_RUT_EMISOR=76123456');
        putenv('SII_DV_EMISOR=0');
        putenv('SII_RAZON_SOCIAL=Empresa Base64 Override');
        putenv('SII_GIRO=Giro Base64');
        putenv('SII_DIRECCION=Direccion Base 123');
        putenv('SII_CIUDAD=Santiago');
        putenv('SII_AMBIENTE=certificacion');
        putenv('SII_CERT_PATH');
        putenv('SII_CERT_B64');
        putenv('SII_CERT_PASS');

        try {
            $client = SiiClient::fromEnv([
                'cert_b64'  => self::$certBase64,
                'cert_pass' => self::$certPassword,
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

    private function cliente(?HttpTransport $transport = null): SiiClient
    {
        $this->assertNotNull(self::$tempCertPath);

        return new SiiClient([
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
        ], CertificateStore::fromFile(self::$tempCertPath, self::$certPassword), $transport);
    }

    public function testEmitirSinFolioProviderFallaSinEfectosExternos(): void
    {
        $transport = new class implements HttpTransport {
            public int $calls = 0;

            public function post(string $url, string $body, array $headers, int $timeoutSeconds): HttpResponse
            {
                ++$this->calls;

                throw new \LogicException('No debe contactar al SII.');
            }
        };

        try {
            $this->cliente($transport)->enviarDte(33, []);
            self::fail('Debía exigir un FolioProvider.');
        } catch (SiiException $exception) {
            self::assertStringContainsString('FolioProvider', $exception->getMessage());
        }
        self::assertSame(0, $transport->calls);
    }

    public function testUsarFoliosYAlmacenPermitenPrepararYEmitirDesdeLaFachada(): void
    {
        $synth = SyntheticSii::get();
        $folios = new InMemoryFolioProvider();
        $folios->agregarCaf($synth->caf(33, '76123456-0', 1, 5), '76123456-0', 'certificacion', 33);
        $store = new InMemoryDteStore();
        $transport = new ScriptedTransport([
            ScriptedTransport::soap('getSeedReturn', ScriptedTransport::sii('SEMILLA', '000123')),
            ScriptedTransport::soap('getTokenReturn', ScriptedTransport::sii('TOKEN', 'token-de-prueba')),
            new HttpResponse(200, '<RECEPCIONDTE><STATUS>0</STATUS><TRACKID>555</TRACKID></RECEPCIONDTE>'),
        ]);

        $client = $this->cliente($transport)->usarFolios($folios)->usarAlmacen($store);
        $resultado = $client->enviarDte(33, [
            'fecha' => date('Y-m-d'),
            'receptor' => ['rut' => '8414240', 'dv' => '9', 'razon_social' => 'Cliente', 'giro' => 'Comercio', 'direccion' => 'Calle 1', 'comuna' => 'Santiago'],
            'detalle' => [['nombre' => 'Servicio', 'cantidad' => 1, 'precio' => 1000]],
        ]);

        self::assertSame('555', $resultado['trackId']);
        self::assertSame(1, $resultado['folio']);
        self::assertSame('enviado', $store->registros['76123456-0|certificacion|33|1']['estado']);
    }

    public function testPrevisualizacionUsaFolioExplicitoYNoIncluyeFirmaNiSobre(): void
    {
        $preview = $this->cliente()->previsualizarXml(33, [
            'folio' => 7,
            'fecha' => '2026-04-15',
            'receptor' => [
                'rut' => '98765432',
                'dv' => '5',
                'razon_social' => 'Cliente de Prueba',
                'giro' => 'Comercio',
                'direccion' => 'Calle Uno 1',
                'comuna' => 'Valparaíso',
            ],
            'detalle' => [['nombre' => 'Artículo', 'cantidad' => 1, 'precio_unitario' => 100]],
        ]);

        $this->assertStringContainsString('<Folio>7</Folio>', $preview);
        $this->assertStringNotContainsString('<Signature', $preview);
        $this->assertStringNotContainsString('<TED', $preview);
        $this->assertStringNotContainsString('<EnvioDTE', $preview);
    }

    public function testPrevisualizacionSinFolioExternoNoReserva(): void
    {
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('no reserva folios');
        $this->cliente()->previsualizarXml(33, []);
    }
}
