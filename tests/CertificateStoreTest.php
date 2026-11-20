<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\SiiException;
use SiiDte\Storage\CertificateStore;

class CertificateStoreTest extends TestCase
{
    private static ?string $openSslConfigPath = null;

    public static function setUpBeforeClass(): void
    {
        $conf = tempnam(sys_get_temp_dir(), 'sii_ssl_cnf_');
        if ($conf !== false) {
            file_put_contents($conf, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
            self::$openSslConfigPath = $conf;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$openSslConfigPath !== null && file_exists(self::$openSslConfigPath)) {
            unlink(self::$openSslConfigPath);
        }
    }

    /**
     * @return array{config?: string, private_key_type: int, private_key_bits: int}
     */
    private function getOpenSslArgs(): array
    {
        $args = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ];
        if (self::$openSslConfigPath !== null) {
            $args['config'] = self::$openSslConfigPath;
        }

        return $args;
    }
    public function testRechazaUnCertificadoInexistente(): void
    {
        $path = sys_get_temp_dir()
            . DIRECTORY_SEPARATOR
            . 'certificado-inexistente-'
            . uniqid('', true)
            . '.p12';

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('Certificado no encontrado');

        CertificateStore::fromFile($path, 'password-de-prueba');
    }

    public function testRechazaUnArchivoQueNoEsUnCertificadoValido(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sii-cert-');

        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal.');
        }

        try {
            file_put_contents($path, 'esto no es un certificado PKCS#12');

            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('No se pudo abrir el certificado');

            CertificateStore::fromFile($path, 'password-de-prueba');
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testAceptaUnCertificadoValido(): void
    {
        $privateKey = openssl_pkey_new($this->getOpenSslArgs());

        $this->assertNotFalse($privateKey);

        $csrArgs = self::$openSslConfigPath !== null ? ['config' => self::$openSslConfigPath] : [];
        $csr = openssl_csr_new(
            ['commonName' => 'test.local'],
            $privateKey,
            $csrArgs
        );

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            1,
            $csrArgs
        );

        $this->assertNotFalse($certificate);

        $password = 'password-de-prueba';
        $certData = [];

        $this->assertTrue(
            openssl_pkcs12_export(
                $certificate,
                $pkcs12,
                $privateKey,
                $password
            )
        );

        $path = tempnam(sys_get_temp_dir(), 'sii-cert-');

        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal.');
        }

        try {
            $this->assertNotFalse(file_put_contents($path, $pkcs12));

            $store = CertificateStore::fromFile($path, $password);

            $this->assertSame($path, $store->getPath());
            $this->assertSame($password, $store->getPassword());
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testCargaDesdeBytesSinCrearUnArchivoTemporalYGetPathFallaExplicitamente(): void
    {
        [$pkcs12, $password] = $this->createPkcs12();

        $store = CertificateStore::fromBytes($pkcs12, $password);

        $this->assertSame($pkcs12, $store->getPkcs12Data());
        $this->assertSame($password, $store->getPassword());
        $this->assertSame(true, $store->getInfo()['vigente']);
        $this->assertSame(false, $store->getInfo()['aun_no_vigente']);
        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('no tiene una ruta de archivo');
        $store->getPath();
    }

    public function testCargaDesdeBase64EnMemoria(): void
    {
        [$pkcs12, $password] = $this->createPkcs12();

        $store = CertificateStore::fromBase64(base64_encode($pkcs12), $password);

        $this->assertSame($pkcs12, $store->getPkcs12Data());
    }

    public function testCargaDesdeVariablesDeEntornoSinRutaTemporal(): void
    {
        [$pkcs12, $password] = $this->createPkcs12();
        $certVar = 'SII_TEST_CERT_B64_' . strtoupper(bin2hex(random_bytes(4)));
        $passVar = 'SII_TEST_CERT_PASS_' . strtoupper(bin2hex(random_bytes(4)));
        putenv($certVar . '=' . base64_encode($pkcs12));
        putenv($passVar . '=' . $password);

        try {
            $store = CertificateStore::fromEnv($certVar, $passVar);

            $this->assertSame($pkcs12, $store->getPkcs12Data());
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('no tiene una ruta de archivo');
            $store->getPath();
        } finally {
            putenv($certVar);
            putenv($passVar);
        }
    }

    public function testRechazaContrasenaIncorrectaParaCertificadoEnMemoria(): void
    {
        [$pkcs12] = $this->createPkcs12();

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('No se pudo abrir el certificado PKCS#12');

        CertificateStore::fromBytes($pkcs12, 'incorrecta');
    }

    public function testRechazaClavePrivadaQueNoCorrespondeAlCertificado(): void
    {
        $privateKey = openssl_pkey_new($this->getOpenSslArgs());
        self::assertNotFalse($privateKey);

        $csrArgs = self::$openSslConfigPath !== null ? ['config' => self::$openSslConfigPath] : [];
        $csr = openssl_csr_new(['commonName' => 'test.local'], $privateKey, $csrArgs);
        self::assertNotFalse($csr);
        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, $csrArgs);
        self::assertNotFalse($certificate);

        [$differentPkcs12, $password] = $this->createPkcs12();
        self::assertTrue(openssl_pkcs12_read($differentPkcs12, $differentCertificates, $password));

        $certificatePem = '';
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        $validator = new \ReflectionMethod(CertificateStore::class, 'assertMatchingKey');

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('no corresponde al certificado');

        $validator->invoke(null, $certificatePem, $differentCertificates['pkey']);
    }

