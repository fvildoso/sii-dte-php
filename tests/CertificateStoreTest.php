<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use SiiDte\Exceptions\SiiException;
use SiiDte\Storage\CertificateStore;

class CertificateStoreTest extends TestCase
{
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
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new(
            ['commonName' => 'test.local'],
            $privateKey
        );

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign(
            $csr,
            null,
            $privateKey,
            1
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

    public function testRechazaUnCertificadoConContrasenaIncorrecta(): void
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new(
            ['commonName' => 'test.local'],
            $privateKey
        );

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1);

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
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new(
            ['commonName' => 'test.local'],
            $privateKey
        );

        $this->assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 1);

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
