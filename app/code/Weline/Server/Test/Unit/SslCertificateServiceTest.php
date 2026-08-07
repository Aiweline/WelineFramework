<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Model\SslCertificate;
use Weline\Server\Service\Control\BroadcastControlDispatchService;
use Weline\Server\Service\Edge\Gateway\ProjectAcmeHttp01ChallengeStore;
use Weline\Server\Service\SslCertificateService;

require_once dirname(__DIR__, 5) . '/bootstrap_phpunit.php';

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

            public function getCertificateMap(array $certificateRoots = []): array
            {
                unset($certificateRoots);

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

    public function testRevocationContainmentRunsBeforeLegacyCompatibilityMapFailure(): void
    {
        $service = new class extends SslCertificateService {
            /** @var list<string> */
            public array $events = [];

            public function __construct()
            {
            }

            protected function publishCertificateRuntimeUpdate(
                string $domain,
                array $revocationIntent,
            ): void {
                unset($domain, $revocationIntent);
                $this->events[] = 'wls2-containment';
            }

            protected function writeLegacyCertificateCompatibilityMap(
                ?float $deadlineMonotonic = null,
            ): void {
                unset($deadlineMonotonic);
                $this->events[] = 'legacy-map';
                throw new \RuntimeException('legacy map unavailable');
            }
        };
        $intent = [
            'domain' => 'retired.example.test',
            'generation' => 7,
            'source_digest' => \hash(
                'sha256',
                "wls-disabled-certificate\0retired.example.test\0" . 7,
            ),
            'intent_id' => \str_repeat('c', 64),
        ];

        try {
            $service->regenerateCertificateMap(true, 'retired.example.test', $intent);
            self::fail('The compatibility map failure must remain visible.');
        } catch (\RuntimeException $throwable) {
            self::assertSame('legacy map unavailable', $throwable->getMessage());
        }
        self::assertSame(['wls2-containment', 'legacy-map'], $service->events);
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

    public function testSniCertificateMapRejectsReversedOverbroadAndMismatchedPairs(): void
    {
        $wildcard = $this->createSniCertificatePair('*.weline.test', ['*.weline.test', 'localhost'], 'wildcard');
        $localhost = $this->createSniCertificatePair('localhost', ['localhost'], 'localhost');
        $exact = $this->createSniCertificatePair('shop.example.test', ['shop.example.test'], 'exact');
        $wrongKey = $this->createSniCertificatePair(
            'wrong-key.example.test',
            ['wrong-key.example.test'],
            'wrong-key',
        );

        $sanitized = SslCertificateService::sanitizeSniCertificateMap([
            'p2583f416.weline.test' => $localhost,
            '*.weline.test' => $wildcard,
            'localhost' => $localhost,
            'deep.p2583f416.weline.test' => $wildcard,
            'shop.example.test' => $exact,
            'wrong-key.example.test' => [
                'local_cert' => $wrongKey['local_cert'],
                'local_pk' => $localhost['local_pk'],
            ],
        ]);

        $this->assertArrayNotHasKey('p2583f416.weline.test', $sanitized);
        $this->assertArrayHasKey('*.weline.test', $sanitized);
        $this->assertArrayHasKey('localhost', $sanitized);
        $this->assertArrayNotHasKey('deep.p2583f416.weline.test', $sanitized);
        $this->assertArrayNotHasKey('wrong-key.example.test', $sanitized);

        $projectPair = SslCertificateService::selectSniCertificatePair(
            'p2583f416.weline.test',
            $sanitized,
            $localhost['local_cert'],
            $localhost['local_pk'],
        );
        $this->assertSame($wildcard['local_cert'], $projectPair['local_cert']);

        $exactPair = SslCertificateService::selectSniCertificatePair(
            'shop.example.test',
            $sanitized,
            $localhost['local_cert'],
            $localhost['local_pk'],
        );
        $this->assertSame($exact['local_cert'], $exactPair['local_cert']);

        foreach (['deep.p2583f416.weline.test', 'unrelated.example.test'] as $fallbackHost) {
            $fallbackPair = SslCertificateService::selectSniCertificatePair(
                $fallbackHost,
                $sanitized,
                $localhost['local_cert'],
                $localhost['local_pk'],
            );
            $this->assertSame($localhost['local_cert'], $fallbackPair['local_cert']);
        }
    }

    public function testSniHostnameWildcardMatchesExactlyOneLabel(): void
    {
        $this->assertTrue(SslCertificateService::sniHostnameMatchesPattern(
            'p2583f416.weline.test',
            '*.weline.test',
        ));
        $this->assertFalse(SslCertificateService::sniHostnameMatchesPattern(
            'deep.p2583f416.weline.test',
            '*.weline.test',
        ));
        $this->assertTrue(SslCertificateService::sniHostnameMatchesPattern(
            'shop.example.test',
            'shop.example.test',
        ));
        $this->assertFalse(SslCertificateService::sniHostnameMatchesPattern(
            'other.example.test',
            'shop.example.test',
        ));
    }

    public function testSniCertificateMapRejectsOverPermissivePrivateKeySource(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX mode bits do not represent the Windows certificate ACL.');
        }
        $pair = $this->createSniCertificatePair(
            'private-mode.example.test',
            ['private-mode.example.test'],
            'private-mode',
        );
        self::assertTrue(\chmod($pair['local_pk'], 0644));

        self::assertSame([], SslCertificateService::sanitizeSniCertificateMap([
            'private-mode.example.test' => $pair,
        ]));
    }

    /** @return array{local_cert:string,local_pk:string} */
    private function createSniCertificatePair(
        string $commonName,
        array $dnsNames,
        string $name,
        int $validDays = 30,
    ): array
    {
        $directory = $this->makeTempDir();
        $configPath = $directory . DIRECTORY_SEPARATOR . $name . '.cnf';
        $certPath = $directory . DIRECTORY_SEPARATOR . $name . '.pem';
        $keyPath = $directory . DIRECTORY_SEPARATOR . $name . '.key';
        $subjectAltNames = \implode(',', \array_map(
            static fn(string $dnsName): string => 'DNS:' . $dnsName,
            $dnsNames,
        ));
        $config = "[req]\n"
            . "distinguished_name=req_dn\n"
            . "prompt=no\n"
            . "req_extensions=v3_req\n"
            . "[req_dn]\n"
            . "CN={$commonName}\n"
            . "[v3_req]\n"
            . "subjectAltName={$subjectAltNames}\n"
            . "[v3_cert]\n"
            . "basicConstraints=critical,CA:false\n"
            . "keyUsage=critical,digitalSignature,keyEncipherment\n"
            . "extendedKeyUsage=serverAuth\n"
            . "subjectAltName={$subjectAltNames}\n";
        $this->assertNotFalse(\file_put_contents($configPath, $config));
        $options = [
            'config' => $configPath,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
            'digest_alg' => 'sha256',
        ];
        $key = \openssl_pkey_new($options);
        $this->assertNotFalse($key);
        $csr = \openssl_csr_new(['commonName' => $commonName], $key, $options);
        $this->assertNotFalse($csr);
        $certificate = \openssl_csr_sign(
            $csr,
            null,
            $key,
            $validDays,
            \array_replace($options, ['x509_extensions' => 'v3_cert']),
            1,
        );
        $this->assertNotFalse($certificate);
        $this->assertTrue(\openssl_x509_export($certificate, $certificatePem));
        $this->assertTrue(\openssl_pkey_export($key, $privateKeyPem, null, $options));
        $this->assertNotFalse(\file_put_contents($certPath, $certificatePem));
        $this->assertNotFalse(\file_put_contents($keyPath, $privateKeyPem));
        @\chmod($keyPath, 0600);

        return ['local_cert' => $certPath, 'local_pk' => $keyPath];
    }

    public function testLocalCaCertificateReuseRequiresLoopbackIpSanForLocalDomain(): void
    {
        $fixture = $this->createLocalCaFixture('p11005ce4.weline.test');
        $tempDir = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'p11005ce4.weline.test';
        \mkdir($tempDir, 0700, true);
        $certPath = $tempDir . DIRECTORY_SEPARATOR . 'fullchain.pem';
        \file_put_contents($certPath, $fixture['fullchain']);
        \file_put_contents($tempDir . DIRECTORY_SEPARATOR . 'chain.pem', $fixture['chain']);

        $service = $this->createLocalCaCoverageService($fixture['ca']);
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

        $service = $this->createLocalCaCoverageService($fixture['ca']);
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

    public function testWildcardCertificateMapDoesNotClaimUncoveredRootDomain(): void
    {
        $service = new SslCertificateService();
        $append = new ReflectionMethod($service, 'appendCertificateMapEntries');
        $append->setAccessible(true);
        $map = [];
        $certificate = [
            'cert' => '/tmp/wildcard-fullchain.pem',
            'key' => '/tmp/wildcard-privkey.pem',
            'chain' => '',
            'cert_type' => SslCertificate::CERT_TYPE_WILDCARD,
            'force_https' => 1,
            'force_root_to_www' => 0,
        ];
        $arguments = [
            &$map,
            '*.example.test',
            SslCertificate::CERT_TYPE_WILDCARD,
            $certificate,
        ];

        $append->invokeArgs($service, $arguments);

        $this->assertSame($certificate, $map['*.example.test'] ?? null);
        $this->assertArrayNotHasKey('example.test', $map);
    }

    public function testExpandLoopbackCertificateMapAliasesFromLocalhost(): void
    {
        $service = new SslCertificateService();
        $expand = new ReflectionMethod($service, 'expandLoopbackCertificateMapAliases');
        $expand->setAccessible(true);
        $certificate = [
            'cert' => '/tmp/localhost-fullchain.pem',
            'key' => '/tmp/localhost-privkey.pem',
            'chain' => '',
            'cert_type' => SslCertificate::CERT_TYPE_EXACT,
            'force_https' => 1,
            'force_root_to_www' => 0,
        ];
        $map = ['localhost' => $certificate];

        $expand->invokeArgs($service, [&$map, false]);

        $this->assertSame($certificate, $map['localhost'] ?? null);
        $this->assertSame($certificate, $map['127.0.0.1'] ?? null);
        $this->assertSame($certificate, $map['::1'] ?? null);
    }

    public function testExpandLoopbackCertificateMapAliasesKeepsExistingEquivalentEntry(): void
    {
        $service = new SslCertificateService();
        $expand = new ReflectionMethod($service, 'expandLoopbackCertificateMapAliases');
        $expand->setAccessible(true);
        $certificate = [
            'cert' => '/tmp/localhost-fullchain.pem',
            'key' => '/tmp/localhost-privkey.pem',
            'chain' => '',
            'cert_type' => SslCertificate::CERT_TYPE_EXACT,
            'force_https' => 1,
            'force_root_to_www' => 0,
        ];
        $map = [
            'localhost' => $certificate,
            '127.0.0.1' => $certificate,
        ];

        $expand->invokeArgs($service, [&$map, true]);

        $this->assertSame($certificate, $map['::1'] ?? null);
        $this->assertCount(3, $map);
    }

    public function testWildcardPropagationReassignsExistingCertificateToDefaultWebsite(): void
    {
        $rootDomain = 'wls-default-' . \bin2hex(\random_bytes(4)) . '.example.test';
        $wildcardDomain = '*.' . $rootDomain;
        $subdomain = 'shop.' . $rootDomain;
        $pair = $this->createSniCertificatePair(
            $wildcardDomain,
            [$wildcardDomain],
            'default-website-wildcard',
        );
        $certificatePem = (string)\file_get_contents($pair['local_cert']);
        $privateKeyPem = (string)\file_get_contents($pair['local_pk']);
        $issuedAt = \date('Y-m-d H:i:s', \time() - 60);
        $expiresAt = \date('Y-m-d H:i:s', \time() + 86400);

        $wildcard = ObjectManager::getInstance(SslCertificate::class, [], false);
        $subCertificate = ObjectManager::getInstance(SslCertificate::class, [], false);
        try {
            $wildcard->setDomain($wildcardDomain)
                ->setCertType(SslCertificate::CERT_TYPE_WILDCARD)
                ->setWebsiteId(42)
                ->setCertPem($certificatePem)
                ->setKeyPem($privateKeyPem)
                ->setIssuer('WLS test issuer')
                ->setProvider(SslCertificateService::PROVIDER_SELF_SIGNED)
                ->setIssuedAt($issuedAt)
                ->setExpiresAt($expiresAt)
                ->setStatus(SslCertificate::STATUS_ACTIVE)
                ->setAutoRenew(true)
                ->setHttpsEnabled(true)
                ->save();
            $subCertificate->setDomain($subdomain)
                ->setWebsiteId(91)
                ->setStatus(SslCertificate::STATUS_PENDING)
                ->setHttpsEnabled(false)
                ->save();

            $service = new WildcardDefaultWebsiteProbe($this->makeTempDir());
            self::assertNotNull(
                $service->applyWildcardToSubdomainIfExists($subdomain, 0),
            );

            $reloaded = ObjectManager::getInstance(SslCertificate::class, [], false)
                ->loadByDomain($subdomain);
            self::assertSame(0, $reloaded->getWebsiteId());
        } finally {
            foreach ([$subdomain, $wildcardDomain] as $domain) {
                $record = ObjectManager::getInstance(SslCertificate::class, [], false)
                    ->loadByDomain($domain);
                if ($record->getCertId() > 0) {
                    $record->delete();
                }
            }
        }
    }

    public function testGatewayCertificateRootFenceRejectsAccessibleStaleHostPath(): void
    {
        $inside = $this->makeTempDir();
        $outside = $this->makeTempDir();
        $insideFile = $inside . DIRECTORY_SEPARATOR . 'inside.pem';
        $outsideFile = $outside . DIRECTORY_SEPARATOR . 'outside.pem';
        \file_put_contents($insideFile, 'inside');
        \file_put_contents($outsideFile, 'outside');
        $service = new SslCertificateService();
        $insideRoots = new ReflectionMethod($service, 'certificatePathsInsideRoots');
        $insideRoots->setAccessible(true);

        $this->assertTrue($insideRoots->invoke($service, [$insideFile], [$inside]));
        $this->assertFalse($insideRoots->invoke($service, [$outsideFile], [$inside]));
        $filesystemRoot = \dirname((string)\realpath($inside));
        while (\dirname($filesystemRoot) !== $filesystemRoot) {
            $filesystemRoot = \dirname($filesystemRoot);
        }
        $this->assertFalse($insideRoots->invoke($service, [$insideFile], [$filesystemRoot]));
    }

    public function testGatewayCertificateRootFenceRejectsLinkedRootAlias(): void
    {
        $canonicalRoot = $this->makeTempDir();
        $linkedParent = $this->makeTempDir();
        $linkedRoot = $linkedParent . DIRECTORY_SEPARATOR . 'linked-certificates';
        if (!@\symlink($canonicalRoot, $linkedRoot)) {
            $this->markTestSkipped('The current platform cannot create a directory symlink.');
        }
        $certificate = $canonicalRoot . DIRECTORY_SEPARATOR . 'certificate.pem';
        \file_put_contents($certificate, 'certificate');
        $service = new SslCertificateService();
        $insideRoots = new ReflectionMethod($service, 'certificatePathsInsideRoots');
        $insideRoots->setAccessible(true);

        $this->assertFalse($insideRoots->invoke($service, [$certificate], [$linkedRoot]));
    }

    public function testCertificatePemPairValidationChecksNameAndPrivateKey(): void
    {
        $fixture = $this->createLocalCaFixture('certificate.example.test');
        $other = $this->createLocalCaFixture('other.example.test');
        $validate = new ReflectionMethod(
            SslCertificateService::class,
            'certificatePemPairIsValidForName',
        );
        $validate->setAccessible(true);

        $this->assertTrue($validate->invoke(
            null,
            $fixture['fullchain'],
            $fixture['key'],
            'certificate.example.test',
        ));
        $this->assertFalse($validate->invoke(
            null,
            $fixture['fullchain'],
            $fixture['key'],
            'uncovered.example.test',
        ));
        $this->assertFalse($validate->invoke(
            null,
            $fixture['fullchain'],
            $other['key'],
            'certificate.example.test',
        ));
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

    private function createLocalCaCoverageService(string $caPem): SslCertificateService
    {
        $caDir = $this->makeTempDir();
        \file_put_contents($caDir . DIRECTORY_SEPARATOR . 'rootCA.pem', $caPem);

        return new class($caDir) extends SslCertificateService {
            public function __construct(private readonly string $caDir)
            {
                parent::__construct();
            }

            protected function getLocalCaDir(): string
            {
                return \rtrim($this->caDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
            }
        };
    }

    /**
     * @return array{ca:string, leaf:string, key:string, chain:string, fullchain:string}
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

        \openssl_pkey_export($leafKey, $leafKeyPem);
        $this->assertNotSame('', $leafKeyPem);

        return [
            'ca' => $caPem,
            'leaf' => $leafPem,
            'key' => $leafKeyPem,
            'chain' => \trim($caPem) . "\n",
            'fullchain' => \trim($leafPem) . "\n" . \trim($caPem) . "\n",
        ];
    }

    /**
     * @return array{ca:string, leaf:string, key:string, chain:string, fullchain:string}
     */
    private function createLocalCaSignedCertificateFixture(string $domain): array
    {
        return $this->createLocalCaFixture($domain);
    }

    public function testTrustLocalCertificateAuthorityOnLinuxUsesSystemTrustToolWithNonInteractiveSudo(): void
    {
        $caPath = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'rootCA.pem';
        \file_put_contents($caPath, 'ca');

        $service = new class extends SslCertificateService {
            /** @var list<list<string>> */
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

            protected function resolveTrustExecutable(string $command): string
            {
                return match ($command) {
                    'sudo' => '/usr/bin/sudo',
                    default => '',
                };
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
                    'install_argv' => [
                        '/usr/bin/install',
                        '-m',
                        '0644',
                        $caCertPath,
                        '/usr/local/share/ca-certificates/weline-local-development-ca.crt',
                    ],
                    'refresh_argv' => ['/usr/sbin/update-ca-certificates'],
                    'manual' => 'sudo install -m 0644 certificate destination',
                ];
            }

            protected function runTrustCommand(
                array $command,
                ?int &$exitCode = null,
                bool $inheritStdin = false,
            ): string
            {
                unset($inheritStdin);
                $this->commands[] = $command;
                $this->installed = \in_array(
                    '/usr/sbin/update-ca-certificates',
                    $command,
                    true,
                );
                $exitCode = 0;

                return '';
            }

            protected function runInteractiveTrustCommand(array $command, ?int &$exitCode = null): string
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
        $this->assertSame('/usr/bin/sudo', $service->commands[0][0]);
        $this->assertContains('-n', $service->commands[0]);
        $this->assertNotContains('-p', $service->commands[0]);
        $this->assertNotContains('[WLS] sudo password for CA trust: ', $service->commands[0]);
        $this->assertNotContains('/bin/sh', $service->commands[0]);
        $this->assertContains('/usr/bin/install', $service->commands[0]);
        $this->assertContains('/usr/sbin/update-ca-certificates', $service->commands[1]);
        $this->assertContains(
            '/usr/local/share/ca-certificates/weline-local-development-ca.crt',
            $service->commands[0],
        );
    }

    public function testTrustLocalCertificateAuthorityOnLinuxUsesNonInteractiveSudoWithoutTty(): void
    {
        $caPath = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'rootCA.pem';
        \file_put_contents($caPath, 'ca');

        $service = new class extends SslCertificateService {
            /** @var list<list<string>> */
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

            protected function resolveTrustExecutable(string $command): string
            {
                return $command === 'sudo' ? '/usr/bin/sudo' : '';
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
                    'install_argv' => [
                        '/usr/bin/install',
                        '-m',
                        '0644',
                        $caCertPath,
                        '/usr/local/share/ca-certificates/weline-local-development-ca.crt',
                    ],
                    'refresh_argv' => ['/usr/sbin/update-ca-certificates'],
                    'manual' => 'sudo install -m 0644 certificate destination',
                ];
            }

            protected function runTrustCommand(
                array $command,
                ?int &$exitCode = null,
                bool $inheritStdin = false,
            ): string
            {
                unset($inheritStdin);
                $this->commands[] = $command;
                $this->installed = \in_array(
                    '/usr/sbin/update-ca-certificates',
                    $command,
                    true,
                );
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
        $this->assertSame('/usr/bin/sudo', $service->commands[0][0]);
        $this->assertContains('-n', $service->commands[0]);
        $this->assertNotContains('/bin/sh', $service->commands[0]);
    }

    public function testTrustLocalCertificateAuthorityOnMacosUsesLoginKeychain(): void
    {
        $caPath = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'rootCA.pem';
        \file_put_contents($caPath, 'ca');

        $service = new class extends SslCertificateService {
            /** @var list<list<string>> */
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

            protected function resolveTrustExecutable(string $command): string
            {
                return $command === 'security' ? '/usr/bin/security' : '';
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

            protected function runTrustCommand(
                array $command,
                ?int &$exitCode = null,
                bool $inheritStdin = false,
            ): string
            {
                unset($inheritStdin);
                $this->commands[] = $command;
                $this->installed = \in_array('add-trusted-cert', $command, true);
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
        $this->assertSame('/usr/bin/security', $service->commands[0][0]);
        $this->assertContains('add-trusted-cert', $service->commands[0]);
        $this->assertContains('-d', $service->commands[0]);
        $this->assertContains(
            '/Users/unit/Library/Keychains/login.keychain-db',
            $service->commands[0],
        );
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

    public function testDeferredCertificateStorageDoesNotInstantiateOrmModel(): void
    {
        $service = new SslCertificateService(true);
        $property = new \ReflectionProperty($service, 'certModel');
        $property->setAccessible(true);

        self::assertNull(
            $property->getValue($service),
            'challenge-only 文件探针不得在 WLS 2.0 pending 判定前连接项目数据库',
        );
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

    public function testLocalCertificateReuseRejectsMismatchedKeyAndSanAfterInPlaceReplacement(): void
    {
        $domain = 'reuse.example.com';
        $certificateDirectory = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'reuse';
        $this->assertTrue(\mkdir($certificateDirectory, 0700, true));
        $matching = $this->createSniCertificatePair($domain, [$domain], 'matching');
        $other = $this->createSniCertificatePair('other.example.com', ['other.example.com'], 'other');
        $conflictingSan = $this->createSniCertificatePair(
            $domain,
            ['other.example.com'],
            'conflicting-san',
        );
        $certPath = $certificateDirectory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $keyPath = $certificateDirectory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $service = new LocalCertificateReuseProbe($certificateDirectory);

        $this->assertTrue(\copy($matching['local_cert'], $certPath));
        $this->assertTrue(\copy($matching['local_pk'], $keyPath));
        $this->assertTrue(\chmod($keyPath, 0600));
        $this->assertTrue($service->hasValidLocalCertificate($domain));

        $this->assertTrue(\copy($other['local_pk'], $keyPath));
        $this->assertTrue(\chmod($keyPath, 0600));
        $this->assertFalse($service->hasValidLocalCertificate($domain), '必须拒绝与证书不配对的私钥');

        $this->assertTrue(\copy($other['local_cert'], $certPath));
        $this->assertFalse($service->hasValidLocalCertificate($domain), '必须拒绝不覆盖当前域名的 SAN');

        $this->assertTrue(\copy($conflictingSan['local_cert'], $certPath));
        $this->assertTrue(\copy($conflictingSan['local_pk'], $keyPath));
        $this->assertTrue(\chmod($keyPath, 0600));
        $this->assertFalse(
            $service->hasValidLocalCertificate($domain),
            '证书存在 SAN 时不得以冲突 CN 绕过域名覆盖校验',
        );

        $this->assertTrue(\copy($matching['local_cert'], $certPath));
        $this->assertTrue(\copy($matching['local_pk'], $keyPath));
        $this->assertTrue(\chmod($keyPath, 0600));
        $this->assertTrue(
            $service->hasValidLocalCertificate($domain),
            '同路径原子续签后不得沿用旧证书解析或 SAN 匹配缓存',
        );
    }

    public function testGatewayHttp01PublicationFailsBeforeCaNotificationAndRemainsReplayable(): void
    {
        $directory = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'acme-http01';
        $now = 1_000;
        $store = new ProjectAcmeHttp01ChallengeStore(
            $directory,
            static fn (): int => $now,
            static fn (): float => 1_000.0,
        );
        $service = new GatewayPublishingSslCertificateServiceDouble(
            $store,
            false,
            1_000.0,
        );
        $register = new ReflectionMethod($service, 'registerWlsHttp01Challenge');
        $register->setAccessible(true);
        $cleanup = new ReflectionMethod($service, 'cleanupWlsHttp01Challenge');
        $cleanup->setAccessible(true);
        $token = 'TOKEN_gateway';
        $authorization = $token . '.' . \str_repeat('A', 43);
        $domain = 'gateway-acme.example.test';

        $published = $register->invoke($service, $domain, $token, $authorization);
        $service->publicationResult = true;
        $cleanup->invoke($service, $domain);

        self::assertFalse($published);
        self::assertCount(2, $service->publishedDesired);
        self::assertSame(1, $service->publishedDesired[0]['generation']);
        self::assertSame($token, $service->publishedDesired[0]['challenges'][0]['token']);
        self::assertSame(2, $service->publishedDesired[1]['generation']);
        self::assertSame([], $service->publishedDesired[1]['challenges']);
    }

    public function testCertificatePublicationCollectsPairedAtomicRecoveryBackup(): void
    {
        $domain = 'atomic-publication.example.test';
        $pair = $this->createSniCertificatePair($domain, [$domain], 'atomic-publication');
        $base = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($base, 0700, true));
        $directory = $base . DIRECTORY_SEPARATOR . $domain;
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $contents = (string)\file_get_contents($pair['local_cert']);
        self::assertNotSame('', $contents);
        self::assertNotFalse(\file_put_contents($target, $contents));
        self::assertTrue(\chmod($target, 0644));
        $backup = $target . '.wls-backup-aaaaaaaaaaaaaaaa';
        self::assertNotFalse(\file_put_contents($backup, $contents));

        $service = new CertificateStatePublicationProbe($base);
        $publish = new ReflectionMethod($service, 'writeCertificateFileAtomically');
        $publish->setAccessible(true);
        $publish->invoke($service, $target, $contents, 0644);

        self::assertFileDoesNotExist($backup);
        self::assertSame($contents, (string)\file_get_contents($target));
    }

    public function testCertificatePublicationPreservesRecoveryEvidenceForMalformedTarget(): void
    {
        $domain = 'atomic-corrupt.example.test';
        $pair = $this->createSniCertificatePair($domain, [$domain], 'atomic-corrupt');
        $base = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($base, 0700, true));
        $directory = $base . DIRECTORY_SEPARATOR . $domain;
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        self::assertNotFalse(\file_put_contents($target, 'malformed certificate'));
        self::assertTrue(\chmod($target, 0644));
        $backup = $target . '.wls-backup-bbbbbbbbbbbbbbbb';
        self::assertNotFalse(\file_put_contents($backup, 'previous committed generation'));
        $next = (string)\file_get_contents($pair['local_cert']);

        $service = new CertificateStatePublicationProbe($base);
        $publish = new ReflectionMethod($service, 'writeCertificateFileAtomically');
        $publish->setAccessible(true);
        $failure = null;
        try {
            $publish->invoke($service, $target, $next, 0644);
        } catch (\Throwable $throwable) {
            $failure = $throwable;
        }

        self::assertInstanceOf(\RuntimeException::class, $failure);
        self::assertSame('malformed certificate', (string)\file_get_contents($target));
        self::assertFileExists($backup);
    }

    public function testCertificatePublicationRejectsCertificateForDifferentStorageDomain(): void
    {
        $domain = 'expected-san.example.test';
        $other = $this->createSniCertificatePair(
            'other-san.example.test',
            ['other-san.example.test'],
            'other-san',
        );
        $base = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($base, 0700, true));
        $directory = $base . DIRECTORY_SEPARATOR . $domain;
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'fullchain.pem';
        $service = new CertificateStatePublicationProbe($base);
        $publish = new ReflectionMethod($service, 'writeCertificateFileAtomically');
        $publish->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        try {
            $publish->invoke(
                $service,
                $target,
                (string)\file_get_contents($other['local_cert']),
                0644,
            );
        } finally {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testCertificatePublicationRejectsMultiplePrivateKeyPemBlocks(): void
    {
        $domain = 'single-private-key.example.test';
        $pair = $this->createSniCertificatePair($domain, [$domain], 'single-private-key');
        $base = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($base, 0700, true));
        $directory = $base . DIRECTORY_SEPARATOR . $domain;
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'privkey.pem';
        $privateKey = (string)\file_get_contents($pair['local_pk']);
        self::assertNotSame('', $privateKey);
        $service = new CertificateStatePublicationProbe($base);
        $publish = new ReflectionMethod($service, 'writeCertificateFileAtomically');
        $publish->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        try {
            $publish->invoke(
                $service,
                $target,
                \rtrim($privateKey) . "\n" . \ltrim($privateKey),
                0600,
            );
        } finally {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testCertificatePublicationRejectsMultipleCsrPemBlocks(): void
    {
        $domain = 'single-csr.example.test';
        $pair = $this->createSniCertificatePair($domain, [$domain], 'single-csr');
        $privateKey = \openssl_pkey_get_private(
            (string)\file_get_contents($pair['local_pk']),
        );
        self::assertNotFalse($privateKey);
        $csr = \openssl_csr_new(
            ['commonName' => $domain],
            $privateKey,
            ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr);
        self::assertTrue(\openssl_csr_export($csr, $csrPem));
        $base = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($base, 0700, true));
        $directory = $base . DIRECTORY_SEPARATOR . $domain;
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'csr.pem';
        $service = new CertificateStatePublicationProbe($base);
        $publish = new ReflectionMethod($service, 'writeCertificateFileAtomically');
        $publish->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        try {
            $publish->invoke(
                $service,
                $target,
                \rtrim($csrPem) . "\n" . \ltrim($csrPem),
                0600,
            );
        } finally {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testLocalCaPublicationCollectsOnlySemanticallyValidPairedBackup(): void
    {
        $fixture = $this->createLocalCaSignedCertificateFixture('local-ca-leaf.example.test');
        $directory = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'local-ca';
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'rootCA.pem';
        self::assertNotFalse(\file_put_contents($target, $fixture['ca']));
        self::assertTrue(\chmod($target, 0644));
        $backup = $target . '.wls-backup-cccccccccccccccc';
        self::assertNotFalse(\file_put_contents($backup, $fixture['ca']));

        $service = new CertificateStatePublicationProbe($directory, $directory);
        $publish = new ReflectionMethod($service, 'writeLocalCaStateAtomically');
        $publish->setAccessible(true);
        $publish->invoke($service, $target, $fixture['ca'], 0644);

        self::assertFileDoesNotExist($backup);
        self::assertFileExists(
            $directory . DIRECTORY_SEPARATOR . '.wls-local-ca-state.lock',
        );
    }

    public function testLocalCaPublicationRejectsTrailingNonCertificateData(): void
    {
        $fixture = $this->createLocalCaSignedCertificateFixture('local-ca-trailing.example.test');
        $directory = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'local-ca-trailing';
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'rootCA.pem';
        $service = new CertificateStatePublicationProbe($directory, $directory);
        $publish = new ReflectionMethod($service, 'writeLocalCaStateAtomically');
        $publish->setAccessible(true);

        $this->expectException(\RuntimeException::class);
        try {
            $publish->invoke(
                $service,
                $target,
                \rtrim($fixture['ca']) . "\nnot-certificate-data\n",
                0644,
            );
        } finally {
            self::assertFileDoesNotExist($target);
        }
    }

    public function testWebrootChallengePublicationCollectsRecoveryBackupAndRejectsLinkCleanup(): void
    {
        $webroot = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'webroot';
        self::assertTrue(\mkdir($webroot, 0700, true));
        $service = new HttpChallengeStateProbe();
        $token = 'TOKEN_atomic_state';
        self::assertTrue($service->createChallenge($webroot, $token));
        $target = $webroot . DIRECTORY_SEPARATOR . '.well-known'
            . DIRECTORY_SEPARATOR . 'acme-challenge' . DIRECTORY_SEPARATOR . $token;
        $backup = $target . '.wls-backup-dddddddddddddddd';
        self::assertNotFalse(\file_put_contents(
            $backup,
            (string)\file_get_contents($target),
        ));

        self::assertTrue($service->createChallenge($webroot, $token));
        self::assertFileDoesNotExist($backup);

        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\unlink($target));
            $victim = $webroot . DIRECTORY_SEPARATOR . 'must-remain.txt';
            self::assertNotFalse(\file_put_contents($victim, 'must remain'));
            self::assertTrue(\symlink($victim, $target));
            $service->cleanupChallenge($webroot, $token);
            self::assertTrue(\is_link($target));
            self::assertSame('must remain', (string)\file_get_contents($victim));
        }
    }

    public function testWebrootChallengePreservesBackupWhenPairedTargetIsMalformed(): void
    {
        $webroot = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'webroot-corrupt';
        self::assertTrue(\mkdir($webroot, 0700, true));
        $service = new HttpChallengeStateProbe();
        $token = 'TOKEN_corrupt_state';
        self::assertTrue($service->createChallenge($webroot, $token));
        $target = $webroot . DIRECTORY_SEPARATOR . '.well-known'
            . DIRECTORY_SEPARATOR . 'acme-challenge' . DIRECTORY_SEPARATOR . $token;
        self::assertNotFalse(\file_put_contents($target, 'malformed challenge'));
        self::assertTrue(\chmod($target, 0644));
        $backup = $target . '.wls-backup-ffffffffffffffff';
        self::assertNotFalse(\file_put_contents($backup, 'previous challenge generation'));

        self::assertFalse($service->createChallenge($webroot, $token));
        self::assertSame('malformed challenge', (string)\file_get_contents($target));
        self::assertFileExists($backup);
    }

    public function testSslIssuanceReleaseCollectsRecoveryBackupBeforeRemovingMarker(): void
    {
        $domain = 'issuance-release.example.test';
        $base = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($base, 0700, true));
        $directory = $base . DIRECTORY_SEPARATOR . $domain;
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR
            . SslCertificateService::SSL_ISSUANCE_LOCK_FILENAME;
        $contents = (string)\getmypid() . "\n" . \date('c');
        self::assertNotFalse(\file_put_contents($target, $contents));
        self::assertTrue(\chmod($target, 0600));
        $backup = $target . '.wls-backup-0123456789abcdef';
        self::assertNotFalse(\file_put_contents($backup, $contents));
        $service = new CertificateStatePublicationProbe($base);

        $service->releaseIssuanceMarker($domain);

        self::assertFileDoesNotExist($target);
        self::assertFileDoesNotExist($backup);
    }

    public function testOptionalCertificateStateRemovalCollectsRecoveryBackupFirst(): void
    {
        $domain = 'optional-chain.example.test';
        $pair = $this->createSniCertificatePair($domain, [$domain], 'optional-chain');
        $base = $this->makeTempDir() . DIRECTORY_SEPARATOR . 'ssl';
        self::assertTrue(\mkdir($base, 0700, true));
        $directory = $base . DIRECTORY_SEPARATOR . $domain;
        self::assertTrue(\mkdir($directory, 0700, true));
        $target = $directory . DIRECTORY_SEPARATOR . 'chain.pem';
        $contents = (string)\file_get_contents($pair['local_cert']);
        self::assertNotFalse(\file_put_contents($target, $contents));
        self::assertTrue(\chmod($target, 0644));
        $backup = $target . '.wls-backup-1234567890abcdef';
        self::assertNotFalse(\file_put_contents($backup, $contents));
        $service = new CertificateStatePublicationProbe($base);
        $remove = new ReflectionMethod($service, 'removeCertificateStateLeafSafely');
        $remove->setAccessible(true);

        self::assertTrue($remove->invoke($service, $directory, 'chain.pem'));
        self::assertFileDoesNotExist($target);
        self::assertFileDoesNotExist($backup);
    }

    public function testLegacyCertificateMapCollectsValidPairedAtomicRecoveryBackup(): void
    {
        $mapFile = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'ssl_certificate_map.json';
        $hadMap = \is_file($mapFile);
        $previousMap = $hadMap ? (string)\file_get_contents($mapFile) : null;
        $backup = $mapFile . '.wls-backup-eeeeeeeeeeeeeeee';
        $service = new class extends SslCertificateService {
            public function __construct()
            {
            }

            public function getCertificateMap(array $certificateRoots = []): array
            {
                unset($certificateRoots);
                return [
                    'legacy-map.example.test' => [
                        'cert' => '/tmp/legacy-map.crt',
                        'key' => '/tmp/legacy-map.key',
                    ],
                ];
            }
        };

        try {
            $service->regenerateCertificateMap(false);
            self::assertNotFalse(\file_put_contents(
                $backup,
                (string)\file_get_contents($mapFile),
            ));
            $service->regenerateCertificateMap(false);
            self::assertFileDoesNotExist($backup);
        } finally {
            if (\is_file($backup)) {
                @\unlink($backup);
            }
            if ($hadMap) {
                \file_put_contents($mapFile, (string)$previousMap);
            } elseif (\is_file($mapFile)) {
                @\unlink($mapFile);
            }
        }
    }

    private function makeTempDir(): string
    {
        $tempRoot = \realpath(\sys_get_temp_dir());
        if (!\is_string($tempRoot) || $tempRoot === '') {
            self::fail('Unable to resolve the canonical test temporary directory.');
        }
        $tempDir = $tempRoot . DIRECTORY_SEPARATOR . 'wls-local-ca-'
            . \bin2hex(\random_bytes(4));
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

final class LocalCertificateReuseProbe extends SslCertificateService
{
    public function __construct(private readonly string $certificateDirectory)
    {
    }

    public function getCertificateDir(string $domain): string
    {
        unset($domain);
        return \rtrim($this->certificateDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    protected function shouldUseTrustedLocalCertificateAuthority(string $domain): bool
    {
        unset($domain);
        return false;
    }
}

final class WildcardDefaultWebsiteProbe extends SslCertificateService
{
    public function __construct(private readonly string $certificateDirectory)
    {
    }

    public function getCertificateDir(string $domain): string
    {
        unset($domain);
        return \rtrim($this->certificateDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    protected function shouldUseTrustedLocalCertificateAuthority(string $domain): bool
    {
        unset($domain);
        return false;
    }

    protected function restoreCertificateFilesFromData(array $cert): bool
    {
        unset($cert);
        return true;
    }

    public function regenerateCertificateMap(
        bool $broadcastReload = true,
        string $changedDomain = '',
        array $revocationIntent = [],
    ): void {
        unset($broadcastReload, $changedDomain, $revocationIntent);
    }
}

final class GatewayPublishingSslCertificateServiceDouble extends SslCertificateService
{
    /** @var list<array<string,mixed>> */
    public array $publishedDesired = [];
    private float $gatewayPublishNow = 0.0;

    public function __construct(
        private readonly ProjectAcmeHttp01ChallengeStore $challengeStore,
        public bool $publicationResult,
        float $gatewayPublishNow = 0.0,
    ) {
        $this->gatewayPublishNow = $gatewayPublishNow;
    }

    protected function acmeHttp01ChallengeStore(): ProjectAcmeHttp01ChallengeStore
    {
        return $this->challengeStore;
    }

    /** @param array<string,mixed> $desired */
    protected function publishGatewayAcmeDesired(
        array $desired,
        ?string $requiredDomain = null,
        ?float $deadlineMonotonic = null,
    ): bool {
        unset($requiredDomain, $deadlineMonotonic);
        $this->publishedDesired[] = $desired;
        return $this->publicationResult;
    }

    protected function gatewayAcmePublishMonotonicNow(): float
    {
        return $this->gatewayPublishNow;
    }

    protected function waitForGatewayAcmePublishRetry(
        int $attempt,
        float $remainingSeconds,
    ): void
    {
        unset($attempt);
        $this->gatewayPublishNow += $remainingSeconds;
    }
}

final class CertificateStatePublicationProbe extends SslCertificateService
{
    public function __construct(
        string $certificateBaseDirectory,
        private readonly ?string $localCaDirectory = null,
    ) {
        $this->certBaseDir = \rtrim(
            $certificateBaseDirectory,
            '/\\',
        ) . DIRECTORY_SEPARATOR;
        $this->accountKeyPath = $this->certBaseDir . 'account.key';
    }

    protected function getLocalCaDir(): string
    {
        return \rtrim(
            $this->localCaDirectory ?? $this->certBaseDir,
            '/\\',
        ) . DIRECTORY_SEPARATOR;
    }

    protected function getGlobalLocalCaDir(bool $create = true): string
    {
        unset($create);
        return $this->getLocalCaDir();
    }

    public function releaseIssuanceMarker(string $domain): void
    {
        $this->releaseSslIssuanceLock($domain);
    }
}

final class HttpChallengeStateProbe extends SslCertificateService
{
    public function __construct()
    {
    }

    protected function getAccountThumbprint(): string
    {
        return \str_repeat('A', 43);
    }

    public function createChallenge(string $webroot, string $token): bool
    {
        return $this->createHttpChallenge($webroot, $token, $token);
    }

    public function cleanupChallenge(string $webroot, string $token): void
    {
        $this->cleanupHttpChallenge($webroot, $token);
    }
}
