<?php

declare(strict_types=1);

namespace SiiDte\Tests;

use PHPUnit\Framework\TestCase;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;
use SiiDte\Document\DteBuilder;
use SiiDte\Document\DteTypes;
use SiiDte\Document\SiiSchemaValidator;
use SiiDte\Envelope\EnvelopeBuilder;
use SiiDte\Exceptions\SiiException;
use SiiDte\Signature\XmlSigner;
use SiiDte\Storage\CertificateStore;

class XmlSignerTest extends TestCase
{
    private const SII_NAMESPACE = 'http://www.sii.cl/SiiDte';
    private const DSIG_NAMESPACE = 'http://www.w3.org/2000/09/xmldsig#';

    private static ?string $openSslConfigPath = null;
    private static ?XmlSigner $signer = null;
    private static ?string $trustedPublicKeyPem = null;

    public static function setUpBeforeClass(): void
    {
        $configPath = tempnam(sys_get_temp_dir(), 'sii_xml_signer_');
        if ($configPath !== false) {
            file_put_contents($configPath, "[req]\ndistinguished_name = req_distinguished_name\n[req_distinguished_name]\n");
            self::$openSslConfigPath = $configPath;
        }

        $arguments = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ];
        if (self::$openSslConfigPath !== null) {
            $arguments['config'] = self::$openSslConfigPath;
        }

        $privateKey = openssl_pkey_new($arguments);
        self::assertNotFalse($privateKey);

