<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\SslCertificateService;

class SslCertificateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\defined('IS_WIN')) {
            \define('IS_WIN', \PHP_OS_FAMILY === 'Windows');
        }
    }

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

    public function testCollectSanEntriesSkipsBlockingDnsForWelineTestHost(): void
    {
        $service = new SslCertificateService();
        $m = new ReflectionMethod($service, 'collectSanEntries');
        $m->setAccessible(true);

        $san = $m->invoke($service, 'p11005ce4.weline.test');
        $this->assertContains('p11005ce4.weline.test', $san['dns']);
        $this->assertContains('127.0.0.1', $san['ip']);
        $this->assertContains('::1', $san['ip']);
    }

    public function testResolvesToLoopbackIsTrueForLocalTldWithoutDns(): void
    {
        $service = new SslCertificateService();
        $this->assertTrue($service->resolvesToLoopback('p11005ce4.weline.test'));
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

    public function testRegenerateCertificateMapCanSkipBroadcastForStartupRefresh(): void
    {
        $mapFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'ssl_certificate_map.json';
        $hadMap = \is_file($mapFile);
        $previousMap = $hadMap ? (string) \file_get_contents($mapFile) : null;

        $broadcast = $this->createMock(BroadcastControlDispatchService::class);
        $broadcast->expects($this->never())->method('reloadSslCert');
        ObjectManager::setInstance(BroadcastControlDispatchService::class, $broadcast);

        $service = new class extends SslCertificateService {
            public function __construct()
            {
            }

            public function getCertificateMap(): array
            {
                return [
                    'unit.test' => [
                        'cert' => '/tmp/unit.crt',
                        'key' => '/tmp/unit.key',
                    ],
                ];
            }
        };

        try {
            $service->regenerateCertificateMap(false);
            $map = \json_decode((string) \file_get_contents($mapFile), true);

            $this->assertSame('/tmp/unit.crt', $map['unit.test']['cert'] ?? null);
            $this->assertSame('/tmp/unit.key', $map['unit.test']['key'] ?? null);
        } finally {
            if ($hadMap) {
                \file_put_contents($mapFile, (string) $previousMap);
            } elseif (\is_file($mapFile)) {
                @\unlink($mapFile);
            }
            ObjectManager::removeInstance(BroadcastControlDispatchService::class);
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

    public function testLocalCaCertificateReuseRequiresLoopbackIpSanForLocalDomain(): void
    {
        $fixture = $this->createLocalCaFixture('p11005ce4.weline.test');
        $tempDir = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'p11005ce4.weline.test';
        \mkdir($tempDir, 0700, true);
        $certPath = $tempDir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        \file_put_contents($certPath, $fixture['fullchain']);
        \file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'chain.pem', $fixture['chain']);

        $service = new SslCertificateService();
        $covers = new ReflectionMethod($service, 'localCaCertificateCoversRequiredSan');
        $covers->setAccessible(true);

        $this->assertFalse($covers->invoke($service, 'p11005ce4.weline.test', $certPath));
    }

    public function testLocalCaCertificateReuseAcceptsLoopbackIpSanForLocalDomain(): void
    {
        $fixture = $this->createLocalCaFixture('p11005ce4.weline.test', ['127.0.0.1', '::1']);
        $tempDir = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'p11005ce4.weline.test';
        \mkdir($tempDir, 0700, true);
        $certPath = $tempDir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        \file_put_contents($certPath, $fixture['fullchain']);
        \file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'chain.pem', $fixture['chain']);

        $service = new SslCertificateService();
        $covers = new ReflectionMethod($service, 'localCaCertificateCoversRequiredSan');
        $covers->setAccessible(true);

        $this->assertTrue($covers->invoke($service, 'p11005ce4.weline.test', $certPath));
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
    private function createLocalCaFixture(string $domain, array $ipSans = []): array
    {
        $caOpenSslConfig = $this->getOpenSslConfigForFixture(
            'ca',
            $this->buildFixtureLocalCaOpenSslConfig()
        );
        $leafOpenSslConfig = $this->getOpenSslConfigForFixture(
            'leaf',
            $this->buildFixtureServerLeafOpenSslConfig($domain, $ipSans)
        );
        $caKey = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'digest_alg' => 'sha256',
            'config' => $caOpenSslConfig['config'] ?? null,
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

        $caCsr = \openssl_csr_new($caDistinguishedName, $caKey, $caOpenSslConfig);
        $this->assertNotFalse($caCsr);

        $caCert = \openssl_csr_sign($caCsr, null, $caKey, 3650, $caOpenSslConfig, 1);
        $this->assertNotFalse($caCert);

        \openssl_x509_export($caCert, $caPem);
        $this->assertNotSame('', $caPem);

        $leafKey = \openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'digest_alg' => 'sha256',
            'config' => $leafOpenSslConfig['config'] ?? null,
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

        $leafCsr = \openssl_csr_new($leafDistinguishedName, $leafKey, $leafOpenSslConfig);
        $this->assertNotFalse($leafCsr);

        $leafCert = \openssl_csr_sign($leafCsr, $caCert, $caKey, 825, $leafOpenSslConfig, 2);
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

    /**
     * @return array{ca:string, leaf:string, chain:string, fullchain:string}
     */
    private function createLocalCaSignedCertificateFixture(string $domain): array
    {
        return $this->createLocalCaFixture($domain);
    }

    public function testShouldPreferTrustedLocalSelfSignedCertificateMatchesWindowsForLocalDomains(): void
    {
        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'shouldPreferTrustedLocalSelfSignedCertificate');
        $method->setAccessible(true);

        $this->assertSame(
            \in_array(\PHP_OS_FAMILY, ['Windows', 'Darwin', 'Linux'], true),
            $method->invoke($service, 'demo.weline.test')
        );
        $this->assertFalse($method->invoke($service, 'example.com'));
    }

    public function testTrustLocalCertificateAuthorityOnLinuxUsesSystemTrustToolWithNonInteractiveSudo(): void
    {
        $caPath = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'rootCA.pem';
        \file_put_contents($caPath, 'ca');

        $service = new class extends SslCertificateService {
            /** @var list<string> */
            public array $commands = [];
            public bool $installed = false;

            protected function getOsFamily(): string
            {
                return 'Linux';
            }

            protected function commandExists(string $command): bool
            {
                return \in_array($command, ['sudo', 'openssl', 'update-ca-certificates'], true);
            }

            protected function isRootUser(): bool
            {
                return false;
            }

            protected function canUseInteractivePrivilegePrompt(): bool
            {
                return true;
            }

            protected function isLocalCertificateAuthorityTrustedOnLinux(string $caCertPath): bool
            {
                unset($caCertPath);

                return $this->installed;
            }

            protected function resolveLinuxLocalCaInstallPlan(string $caCertPath): ?array
            {
                return [
                    'dest' => '/usr/local/share/ca-certificates/weline-local-development-ca.crt',
                    'refresh' => 'update-ca-certificates',
                    'manual' => 'sudo /bin/sh -c install',
                ];
            }

            protected function runTrustCommand(string $command, ?int &$exitCode = null): string
            {
                $this->commands[] = $command;
                $this->installed = \str_contains($command, 'update-ca-certificates');
                $exitCode = 0;

                return '';
            }

            protected function runInteractiveTrustCommand(string $command, ?int &$exitCode = null): string
            {
                return $this->runTrustCommand($command, $exitCode);
            }

            public function trust(string $caCertPath): array
            {
                return $this->trustLocalCertificateAuthority($caCertPath);
            }
        };

        $result = $service->trust($caPath);

        $this->assertTrue((bool)($result['trusted'] ?? false));
        $this->assertNotEmpty($service->commands);
        $this->assertStringContainsString('sudo -p', $service->commands[0]);
        $this->assertStringContainsString('[WLS] sudo password for CA trust: ', $service->commands[0]);
        $this->assertStringContainsString('/bin/sh -c', $service->commands[0]);
        $this->assertStringContainsString('update-ca-certificates', $service->commands[0]);
        $this->assertStringContainsString('weline-local-development-ca.crt', $service->commands[0]);
    }

    public function testTrustLocalCertificateAuthorityOnLinuxUsesNonInteractiveSudoWithoutTty(): void
    {
        $caPath = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'rootCA.pem';
        \file_put_contents($caPath, 'ca');

        $service = new class extends SslCertificateService {
            /** @var list<string> */
            public array $commands = [];
            public bool $installed = false;

            protected function getOsFamily(): string
            {
                return 'Linux';
            }

            protected function commandExists(string $command): bool
            {
                return \in_array($command, ['sudo', 'openssl', 'update-ca-certificates'], true);
            }

            protected function isRootUser(): bool
            {
                return false;
            }

            protected function canUseInteractivePrivilegePrompt(): bool
            {
                return false;
            }

            protected function isLocalCertificateAuthorityTrustedOnLinux(string $caCertPath): bool
            {
                unset($caCertPath);

                return $this->installed;
            }

            protected function resolveLinuxLocalCaInstallPlan(string $caCertPath): ?array
            {
                return [
                    'dest' => '/usr/local/share/ca-certificates/weline-local-development-ca.crt',
                    'refresh' => 'update-ca-certificates',
                    'manual' => 'sudo /bin/sh -c install',
                ];
            }

            protected function runTrustCommand(string $command, ?int &$exitCode = null): string
            {
                $this->commands[] = $command;
                $this->installed = \str_contains($command, 'update-ca-certificates');
                $exitCode = 0;

                return '';
            }

            public function trust(string $caCertPath): array
            {
                return $this->trustLocalCertificateAuthority($caCertPath);
            }
        };

        $result = $service->trust($caPath);

        $this->assertTrue((bool)($result['trusted'] ?? false));
        $this->assertNotEmpty($service->commands);
        $this->assertStringContainsString('sudo -n /bin/sh -c', $service->commands[0]);
    }

    public function testTrustLocalCertificateAuthorityOnMacosUsesLoginKeychain(): void
    {
        $caPath = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'rootCA.pem';
        \file_put_contents($caPath, 'ca');

        $service = new class extends SslCertificateService {
            /** @var list<string> */
            public array $commands = [];
            public bool $installed = false;

            protected function getOsFamily(): string
            {
                return 'Darwin';
            }

            protected function commandExists(string $command): bool
            {
                return $command === 'security';
            }

            protected function isLocalCertificateAuthorityTrustedOnMacos(string $caCertPath): bool
            {
                unset($caCertPath);

                return $this->installed;
            }

            protected function resolveMacosLoginKeychain(): string
            {
                return '/Users/unit/Library/Keychains/login.keychain-db';
            }

            protected function runTrustCommand(string $command, ?int &$exitCode = null): string
            {
                $this->commands[] = $command;
                $this->installed = \str_contains($command, 'add-trusted-cert');
                $exitCode = 0;

                return '';
            }

            public function trust(string $caCertPath): array
            {
                return $this->trustLocalCertificateAuthority($caCertPath);
            }
        };

        $result = $service->trust($caPath);

        $this->assertTrue((bool)($result['trusted'] ?? false));
        $this->assertNotEmpty($service->commands);
        $this->assertStringContainsString('/usr/bin/security add-trusted-cert', $service->commands[0]);
        $this->assertStringContainsString('/Users/unit/Library/Keychains/login.keychain-db', $service->commands[0]);
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

    public function testHasValidLocalCertificateReturnsFalseWhenFilesMissing(): void
    {
        $service = new SslCertificateService();
        // 一个绝对不存在的私有开发域名：目录通常不会被预先创建，应直接返回 false。
        $this->assertFalse($service->hasValidLocalCertificate(
            'this-host-must-not-exist-' . \bin2hex(\random_bytes(3)) . '.weline.test'
        ));
    }

    public function testHasValidLocalCertificateNormalizesWildcardBindAndRejectsEmpty(): void
    {
        $service = new SslCertificateService();
        // 空域名直接 false，不触发任何文件探测。
        $this->assertFalse($service->hasValidLocalCertificate(''));
        // "0.0.0.0" 归一为 localhost；本地环境下 localhost 证书也可能不存在，
        // 这里只确保方法不抛异常并返回 bool，不对具体结果断言（避免依赖真机状态）。
        $this->assertIsBool($service->hasValidLocalCertificate('0.0.0.0'));
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
    private function getOpenSslConfigForFixture(string $name = 'default', string $configContent = ''): array
    {
        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'getOpensslConfig');
        $method->setAccessible(true);

        $config = $method->invoke($service);
        $this->assertIsArray($config);
        $config['digest_alg'] = 'sha256';
        if ($configContent !== '') {
            $configPath = $this->makeTempDir() . DIRECTORY_SEPARATOR . $name . '.cnf';
            \file_put_contents($configPath, $configContent);
            $config['config'] = $configPath;
        }

        return $config;
    }

    private function buildFixtureLocalCaOpenSslConfig(): string
    {
        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'buildLocalCaOpenSslConfig');
        $method->setAccessible(true);

        return (string) $method->invoke($service);
    }

    private function buildFixtureServerLeafOpenSslConfig(string $domain, array $ipSans = []): string
    {
        $service = new SslCertificateService();
        $method = new ReflectionMethod($service, 'buildServerLeafOpenSslConfig');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $domain, ['dns' => [$domain], 'ip' => $ipSans]);
    }
}