    public function testCompruebaLimitesDeVigenciaConRelojInyectado(): void
    {
        [$pkcs12, $password, $validFrom, $validTo] = $this->createPkcs12();

        $atNotBefore = CertificateStore::fromBytes(
            $pkcs12,
            $password,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('@' . $validFrom)
        );
        $atNotAfter = CertificateStore::fromBytes(
            $pkcs12,
            $password,
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('@' . $validTo)
        );

        $this->assertTrue($atNotBefore->getInfo()['vigente']);
        $this->assertTrue($atNotAfter->getInfo()['vigente']);

        try {
            CertificateStore::fromBytes(
                $pkcs12,
                $password,
                static fn(): \DateTimeImmutable => new \DateTimeImmutable('@' . ($validFrom - 1))
            );
            $this->fail('El certificado aún no válido debía rechazarse.');
        } catch (SiiException $exception) {
            $this->assertStringContainsString('aún no está vigente', $exception->getMessage());
        }

        try {
            CertificateStore::fromBytes(
                $pkcs12,
                $password,
                static fn(): \DateTimeImmutable => new \DateTimeImmutable('@' . ($validTo + 1))
            );
            $this->fail('El certificado vencido debía rechazarse.');
        } catch (SiiException $exception) {
            $this->assertStringContainsString('ha vencido', $exception->getMessage());
        }
    }

    public function testRechazaCertificadoEnDirectorioPublicoAunqueLaRutaEsteNormalizada(): void
    {
        [$pkcs12, $password] = $this->createPkcs12();
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sii-public-' . bin2hex(random_bytes(6));
        $publicDirectory = $directory . DIRECTORY_SEPARATOR . 'PUBLIC';
        $path = $publicDirectory . DIRECTORY_SEPARATOR . 'certificate.p12';

        self::assertTrue(mkdir($publicDirectory, 0o777, true));
        self::assertNotFalse(file_put_contents($path, $pkcs12));

        try {
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('directorio público');

            CertificateStore::fromFile($path, $password);
        } finally {
            unlink($path);
            rmdir($publicDirectory);
            rmdir($directory);
        }
    }

