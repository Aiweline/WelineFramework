<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Server\Service\SslCertificateService;

class SslCertificateServiceTest extends TestCase
{
    public function testNormalizeProviderAliases(): void
    {
        $service = new SslCertificateService();
        $normalize = new ReflectionMethod($service, 'normalizeAcmeProvider');
        $normalize->setAccessible(true);
        
        $this->assertSame(
            SslCertificateService::PROVIDER_LETS_ENCRYPT,
            $normalize->invoke($service, "Let's Encrypt")
        );
        $this->assertSame(
            SslCertificateService::PROVIDER_LITESSL,
            $normalize->invoke($service, 'lite-ssl')
        );
        $this->assertSame(
            SslCertificateService::PROVIDER_SELF_SIGNED,
            $normalize->invoke($service, 'selfsigned')
        );
        $this->assertSame(
            SslCertificateService::PROVIDER_LOCAL_CA,
            $normalize->invoke($service, 'local-ca')
        );
    }
    
    public function testResolveAcmeDirectoryByProvider(): void
    {
        $service = new SslCertificateService();
        $resolve = new ReflectionMethod($service, 'resolveAcmeDirectory');
        $resolve->setAccessible(true);
        
        $leProd = $resolve->invoke($service, SslCertificateService::PROVIDER_LETS_ENCRYPT, false);
        $leStaging = $resolve->invoke($service, SslCertificateService::PROVIDER_LETS_ENCRYPT, true);
        $liteProd = $resolve->invoke($service, SslCertificateService::PROVIDER_LITESSL, false);
        $liteStaging = $resolve->invoke($service, SslCertificateService::PROVIDER_LITESSL, true);
        
        $this->assertIsString($leProd);
        $this->assertStringContainsString('letsencrypt.org', $leProd);
        $this->assertIsString($leStaging);
        $this->assertStringContainsString('letsencrypt.org', $leStaging);
        $this->assertIsString($liteProd);
        $this->assertStringContainsString('sectigo.com', $liteProd);
        $this->assertNull($liteStaging);
    }

    public function testIsWelineLocalWildcardCandidateDomain(): void
    {
        $service = new SslCertificateService();
        $m = new ReflectionMethod($service, 'isWelineLocalWildcardCandidateDomain');
        $m->setAccessible(true);

        $this->assertTrue($m->invoke($service, '*.weline.test'));
        $this->assertTrue($m->invoke($service, 'p11005ce4.weline.test'));
        $this->assertTrue($m->invoke($service, 'shop-1.weline.test'));
        $this->assertTrue($m->invoke($service, '*.weline.localhost'));
        $this->assertTrue($m->invoke($service, 'p11005ce4.weline.localhost'));

        $this->assertFalse($m->invoke($service, 'weline.test'));
        $this->assertFalse($m->invoke($service, 'weline.localhost'));
        $this->assertFalse($m->invoke($service, 'example.com'));
        $this->assertFalse($m->invoke($service, ''));
    }

    public function testCertificateStorageSegmentForFilesystemPlainDomain(): void
    {
        $this->assertSame(
            'p11005ce4.weline.test',
            SslCertificateService::certificateStorageSegmentForFilesystem('p11005ce4.weline.test')
        );
    }