        $csrArguments = self::$openSslConfigPath !== null ? ['config' => self::$openSslConfigPath] : [];
        $csr = openssl_csr_new(['commonName' => 'xml-signer-test.invalid'], $privateKey, $csrArguments);
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $privateKey, 2, $csrArguments);
        self::assertNotFalse($certificate);

        $certificatePem = '';
        self::assertTrue(openssl_x509_export($certificate, $certificatePem));
        $publicKey = openssl_pkey_get_public($certificatePem);
        self::assertNotFalse($publicKey);
        $publicKeyDetails = openssl_pkey_get_details($publicKey);
        self::assertIsArray($publicKeyDetails);
        self::assertIsString($publicKeyDetails['key'] ?? null);
        self::$trustedPublicKeyPem = $publicKeyDetails['key'];

        $pkcs12 = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pkcs12, $privateKey, 'test-only-password'));
        self::$signer = new XmlSigner(CertificateStore::fromBytes($pkcs12, 'test-only-password'));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$openSslConfigPath !== null && file_exists(self::$openSslConfigPath)) {
            unlink(self::$openSslConfigPath);
        }
    }

    public function testFirmaDteComoSignatureHermanoConDigestFirmaYClavePublicaVerificables(): void
    {
        $xml = $this->signedDte();
        $doc = $this->loadXml($xml);
        $root = $doc->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);

        $document = $this->directChild($root, 'Documento', self::SII_NAMESPACE);
        $signature = $this->directChild($root, 'Signature', self::DSIG_NAMESPACE);
        self::assertNotNull($document);
        self::assertNotNull($signature);
        self::assertSame($document->nextElementSibling, $signature);

        $this->assertSignatureValid($signature, $document);
        $this->assertIndependentSignatureValid($doc, 0, 'DTE-1');

        $value = $doc->getElementsByTagNameNS(self::SII_NAMESPACE, 'Payload')->item(0);
        self::assertNotNull($value);
        self::assertSame('  café & té  ', $value->textContent);
        $text = $doc->getElementsByTagNameNS(self::SII_NAMESPACE, 'Text')->item(0);
        self::assertNotNull($text);
        self::assertSame("uno\n    dos", $text->textContent);
    }

    public function testFirmaSolicitudTokenComoXmlDsigEnvelopedVerificableIndependientemente(): void
    {
        $signedXml = self::$signer->signTokenRequest('000002248802');
        $document = $this->loadXml($signedXml);
        $root = $document->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);
        self::assertSame('getToken', $root->localName);

        $seed = $document->getElementsByTagName('Semilla')->item(0);
        $signature = $document->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Signature')->item(0);
        self::assertInstanceOf(\DOMElement::class, $seed);
        self::assertInstanceOf(\DOMElement::class, $signature);
        self::assertSame('000002248802', $seed->textContent);
        self::assertSame($signature, $root->lastChild);

        $reference = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Reference')->item(0);
        $transform = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Transform')->item(0);
        $modulus = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Modulus')->item(0);
        $exponent = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Exponent')->item(0);
        self::assertInstanceOf(\DOMElement::class, $reference);
        self::assertInstanceOf(\DOMElement::class, $transform);
        self::assertSame('', $reference->getAttribute('URI'));
        self::assertSame(self::DSIG_NAMESPACE . 'enveloped-signature', $transform->getAttribute('Algorithm'));
        self::assertNotSame('', $modulus?->textContent);
        self::assertSame(base64_encode("\x01\x00\x01"), $exponent?->textContent);

        $validatedNodes = $this->verifyIndependently($document);
        self::assertNotSame([], $validatedNodes);

        $changedSeed = str_replace('000002248802', '000002248803', $signedXml);
        self::assertNotSame($signedXml, $changedSeed);
        $this->assertIndependentVerificationFails($this->loadXml($changedSeed));

        $changedSignedInfo = str_replace(self::DSIG_NAMESPACE . 'rsa-sha1', self::DSIG_NAMESPACE . 'rsa-sha256', $signedXml);
        self::assertNotSame($signedXml, $changedSignedInfo);
        $this->assertIndependentVerificationFails($this->loadXml($changedSignedInfo));
    }

    /**
     * El SII responde "estado 10: Error Interno" si la firma usa el prefijo "ds:"; solo acepta la firma
     * en el espacio de nombres por defecto (verificado contra apicert.sii.cl y maullin.sii.cl).
     */
    public function testFirmasUsanSignatureSinPrefijoComoExigeElSii(): void
    {
        $sinPrefijo = '<Signature xmlns="' . self::DSIG_NAMESPACE . '">';
        $token = self::$signer->signTokenRequest('000002248802');
        self::assertStringContainsString($sinPrefijo, $token);
        self::assertStringNotContainsString('ds:', $token);

        $documento = $this->loadXml($token);
        foreach ($documento->getElementsByTagNameNS(self::DSIG_NAMESPACE, '*') as $nodo) {
            self::assertSame('', $nodo->prefix, "El nodo {$nodo->localName} no debe llevar prefijo.");
        }
    }

    public function testFirmaSolicitudTokenRechazaSemillaNoNumerica(): void
    {
        $this->expectException(SiiException::class);
        self::$signer->signTokenRequest('12<3');
    }

    public function testFirmaConservaLaCodificacionDeclaradaIso88591(): void
    {
        $utf8Xml = '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<DTE xmlns="' . self::SII_NAMESPACE . '" version="1.0">'
            . '<Documento ID="DTE-ISO"><Payload>café</Payload></Documento></DTE>';
        $isoXml = iconv('UTF-8', 'ISO-8859-1', $utf8Xml);
        self::assertIsString($isoXml);

        $signed = self::$signer->signDte($isoXml);
        self::assertStringContainsString('encoding="ISO-8859-1"', $signed);
        $doc = $this->loadXml($signed);
        $root = $doc->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);
        $document = $this->directChild($root, 'Documento', self::SII_NAMESPACE);
        $signature = $this->directChild($root, 'Signature', self::DSIG_NAMESPACE);
        self::assertNotNull($document);
        self::assertNotNull($signature);
        $this->assertSignatureValid($signature, $document);
        $this->assertIndependentSignatureValid($doc, 0, 'DTE-ISO');
        self::assertSame('café', $doc->getElementsByTagNameNS(self::SII_NAMESPACE, 'Payload')->item(0)->textContent);
    }

    public function testFirmaSobreDteYBoletaEnElContextoFinal(): void
    {
        foreach (['EnvioDTE', 'EnvioBOLETA'] as $rootName) {
            $xml = $this->envelope($rootName);
            $signed = self::$signer->signEnvelope($xml);
            $doc = $this->loadXml($signed);
            $root = $doc->documentElement;
            self::assertInstanceOf(\DOMElement::class, $root);

            $set = $this->directChild($root, 'SetDTE', self::SII_NAMESPACE);
            $signature = $this->directChild($root, 'Signature', self::DSIG_NAMESPACE);
            self::assertNotNull($set);
            self::assertNotNull($signature);
            self::assertSame($set->nextElementSibling, $signature);
            $this->assertSignatureValid($signature, $set);
            $this->assertIndependentSignatureValid($doc, 0, 'Set-1');
        }
    }

    public function testFirmaDteAnidadoPermaneceValidaAlFirmarElSobre(): void
    {
        $signedDte = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $this->signedDte());
        self::assertIsString($signedDte);
        $envelope = '<EnvioDTE xmlns="' . self::SII_NAMESPACE . '" version="1.0">'
            . '<SetDTE ID="Set-1">' . $signedDte . '</SetDTE></EnvioDTE>';
        $signedEnvelope = self::$signer->signEnvelope($envelope);
        $finalDocument = $this->loadXml($signedEnvelope);
        $finalRoot = $finalDocument->documentElement;
        self::assertInstanceOf(\DOMElement::class, $finalRoot);
        $nestedDte = $this->directChild($this->directChild($finalRoot, 'SetDTE', self::SII_NAMESPACE), 'DTE', self::SII_NAMESPACE);
        self::assertNotNull($nestedDte);
        $nestedDocument = $this->directChild($nestedDte, 'Documento', self::SII_NAMESPACE);
        $nestedSignature = $this->directChild($nestedDte, 'Signature', self::DSIG_NAMESPACE);
        $set = $this->directChild($finalRoot, 'SetDTE', self::SII_NAMESPACE);
        $envelopeSignature = $this->directChild($finalRoot, 'Signature', self::DSIG_NAMESPACE);
        self::assertNotNull($nestedDocument);
        self::assertNotNull($nestedSignature);
        self::assertNotNull($set);
        self::assertNotNull($envelopeSignature);

        $this->assertSignatureValid($nestedSignature, $nestedDocument);
        $this->assertSignatureValid($envelopeSignature, $set);
        $this->assertIndependentSignatureValid($finalDocument, 0, 'DTE-1');
        $this->assertIndependentSignatureValid($finalDocument, 1, 'Set-1');
    }

    public function testDteYSobreFirmadosValidanContraXsdOficial(): void
    {
        $signedDte = self::$signer->signDte($this->schemaValidDte());
        new SiiSchemaValidator()->validate($signedDte, 'dte');
        $dteDocument = $this->loadXml($signedDte);
        $this->assertIndependentSignatureValid($dteDocument, 0, 'DTE-33F1');

        $dteWithoutDeclaration = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $signedDte);
        self::assertIsString($dteWithoutDeclaration);
        $envelope = '<?xml version="1.0" encoding="ISO-8859-1"?>'
            . '<EnvioDTE version="1.0" xmlns="' . self::SII_NAMESPACE . '">'
            . '<SetDTE ID="SetDoc"><Caratula version="1.0">'
            . '<RutEmisor>76123456-0</RutEmisor><RutEnvia>76123456-0</RutEnvia>'
            . '<RutReceptor>60803000-K</RutReceptor><FchResol>2019-10-18</FchResol>'
            . '<NroResol>0</NroResol><TmstFirmaEnv>2024-01-15T12:00:00</TmstFirmaEnv>'
            . '<SubTotDTE><TpoDTE>33</TpoDTE><NroDTE>1</NroDTE></SubTotDTE>'
            . '</Caratula>' . $dteWithoutDeclaration . '</SetDTE></EnvioDTE>';
        $signedEnvelope = self::$signer->signEnvelope($envelope);
        new SiiSchemaValidator()->validate($signedEnvelope, 'envio_dte');

        $envelopeDocument = $this->loadXml($signedEnvelope);
        $this->assertIndependentSignatureValid($envelopeDocument, 0, 'DTE-33F1');
        $this->assertIndependentSignatureValid($envelopeDocument, 1, 'SetDoc');
    }

    public function testEnvelopeBuilderPreservaDteFirmadoYPermiteVerificarAmbasFirmas(): void
    {
        $signedDte = self::$signer->signDte($this->schemaValidDte());
        $envelope = EnvelopeBuilder::build(
            $signedDte,
            [
                'rut_emisor' => '76123456',
                'dv_emisor' => '0',
                'fecha_resolucion' => '2019-10-18',
                'numero_resolucion' => 0,
            ],
            ['rut' => '98765432', 'dv' => '5'],
            '60803000-K',
            new \DateTimeImmutable('2024-01-15T12:00:00-03:00'),
        );
        $signedEnvelope = self::$signer->signEnvelope($envelope);
        new SiiSchemaValidator()->validate($signedEnvelope, 'envio_dte');

        $originalDocument = $this->loadXml($signedDte)->getElementsByTagNameNS(self::SII_NAMESPACE, 'Documento')->item(0);
        $document = $this->loadXml($signedEnvelope);
        $wrappedDocument = $document->getElementsByTagNameNS(self::SII_NAMESPACE, 'Documento')->item(0);
        self::assertNotNull($originalDocument);
        self::assertNotNull($wrappedDocument);
        self::assertSame(
            $originalDocument->C14N(),
            $wrappedDocument->C14N(),
            'Envolver el DTE cambió el contenido canonicalizado del Documento.',
        );
        $this->assertIndependentSignatureValid($document, 0, 'DTE-33F1');
        $this->assertIndependentSignatureValid($document, 1, 'SetDoc');
        self::assertSame('60803000-K', $document->getElementsByTagNameNS(self::SII_NAMESPACE, 'RutEnvia')->item(0)?->textContent);
    }

    public function testRechazaXmlMaliciosoRaicesIncorrectasFirmasPreviasEIdsAmbiguos(): void
    {
        $cases = [
            '<!DOCTYPE DTE [<!ENTITY external SYSTEM "file:///windows/win.ini">]>'
                . '<DTE xmlns="' . self::SII_NAMESPACE . '"><Documento ID="DTE-1">&external;</Documento></DTE>',
            '<Wrong xmlns="' . self::SII_NAMESPACE . '"><Documento ID="DTE-1"/></Wrong>',
            '<DTE xmlns="' . self::SII_NAMESPACE . '"><Wrapper><Documento ID="DTE-1"/></Wrapper></DTE>',
            '<DTE xmlns="' . self::SII_NAMESPACE . '" xmlns:ds="' . self::DSIG_NAMESPACE . '">'
                . '<Documento ID="DTE-1"/><ds:Signature/></DTE>',
            '<DTE xmlns="' . self::SII_NAMESPACE . '"><Documento ID="DTE-1"/>'
                . '<Extra ID="DTE-1"/></DTE>',
            '<DTE xmlns="' . self::SII_NAMESPACE . '"><Documento/></DTE>',
            '<DTE xmlns="' . self::SII_NAMESPACE . '"><Documento ID="DTE:1"/></DTE>',
        ];

        foreach ($cases as $xml) {
            try {
                self::$signer->signDte($xml);
                self::fail('El XML inválido o ambiguo debía rechazarse.');
            } catch (SiiException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRechazaSobreConRaizIncorrectaIdsDuplicadosOFirmaPreexistente(): void
    {
        $cases = [
            '<DTE xmlns="' . self::SII_NAMESPACE . '"><SetDTE ID="Set-1"/></DTE>',
            '<EnvioDTE xmlns="' . self::SII_NAMESPACE . '"><SetDTE ID="Set-1">'
                . '<Item ID="Set-1"/></SetDTE></EnvioDTE>',
            '<EnvioDTE xmlns="' . self::SII_NAMESPACE . '" xmlns:ds="' . self::DSIG_NAMESPACE . '">'
                . '<SetDTE ID="Set-1"/><ds:Signature/></EnvioDTE>',
        ];

        foreach ($cases as $xml) {
            try {
                self::$signer->signEnvelope($xml);
                self::fail('El sobre inválido o ambiguo debía rechazarse.');
            } catch (SiiException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRechazaXmlQueExcedeElLimiteDeTamano(): void
    {
        $xml = '<DTE xmlns="' . self::SII_NAMESPACE . '"><Documento ID="DTE-1">'
            . str_repeat('x', 5 * 1024 * 1024)
            . '</Documento></DTE>';

        $this->expectException(SiiException::class);
        $this->expectExceptionMessage('excede el límite');
        self::$signer->signDte($xml);
    }

    public function testAlterarDocumentoInvalidaElDigestYAlterarSignedInfoInvalidaLaFirma(): void
    {
        $xml = $this->signedDte();
        $tamperedDoc = $this->loadXml($xml);
        $root = $tamperedDoc->documentElement;
        self::assertInstanceOf(\DOMElement::class, $root);
        $document = $this->directChild($root, 'Documento', self::SII_NAMESPACE);
        $signature = $this->directChild($root, 'Signature', self::DSIG_NAMESPACE);
        self::assertNotNull($document);
        self::assertNotNull($signature);
        $payload = $document->getElementsByTagNameNS(self::SII_NAMESPACE, 'Payload')->item(0);
        self::assertNotNull($payload);
        $payload->textContent = 'contenido modificado';
        self::assertNotSame(
            $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'DigestValue')->item(0)->textContent,
            base64_encode(hash('sha1', $document->C14N(), true))
        );

        $tamperedInfoDoc = $this->loadXml($xml);
        $tamperedRoot = $tamperedInfoDoc->documentElement;
        self::assertInstanceOf(\DOMElement::class, $tamperedRoot);
        $tamperedSignature = $this->directChild($tamperedRoot, 'Signature', self::DSIG_NAMESPACE);
        self::assertNotNull($tamperedSignature);
        $signedInfo = $tamperedSignature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'SignedInfo')->item(0);
        self::assertNotNull($signedInfo);
        $signatureMethod = $signedInfo->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'SignatureMethod')->item(0);
        self::assertNotNull($signatureMethod);
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha256');
        $certificate = $tamperedSignature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'X509Certificate')->item(0);
        $signatureValue = $tamperedSignature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'SignatureValue')->item(0);
        self::assertNotNull($certificate);
        self::assertNotNull($signatureValue);
        $certPem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(self::b64($certificate->textContent), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($certPem);
        self::assertNotFalse($publicKey);
        self::assertSame(
            0,
            openssl_verify($signedInfo->C14N(), base64_decode(self::b64($signatureValue->textContent), true), $publicKey, OPENSSL_ALGO_SHA1)
        );
    }

    public function testVerificadorIndependienteRechazaContenidoSignedInfoFirmaYClaveIncorrectos(): void
    {
        $contentTampered = $this->loadXml($this->signedDte());
        $contentRoot = $contentTampered->documentElement;
        self::assertInstanceOf(\DOMElement::class, $contentRoot);
        $payload = $contentRoot->getElementsByTagNameNS(self::SII_NAMESPACE, 'Payload')->item(0);
        self::assertNotNull($payload);
        $payload->textContent = 'contenido modificado';
        $this->assertIndependentVerificationFails($contentTampered);

        $signedInfoTampered = $this->loadXml($this->signedDte());
        $signature = $signedInfoTampered->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Signature')->item(0);
        self::assertInstanceOf(\DOMElement::class, $signature);
        $signatureMethod = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'SignatureMethod')->item(0);
        self::assertInstanceOf(\DOMElement::class, $signatureMethod);
        $signatureMethod->setAttribute('Algorithm', 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256');
        $this->assertIndependentVerificationFails($signedInfoTampered);

        $signatureValueTampered = $this->loadXml($this->signedDte());
        $signature = $signatureValueTampered->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Signature')->item(0);
        self::assertInstanceOf(\DOMElement::class, $signature);
        $signatureValue = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'SignatureValue')->item(0);
        self::assertInstanceOf(\DOMElement::class, $signatureValue);
        $value = $signatureValue->textContent;
        $signatureValue->textContent = ($value[0] === 'A' ? 'B' : 'A') . substr($value, 1);
        $this->assertIndependentVerificationFails($signatureValueTampered);

        $wrongKeyArguments = [
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ];
        if (self::$openSslConfigPath !== null) {
            $wrongKeyArguments['config'] = self::$openSslConfigPath;
        }
        $wrongKey = openssl_pkey_new($wrongKeyArguments);
        self::assertNotFalse($wrongKey);
        $wrongKeyDetails = openssl_pkey_get_details($wrongKey);
        self::assertIsArray($wrongKeyDetails);
        self::assertIsString($wrongKeyDetails['key'] ?? null);
        $wrongKeyDocument = $this->loadXml($this->signedDte());
        $this->assertIndependentVerificationFails($wrongKeyDocument, 0, $wrongKeyDetails['key']);
    }

    /** XMLDSig admite espacios y saltos de línea dentro del base64; se quitan antes de decodificar. */
    private static function b64(string $value): string
    {
        return preg_replace('/\s+/', '', $value) ?? '';
    }

    private function assertSignatureValid(\DOMElement $signature, \DOMElement $target): void
    {
        $signedInfo = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'SignedInfo')->item(0);
        $signatureValue = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'SignatureValue')->item(0);
        $digestValue = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'DigestValue')->item(0);
        $certificate = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'X509Certificate')->item(0);
        $modulus = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Modulus')->item(0);
        $exponent = $signature->getElementsByTagNameNS(self::DSIG_NAMESPACE, 'Exponent')->item(0);
        self::assertNotNull($signedInfo);
        self::assertNotNull($signatureValue);
        self::assertNotNull($digestValue);
        self::assertNotNull($certificate);
        self::assertNotNull($modulus);
        self::assertNotNull($exponent);

        self::assertSame(base64_encode(hash('sha1', $target->C14N(), true)), $digestValue->textContent);
        $certPem = "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(self::b64($certificate->textContent), 64, "\n")
            . "-----END CERTIFICATE-----\n";
        $publicKey = openssl_pkey_get_public($certPem);
        self::assertNotFalse($publicKey);
        self::assertSame(
            1,
            openssl_verify($signedInfo->C14N(), base64_decode(self::b64($signatureValue->textContent), true), $publicKey, OPENSSL_ALGO_SHA1)
        );

        $details = openssl_pkey_get_details($publicKey);
        self::assertIsArray($details);
        self::assertSame($details['rsa']['n'], base64_decode(self::b64($modulus->textContent), true));
        self::assertSame($details['rsa']['e'], base64_decode(self::b64($exponent->textContent), true));
    }

    private function assertIndependentSignatureValid(\DOMDocument $document, int $signaturePosition, string $expectedId): void
    {
        $validatedNodes = $this->verifyIndependently($document, $signaturePosition);
        self::assertArrayHasKey($expectedId, $validatedNodes);
        self::assertInstanceOf(\DOMElement::class, $validatedNodes[$expectedId]);
        self::assertSame($expectedId, $validatedNodes[$expectedId]->getAttribute('ID'));
    }

    private function assertIndependentVerificationFails(
        \DOMDocument $document,
        int $signaturePosition = 0,
        ?string $publicKeyPem = null,
    ): void {
        try {
            $this->verifyIndependently($document, $signaturePosition, $publicKeyPem);
        } catch (\Throwable) {
            self::assertTrue(true);

            return;
        }

        self::fail('El verificador XMLDSig independiente debía rechazar la firma.');
    }

    /**
     * @return array<int|string, \DOMNode>
     */
    private function verifyIndependently(
        \DOMDocument $document,
        int $signaturePosition = 0,
        ?string $publicKeyPem = null,
    ): array {
        $key = new XMLSecurityKey(XMLSecurityKey::RSA_SHA1, ['type' => 'public']);
        $key->loadKey($publicKeyPem ?? (self::$trustedPublicKeyPem ?? throw new \LogicException('Falta la llave pública de prueba.')));

        $verifier = new XMLSecurityDSig();
        $verifier->idKeys = ['ID'];
        $verifier->allowedSignatureAlgorithms = [XMLSecurityKey::RSA_SHA1];
        $verifier->allowedDigestAlgorithms = [XMLSecurityDSig::SHA1];

        return $verifier->verifyDocument($key, $document, $signaturePosition);
    }

    private function loadXml(string $xml): \DOMDocument
    {
        $doc = new \DOMDocument();
        $doc->preserveWhiteSpace = true;
        self::assertTrue($doc->loadXML($xml, LIBXML_NONET));

        return $doc;
    }

    private function directChild(?\DOMElement $parent, string $localName, string $namespace): ?\DOMElement
    {
        if ($parent === null) {
            return null;
        }

        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->localName === $localName
                && $child->namespaceURI === $namespace
            ) {
                return $child;
            }
        }

        return null;
    }

    private function signedDte(): string
    {
        $xml = '<DTE xmlns="' . self::SII_NAMESPACE . '" version="1.0">'
            . '<Documento ID="DTE-1"><Payload>  café &amp; té  </Payload><Text>uno&#10;    dos</Text></Documento>'
            . '</DTE>';

        return self::$signer->signDte($xml);
    }

    private function schemaValidDte(): string
    {
        $builder = new DteBuilder([
            'rut_emisor'        => '76123456',
            'dv_emisor'         => '0',
            'razon_social'      => 'Empresa Test SpA',
            'giro'              => 'Servicios de TI',
            'direccion'         => 'Av. Ejemplo 100',
            'ciudad'            => 'Santiago',
            'numero_resolucion' => 0,
            'fecha_resolucion'  => '2019-10-18',
        ]);
        $invoice = $builder->build(DteTypes::FACTURA_ELECTRONICA, [
            'folio'    => 1,
            'fecha'    => '2024-01-15',
            'receptor' => [
                'rut'          => '98765432',
                'dv'           => '5',
                'razon_social' => 'Cliente Test',
                'giro'         => 'Comercio',
                'direccion'    => 'Calle Test 1',
                'ciudad'       => 'Valparaiso',
            ],
            'detalle' => [[
                'nombre'          => 'Producto A',
                'cantidad'        => 1,
                'precio_unitario' => 119000,
            ]],
        ]);
        $invoice = iconv('ISO-8859-1', 'UTF-8', $invoice);
        self::assertIsString($invoice);

        return str_replace(
            '</Documento>',
            '<TED version="1.0"><DD><RE>76123456-0</RE><TD>33</TD><F>1</F><FE>2024-01-15</FE>'
                . '<RR>98765432-5</RR><RSR>Cliente Test</RSR><MNT>119000</MNT><IT1>Producto A</IT1>'
                . '<CAF version="1.0"><DA><RE>76123456-0</RE><RS>Empresa Test SpA</RS><TD>33</TD>'
                . '<RNG><D>1</D><H>100</H></RNG><FA>2024-01-01</FA><RSAPK><M>AQ==</M><E>AQAB</E></RSAPK>'
                . '<IDK>100</IDK></DA><FRMA algoritmo="SHA1withRSA">AA==</FRMA></CAF>'
                . '<TSTED>2024-01-15T12:00:00</TSTED></DD><FRMT algoritmo="SHA1withRSA">AA==</FRMT></TED>'
                . '<TmstFirma>2024-01-15T12:00:00</TmstFirma></Documento>',
            $invoice
        );
    }

    private function envelope(string $rootName): string
    {
        return '<' . $rootName . ' xmlns="' . self::SII_NAMESPACE . '" version="1.0">'
            . '<SetDTE ID="Set-1"><Content>  envío de prueba  </Content></SetDTE>'
            . '</' . $rootName . '>';
    }
}