    /**
     * @return array{string, string, int, int}
     */
    private function createPkcs12(string $password = 'password-de-prueba'): array
    {
        $privateKey = openssl_pkey_new($this->getOpenSslArgs());
        self::assertNotFalse($privateKey);

        $csrArgs = self::$openSslConfigPath !== null ? ['config' => self::$openSslConfigPath] : [];
        $csr = openssl_csr_new(['commonName' => 'test.local'], $privateKey, $csrArgs);
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, $csrArgs);
        self::assertNotFalse($certificate);

        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $privateKey, $password));
        self::assertTrue(openssl_pkcs12_read($pkcs12, $certificates, $password));
        $certificateInfo = openssl_x509_parse($certificates['cert']);

        return [
            $pkcs12,
            $password,
            $certificateInfo['validFrom_time_t'],
            $certificateInfo['validTo_time_t'],
        ];
    }

    public function testRechazaUnCertificadoConContrasenaIncorrecta(): void
    {
        $privateKey = openssl_pkey_new($this->getOpenSslArgs());

        $this->assertNotFalse($privateKey);

        $csrArgs = self::$openSslConfigPath !== null ? ['config' => self::$openSslConfigPath] : [];
        $csr = openssl_csr_new(
            ['commonName' => 'test.local'],
            $privateKey,
            $csrArgs
        );

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, $csrArgs);

        $this->assertNotFalse($certificate);

        $this->assertTrue(
            openssl_pkcs12_export(
                $certificate,
                $pkcs12,
                $privateKey,
                'password-correcta'
            )
        );

        $path = tempnam(sys_get_temp_dir(), 'sii-cert-');

        if ($path === false) {
            $this->fail('No se pudo crear el archivo temporal.');
        }

        try {
            $this->assertNotFalse(file_put_contents($path, $pkcs12));

            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('No se pudo abrir el certificado');

            CertificateStore::fromFile($path, 'password-incorrecta');
        } finally {
            if (file_exists($path)) {
                unlink($path);
            }
        }
    }

    public function testRechazaVariablesDeEntornoNoDefinidas(): void
    {
        $certVar = 'SII_TEST_CERT_B64_' . strtoupper(bin2hex(random_bytes(4)));
        $passVar = 'SII_TEST_CERT_PASS_' . strtoupper(bin2hex(random_bytes(4)));

        putenv($certVar);
        putenv($passVar);

        $this->expectException(SiiException::class);

        CertificateStore::fromEnv($certVar, $passVar);
    }

    public function testRechazaBase64InvalidoEnVariablesDeEntorno(): void
    {
        $certVar = 'SII_TEST_CERT_B64_' . strtoupper(bin2hex(random_bytes(4)));
        $passVar = 'SII_TEST_CERT_PASS_' . strtoupper(bin2hex(random_bytes(4)));

        putenv($certVar . '=esto-no-es-base64!!!');
        putenv($passVar . '=password-de-prueba');

        try {
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('no contiene un base64 válido');

            CertificateStore::fromEnv($certVar, $passVar);
        } finally {
            putenv($certVar);
            putenv($passVar);
        }
    }

    public function testRechazaCertificadoInvalidoEnVariablesDeEntorno(): void
    {
        $certVar = 'SII_TEST_CERT_B64_' . strtoupper(bin2hex(random_bytes(4)));
        $passVar = 'SII_TEST_CERT_PASS_' . strtoupper(bin2hex(random_bytes(4)));

        putenv($certVar . '=' . base64_encode('esto no es un certificado PKCS#12'));
        putenv($passVar . '=password-de-prueba');

        try {
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('No se pudo abrir el certificado');

            CertificateStore::fromEnv($certVar, $passVar);
        } finally {
            putenv($certVar);
            putenv($passVar);
        }
    }

    public function testRechazaContrasenaIncorrectaEnVariablesDeEntorno(): void
    {
        $privateKey = openssl_pkey_new($this->getOpenSslArgs());

        $this->assertNotFalse($privateKey);

        $csrArgs = self::$openSslConfigPath !== null ? ['config' => self::$openSslConfigPath] : [];
        $csr = openssl_csr_new(
            ['commonName' => 'test.local'],
            $privateKey,
            $csrArgs
        );

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, $csrArgs);

        $this->assertNotFalse($certificate);

        $this->assertTrue(
            openssl_pkcs12_export(
                $certificate,
                $pkcs12,
                $privateKey,
                'password-correcta'
            )
        );

        $certVar = 'SII_TEST_CERT_B64_' . strtoupper(bin2hex(random_bytes(4)));
        $passVar = 'SII_TEST_CERT_PASS_' . strtoupper(bin2hex(random_bytes(4)));

        putenv($certVar . '=' . base64_encode($pkcs12));
        putenv($passVar . '=password-incorrecta');

        try {
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('No se pudo abrir el certificado');

            CertificateStore::fromEnv($certVar, $passVar);
        } finally {
            putenv($certVar);
            putenv($passVar);
        }
    }

    public function testRechazaVariablesDeEntornoCuandoFaltaLaContrasena(): void
    {
        $certVar = 'SII_TEST_CERT_B64_' . strtoupper(bin2hex(random_bytes(4)));
        $passVar = 'SII_TEST_CERT_PASS_' . strtoupper(bin2hex(random_bytes(4)));

        putenv($certVar . '=' . base64_encode('contenido-de-prueba'));
        putenv($passVar);

        try {
            $this->expectException(SiiException::class);
            $this->expectExceptionMessage('no están definidas');

            CertificateStore::fromEnv($certVar, $passVar);
        } finally {
            putenv($certVar);
            putenv($passVar);
        }
    }

}