    public function testCertificateStorageSegmentForFilesystemWildcard(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->assertSame(
                '_wildcard_.weline.test',
                SslCertificateService::certificateStorageSegmentForFilesystem('*.weline.test')
            );
        } else {
            $this->assertSame(
                '*.weline.test',
                SslCertificateService::certificateStorageSegmentForFilesystem('*.weline.test')
            );
        }
    }

    public function testCertificateStorageSegmentCandidatesForProbeWildcard(): void
    {
        $c = SslCertificateService::certificateStorageSegmentCandidatesForProbe('*.weline.test');
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->assertContains('_wildcard_.weline.test', $c);
            $this->assertContains('*.weline.test', $c);
        } else {
            $this->assertSame(['*.weline.test'], $c);
        }
    }

    public function testLogicalDomainFromStorageSegment(): void
    {
        $this->assertSame(
            '*.weline.test',
            SslCertificateService::logicalDomainFromStorageSegment('_wildcard_.weline.test')
        );
        $this->assertSame(
            'p1.weline.test',
            SslCertificateService::logicalDomainFromStorageSegment('p1.weline.test')
        );
    }

    public function testGetIssuerByProviderSupportsLocalCa(): void
    {
        $service = new SslCertificateService();

        $this->assertSame(
            SslCertificateService::ISSUER_LOCAL_CA,
            $service->getIssuerByProvider(SslCertificateService::PROVIDER_LOCAL_CA)
        );
    }

    public function testInferProviderByIssuerRecognizesLocalCaIssuer(): void
    {
        $service = new SslCertificateService();
        $infer = new ReflectionMethod($service, 'inferProviderByIssuer');
        $infer->setAccessible(true);

        $this->assertSame(
            SslCertificateService::PROVIDER_LOCAL_CA,
            $infer->invoke($service, '', SslCertificateService::ISSUER_LOCAL_CA)
        );
    }

    public function testExtractCertificateSubjectAltNamesParsesDnsAndIpEntries(): void
    {
        $service = new SslCertificateService();
        $extract = new ReflectionMethod($service, 'extractCertificateSubjectAltNames');
        $extract->setAccessible(true);

        $result = $extract->invoke(
            $service,
            'DNS:example.test, DNS:*.weline.test, IP Address:127.0.0.1, IP:::1'
        );

        $this->assertSame(
            [
                'dns' => ['example.test', '*.weline.test'],
                'ip' => ['127.0.0.1', '::1'],
            ],
            $result
        );
    }

    public function testHostMatchesCertificateNameSupportsManagedWildcardDomains(): void
    {
        $service = new SslCertificateService();
        $match = new ReflectionMethod($service, 'hostMatchesCertificateName');
        $match->setAccessible(true);

        $this->assertTrue($match->invoke($service, 'p11005ce4.weline.test', '*.weline.test'));
        $this->assertTrue($match->invoke($service, 'demo.weline.localhost', '*.weline.localhost'));
        $this->assertFalse($match->invoke($service, 'foo.bar.weline.test', '*.weline.test'));
        $this->assertFalse($match->invoke($service, 'weline.test', '*.weline.test'));
    }

    public function testExtractLocalCaPemFromCertificateBundleReturnsEmbeddedRootCertificate(): void
    {
        $fixture = $this->createLocalCaFixture('*.weline.test');
        $service = $this->createRecoveringService($this->makeTempDir());

        $this->assertSame(
            \trim($fixture['ca']) . "\n",
            $service->extractLocalCa($fixture['fullchain'], $fixture['chain'])
        );
    }

    public function testRecoverAndTrustLocalCaFromCertificateBundlePersistsRecoveredRootCertificate(): void
    {
        $fixture = $this->createLocalCaFixture('*.weline.test');
        $tempDir = $this->makeTempDir();
        $service = $this->createRecoveringService($tempDir);

        $service->recoverLocalCa(
            SslCertificateService::PROVIDER_LOCAL_CA,
            SslCertificateService::ISSUER_LOCAL_CA,
            $fixture['fullchain'],
            $fixture['chain']
        );

        $rootCaPath = $tempDir . DIRECTORY_SEPARATOR . 'rootCA.pem';
        $this->assertFileExists($rootCaPath);
        $this->assertSame(\trim($fixture['ca']), \trim((string) \file_get_contents($rootCaPath)));
        $this->assertSame([$rootCaPath], $service->trustedCaPaths);
    }

    private function createRecoveringService(string $tempDir): object
    {
        return new class($tempDir) extends SslCertificateService {
            /** @var list<string> */
            public array $trustedCaPaths = [];

            public function __construct(private string $tempDir)
            {
                parent::__construct();
            }

            protected function getLocalCaDir(): string
            {
                if (!\is_dir($this->tempDir)) {
                    \mkdir($this->tempDir, 0700, true);
                }

                return \rtrim($this->tempDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }

            protected function trustLocalCertificateAuthority(string $caCertPath): array
            {
                $this->trustedCaPaths[] = $caCertPath;

                return ['success' => true, 'trusted' => true, 'message' => 'stub'];
            }

            public function extractLocalCa(string $certPem, string $chainPem = ''): string
            {
                return $this->extractLocalCaPemFromCertificateBundle($certPem, $chainPem);
            }

            public function recoverLocalCa(string $provider, string $issuer, string $certPem, string $chainPem = ''): void
            {
                $this->recoverAndTrustLocalCaFromCertificateBundle($provider, $issuer, $certPem, $chainPem);
            }
        };
    }

    /**
     * @return array{ca:string, leaf:string, chain:string, fullchain:string}
     */
    private function createLocalCaFixture(string $domain): array
    {
        $opensslConfig = $this->getOpenSslConfigForFixture();
        $caKey = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'digest_alg' => 'sha256',
            'config' => $opensslConfig['config'] ?? null,
        ]);
        $this->assertNotFalse($caKey);

        $caDistinguishedName = [
            'countryName' => 'CN',
            'stateOrProvinceName' => 'Development',
            'localityName' => 'Local',
            'organizationName' => 'Weline Framework',
            'organizationalUnitName' => 'Development',
            'commonName' => SslCertificateService::ISSUER_LOCAL_CA,
            'emailAddress' => 'dev@weline.localhost',
        ];

        $caCsr = \openssl_csr_new($caDistinguishedName, $caKey, $opensslConfig);
        $this->assertNotFalse($caCsr);

        $caCert = \openssl_csr_sign($caCsr, null, $caKey, 3650, $opensslConfig, 1);
        $this->assertNotFalse($caCert);

        \openssl_x509_export($caCert, $caPem);
        $this->assertNotSame('', $caPem);

        $leafKey = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'digest_alg' => 'sha256',
            'config' => $opensslConfig['config'] ?? null,
        ]);
        $this->assertNotFalse($leafKey);

        $leafDistinguishedName = [
            'countryName' => 'CN',
            'stateOrProvinceName' => 'Development',
            'localityName' => 'Local',
            'organizationName' => 'Weline Framework',
            'organizationalUnitName' => 'Development',
            'commonName' => $domain,
            'emailAddress' => 'dev@' . $domain,
        ];

        $leafCsr = \openssl_csr_new($leafDistinguishedName, $leafKey, $opensslConfig);
        $this->assertNotFalse($leafCsr);

        $leafCert = \openssl_csr_sign($leafCsr, $caCert, $caKey, 825, $opensslConfig, 2);
        $this->assertNotFalse($leafCert);

        \openssl_x509_export($leafCert, $leafPem);
        $this->assertNotSame('', $leafPem);

        return [
            'ca' => $caPem,
            'leaf' => $leafPem,
            'chain' => \trim($caPem) . "\n",
            'fullchain' => \trim($leafPem) . "\n" . \trim($caPem) . "\n",
        ];
    }

    public function testShouldPreferTrustedLocalSelfSignedCertificateMatchesWindowsForLocalDomains(): void
    {
        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'shouldPreferTrustedLocalSelfSignedCertificate');
        $method->setAccessible(true);

        $this->assertSame(\PHP_OS_FAMILY === 'Windows', $method->invoke($service, 'demo.weline.test'));
        $this->assertFalse($method->invoke($service, 'example.com'));
    }

    public function testIsCertificateSelfSignedDistinguishesLocalCaRootAndLeaf(): void
    {
        $fixture = $this->createLocalCaSignedCertificateFixture('*.weline.test');
        $tempDir = $this->makeTempDir();
        $caPath = $tempDir . DIRECTORY_SEPARATOR . 'ca.pem';
        $leafPath = $tempDir . DIRECTORY_SEPARATOR . 'leaf.pem';

        \file_put_contents($caPath, $fixture['ca']);
        \file_put_contents($leafPath, $fixture['leaf']);

        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'isCertificateSelfSigned');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $caPath));
        $this->assertFalse($method->invoke($service, $leafPath));
    }

    public function testIsCertificateAuthorityDistinguishesLocalCaRootAndLeaf(): void
    {
        $fixture = $this->createLocalCaSignedCertificateFixture('*.weline.test');
        $tempDir = $this->makeTempDir();
        $caPath = $tempDir . DIRECTORY_SEPARATOR . 'ca.pem';
        $leafPath = $tempDir . DIRECTORY_SEPARATOR . 'leaf.pem';

        \file_put_contents($caPath, $fixture['ca']);
        \file_put_contents($leafPath, $fixture['leaf']);

        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'isCertificateAuthority');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, $caPath));
        $this->assertFalse($method->invoke($service, $leafPath));
    }

    public function testBuildSanOpenSslConfigUsesLeafServerExtensions(): void
    {
        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'buildSanOpenSslConfig');
        $method->setAccessible(true);

        $config = $method->invoke($service, 'p11005ce4.weline.test', [
            'dns' => ['p11005ce4.weline.test'],
            'ip' => ['127.0.0.1'],
        ]);

        $this->assertStringContainsString('basicConstraints = critical, CA:false', $config);
        $this->assertStringContainsString('extendedKeyUsage = serverAuth', $config);
        $this->assertStringContainsString('DNS.1 = p11005ce4.weline.test', $config);
        $this->assertStringContainsString('IP.1 = 127.0.0.1', $config);
    }
    private function makeTempDir(): string
    {
        $tempDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-local-ca-' . \bin2hex(\random_bytes(4));
        if (!\is_dir($tempDir)) {
            \mkdir($tempDir, 0700, true);
        }

        return $tempDir;
    }

    /**
     * @return array<string, mixed>
     */
    private function getOpenSslConfigForFixture(): array
    {
        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'getOpensslConfig');
        $method->setAccessible(true);

        $config = $method->invoke($service);
        $this->assertIsArray($config);
        $config['digest_alg'] = 'sha256';

        return $config;
    }
}
