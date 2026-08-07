<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

require_once \dirname(__DIR__, 9) . DIRECTORY_SEPARATOR . 'dev'
    . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR
    . 'wls-gateway-package.php';

final class WlsGatewayPackageBuilderTest extends TestCase
{
    private string $root = '';
    /** @var array<string,string> */
    private array $inputs = [];

    protected function setUp(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped(
                'The package builder fixture uses POSIX executable scripts; Windows is covered by native CI.',
            );
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'wls-package-builder-' . \bin2hex(\random_bytes(8));
        self::assertTrue(\mkdir($this->root, 0700, true));
        $controller = $this->root . DIRECTORY_SEPARATOR . 'controller.php';
        self::assertNotFalse(\file_put_contents(
            $controller,
            "<?php\nfinal class PackageFixtureController {}\n",
        ));
        $licenses = $this->root . DIRECTORY_SEPARATOR . 'LICENSES.txt';
        self::assertNotFalse(\file_put_contents(
            $licenses,
            "WLS fixture: test-only\nPHP: PHP License\nNginx: BSD-2-Clause\n",
        ));
        $this->inputs = [
            'controller' => $controller,
            'php' => $this->executable('php', ''),
            'nginx' => $this->executable('nginx', '-V'),
            'wls-gateway-broker' => $this->executable(
                'wls-gateway-broker',
                '--self-test',
            ),
            'wls-gateway-launcher' => $this->executable(
                'wls-gateway-launcher',
                '--self-test',
            ),
            'licenses' => $licenses,
        ];
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testTestPackageIsAtomicAndCannotClaimReleaseReadiness(): void
    {
        $output = $this->root . DIRECTORY_SEPARATOR . 'test-package';
        $result = (new \WlsGatewayPackageBuilder())->build($this->options(
            $output,
            'test',
        ));

        self::assertTrue($result['ok']);
        self::assertFalse($result['release_ready']);
        self::assertDirectoryExists($output);
        self::assertFileDoesNotExist($output . DIRECTORY_SEPARATOR . 'manifest.sig');
        $manifest = $this->json($output . DIRECTORY_SEPARATOR . 'manifest.json');
        self::assertFalse($manifest['release_ready']);
        self::assertFalse($manifest['capabilities']['self_contained_php']);
        self::assertFalse($manifest['capabilities']['self_contained_nginx']);
        self::assertCount(8, $manifest['components']);
        self::assertSame(
            'CycloneDX',
            $this->json($output . DIRECTORY_SEPARATOR . 'sbom.cdx.json')['bomFormat'],
        );

        $previousTestMode = \getenv('WLS_GATEWAY_TEST_MODE');
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        try {
            $verified = (new HostGatewayPackageManager())->verifyPackage(
                $output,
                'default',
            );
            self::assertSame('test', $verified['manifest']['package_profile']);
            self::assertFalse($verified['manifest']['release_ready']);
        } finally {
            $previousTestMode === false
                ? \putenv('WLS_GATEWAY_TEST_MODE')
                : \putenv('WLS_GATEWAY_TEST_MODE=' . $previousTestMode);
        }
    }

    public function testProductionPackageRequiresTrustedKeyAndSelfContainedProvenance(): void
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secret = \sodium_crypto_sign_secretkey($keyPair);
        $public = \sodium_crypto_sign_publickey($keyPair);
        $secretFile = $this->root . DIRECTORY_SEPARATOR . 'release.secret';
        self::assertNotFalse(\file_put_contents(
            $secretFile,
            \base64_encode($secret) . PHP_EOL,
        ));
        self::assertTrue(\chmod($secretFile, 0600));
        $trustedKeys = $this->root . DIRECTORY_SEPARATOR . 'trusted-keys.json';
        self::assertNotFalse(\file_put_contents(
            $trustedKeys,
            \json_encode([
                'schema_version' => 1,
                'keys' => [[
                    'id' => 'fixture-release-key',
                    'algorithm' => 'ed25519',
                    'enabled' => true,
                    'public_key_base64' => \base64_encode($public),
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $provenance = $this->root . DIRECTORY_SEPARATOR . 'provenance.json';
        $definitions = [];
        foreach ([
            'controller',
            'php',
            'nginx',
            'wls-gateway-broker',
            'wls-gateway-launcher',
        ] as $name) {
            $path = $this->inputs[$name];
            $definitions[$name] = [
                'version' => 'fixture-1',
                'source_url' => 'https://example.invalid/' . $name,
                'source_sha256' => \hash_file('sha256', $path),
                'binary_sha256' => \hash_file('sha256', $path),
                'license' => 'test-only',
                'self_contained' => $name !== 'controller',
            ];
        }
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 1,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));

        $output = $this->root . DIRECTORY_SEPARATOR . 'production-package';
        $options = $this->options($output, 'production') + [
            'provenance' => $provenance,
        ];
        $result = (new \WlsGatewayPackageBuilder())->build($options);
        self::assertFalse($result['release_ready']);
        self::assertTrue($result['production_candidate']);
        self::assertFileDoesNotExist($output . DIRECTORY_SEPARATOR . 'manifest.sig');
        $executionMarker = $this->inputs['wls-gateway-launcher'] . '.executed';
        self::assertFileExists($executionMarker);
        self::assertTrue(\unlink($executionMarker));
        $auditReceipt = $this->root . DIRECTORY_SEPARATOR
            . 'production-package-audit.json';
        $signer = new \WlsGatewayPackageSigner();
        $audit = $signer->audit([
            'package' => $output,
            'receipt-output' => $auditReceipt,
        ]);
        self::assertTrue($audit['ok']);
        $auditEnvironment = (string)$audit['audit_environment_sha256'];
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $auditEnvironment);
        self::assertFileDoesNotExist(
            $executionMarker,
            'The dependency-audit phase must parse metadata without executing a package component.',
        );
        $receiptBytes = (string)\file_get_contents($auditReceipt);
        $tamperedReceipt = \json_decode(
            $receiptBytes,
            true,
            16,
            JSON_THROW_ON_ERROR,
        );
        $tamperedReceipt['component_set_sha256'] = \str_repeat('0', 64);
        self::assertNotFalse(\file_put_contents(
            $auditReceipt,
            \json_encode(
                $tamperedReceipt,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        try {
            $signer->sign([
                'package' => $output,
                'audit-receipt' => $auditReceipt,
                'expected-audit-environment-sha256' => $auditEnvironment,
                'signing-key-id' => 'fixture-release-key',
                'signing-key-file' => $secretFile,
                'trusted-keys' => $trustedKeys,
            ]);
            self::fail('A forged dependency-audit receipt must not authorize signing.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'receipt does not match',
                $exception->getMessage(),
            );
        }
        self::assertNotFalse(\file_put_contents($auditReceipt, $receiptBytes));
        $manifestFile = $output . DIRECTORY_SEPARATOR . 'manifest.json';
        $unsignedManifestBytes = (string)\file_get_contents($manifestFile);
        $modeTamperedManifest = $this->json($manifestFile);
        $phpComponent = \PHP_OS_FAMILY === 'Windows' ? 'bin/php.exe' : 'bin/php';
        self::assertSame(0755, $modeTamperedManifest['components'][$phpComponent]['mode']);
        $originalDigest = $modeTamperedManifest['components'][$phpComponent]['sha256'];
        $modeTamperedManifest['components'][$phpComponent]['mode'] = 0400;
        self::assertSame(
            $originalDigest,
            $modeTamperedManifest['components'][$phpComponent]['sha256'],
            'The tampered candidate preserves the component bytes and hash.',
        );
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $modeTamperedManifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        try {
            $signer->sign([
                'package' => $output,
                'audit-receipt' => $auditReceipt,
                'expected-audit-environment-sha256' => $auditEnvironment,
                'signing-key-id' => 'fixture-release-key',
                'signing-key-file' => $secretFile,
                'trusted-keys' => $trustedKeys,
            ]);
            self::fail('A non-executable declared bin component must not be signed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'locked release mode',
                $exception->getMessage(),
            );
        }
        self::assertNotFalse(\file_put_contents($manifestFile, $unsignedManifestBytes));
        $signed = $signer->sign([
            'package' => $output,
            'audit-receipt' => $auditReceipt,
            'expected-audit-environment-sha256' => $auditEnvironment,
            'signing-key-id' => 'fixture-release-key',
            'signing-key-file' => $secretFile,
            'trusted-keys' => $trustedKeys,
        ]);
        self::assertTrue($signed['release_ready']);
        self::assertFileDoesNotExist(
            $executionMarker,
            'The signing phase must never execute a package component.',
        );
        $manifestBytes = (string)\file_get_contents(
            $output . DIRECTORY_SEPARATOR . 'manifest.json',
        );
        $signature = \base64_decode(\trim((string)\file_get_contents(
            $output . DIRECTORY_SEPARATOR . 'manifest.sig',
        )), true);
        self::assertIsString($signature);
        self::assertTrue(\sodium_crypto_sign_verify_detached(
            $signature,
            $manifestBytes,
            $public,
        ));
        $verified = (new HostGatewayPackageManager(
            trustedKeysFile: $trustedKeys,
        ))->verifyPackage($output, 'default');
        self::assertSame('production', $verified['manifest']['package_profile']);
        self::assertTrue($verified['manifest']['release_ready']);

        $modeTamperedManifest = $this->json($manifestFile);
        self::assertSame(0755, $modeTamperedManifest['components'][$phpComponent]['mode']);
        $modeTamperedManifest['components'][$phpComponent]['mode'] = 0400;
        $modeTamperedBytes = \json_encode(
            $modeTamperedManifest,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ) . PHP_EOL;
        self::assertNotFalse(\file_put_contents($manifestFile, $modeTamperedBytes));
        self::assertNotFalse(\file_put_contents(
            $output . DIRECTORY_SEPARATOR . 'manifest.sig',
            \base64_encode(\sodium_crypto_sign_detached($modeTamperedBytes, $secret)) . PHP_EOL,
        ));
        try {
            (new HostGatewayPackageManager(
                trustedKeysFile: $trustedKeys,
            ))->verifyPackage($output, 'default');
            self::fail('A validly signed package with a non-executable declared bin component must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'component verification failed',
                $exception->getMessage(),
            );
        }

        $sbomMismatchOutput = $this->root . DIRECTORY_SEPARATOR . 'sbom-mismatch';
        (new \WlsGatewayPackageBuilder())->build(
            $this->options($sbomMismatchOutput, 'production') + [
                'provenance' => $provenance,
            ],
        );
        $sbomFile = $sbomMismatchOutput . DIRECTORY_SEPARATOR . 'sbom.cdx.json';
        $sbom = $this->json($sbomFile);
        $sbom['components'][0]['hashes'][0]['content'] = \str_repeat('0', 64);
        self::assertNotFalse(\file_put_contents(
            $sbomFile,
            \json_encode(
                $sbom,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        $manifestFile = $sbomMismatchOutput . DIRECTORY_SEPARATOR . 'manifest.json';
        $mismatchedManifest = $this->json($manifestFile);
        $mismatchedManifest['components']['sbom.cdx.json']['sha256']
            = \hash_file('sha256', $sbomFile);
        $mismatchedManifest['components']['sbom.cdx.json']['size']
            = \filesize($sbomFile);
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $mismatchedManifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        try {
            (new \WlsGatewayPackageSigner())->sign([
                'package' => $sbomMismatchOutput,
                'signing-key-id' => 'fixture-release-key',
                'signing-key-file' => $secretFile,
                'trusted-keys' => $trustedKeys,
            ]);
            self::fail('A CycloneDX/provenance hash mismatch must not be signed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'SBOM does not match provenance',
                $exception->getMessage(),
            );
        }
        self::assertFileDoesNotExist(
            $sbomMismatchOutput . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
        self::assertFalse($this->json($manifestFile)['release_ready']);
        \sodium_memzero($secret);

        $definitions['php']['self_contained'] = false;
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 1,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $rejectedOutput = $this->root . DIRECTORY_SEPARATOR . 'rejected-package';
        try {
            (new \WlsGatewayPackageBuilder())->build(
                $this->options($rejectedOutput, 'production') + [
                    'provenance' => $provenance,
                ],
            );
            self::fail('A non-self-contained production runtime must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'not proven self-contained',
                $exception->getMessage(),
            );
        }
        self::assertDirectoryDoesNotExist($rejectedOutput);
    }

    public function testLinuxDependencyAuditRejectsDynamicCryptoDespiteProvenanceClaim(): void
    {
        if (\PHP_OS_FAMILY !== 'Linux') {
            self::markTestSkipped('The deterministic libcrypto fixture is Linux-specific.');
        }
        $openssl = '';
        foreach (['/usr/bin/openssl', '/bin/openssl'] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                $openssl = $candidate;
                break;
            }
        }
        if ($openssl === '') {
            self::markTestSkipped('A system OpenSSL binary is unavailable.');
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('forbidden host runtime dependency');
        \WlsGatewayDependencyAuditor::assertBaseSystemOnly(
            'nginx',
            $openssl,
            'Linux',
        );
    }

    public function testSignerReconcilesCrashPointsAndRecognizesCompletedTransaction(): void
    {
        $fixture = $this->productionSigningFixture('transaction-crash-points');
        $signer = $fixture['signer'];
        $options = $fixture['sign_options'];
        $package = $fixture['package'];
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $signatureFile = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        $active = $package . '.signing-transaction';
        $complete = $package . '.signing-complete';

        $signer->sign($options);
        $completedManifest = (string)\file_get_contents($manifestFile);
        $completedSignature = (string)\file_get_contents($signatureFile);
        self::assertDirectoryExists($complete);
        $recognized = $signer->sign($options);
        self::assertTrue($recognized['release_ready']);
        self::assertSame($completedManifest, (string)\file_get_contents($manifestFile));
        self::assertSame($completedSignature, (string)\file_get_contents($signatureFile));

        self::assertTrue(\rename($complete, $active));
        $this->tamperAuditReceipt($fixture['audit_receipt']);
        try {
            $signer->sign($options);
            self::fail('A crash after manifest publication must roll back before revalidation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('receipt does not match', $exception->getMessage());
        }
        self::assertSame($fixture['unsigned_manifest'], (string)\file_get_contents($manifestFile));
        self::assertFileDoesNotExist($signatureFile);
        self::assertDirectoryDoesNotExist($active);
        self::assertDirectoryDoesNotExist($complete);

        self::assertNotFalse(\file_put_contents(
            $fixture['audit_receipt'],
            $fixture['audit_receipt_bytes'],
        ));
        $signer->sign($options);
        self::assertTrue(\rename($complete, $active));
        self::assertTrue(\rename(
            $manifestFile,
            $active . DIRECTORY_SEPARATOR . 'signed-manifest.json',
        ));
        self::assertTrue(\rename(
            $active . DIRECTORY_SEPARATOR . 'quarantine'
                . DIRECTORY_SEPARATOR . 'original-unsigned-manifest.json',
            $manifestFile,
        ));
        $this->tamperAuditReceipt($fixture['audit_receipt']);
        try {
            $signer->sign($options);
            self::fail('A crash after signature publication must roll back before revalidation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('receipt does not match', $exception->getMessage());
        }
        self::assertSame($fixture['unsigned_manifest'], (string)\file_get_contents($manifestFile));
        self::assertFileDoesNotExist($signatureFile);
        self::assertDirectoryDoesNotExist($active);
    }

    public function testSignerFailsClosedForUnprovedReservedTransactionState(): void
    {
        $fixture = $this->productionSigningFixture('reserved-transaction-state');
        $reserved = $fixture['package'] . '.signing-transaction';
        self::assertTrue(\mkdir($reserved, 0700));
        self::assertNotFalse(\file_put_contents(
            $reserved . DIRECTORY_SEPARATOR . 'signed-manifest.json',
            "unproved\n",
        ));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('Reserved signing state without a valid transaction proof must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('transaction proof', $exception->getMessage());
        }
        self::assertSame(
            $fixture['unsigned_manifest'],
            (string)\file_get_contents(
                $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.json',
            ),
        );
        self::assertFileExists($reserved . DIRECTORY_SEPARATOR . 'signed-manifest.json');
    }

    public function testWindowsBootstrapRollbackRequiresExactTransactionOwnershipProof(): void
    {
        $remove = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'removeWindowsBootstrapBundle',
        );
        $expected = [
            'wls-bounded-command.exe' => ['bytes' => "helper\n", 'mode' => 0755],
            'wls-bounded-command.manifest.json' => ['bytes' => "manifest\n", 'mode' => 0644],
            'wls-bounded-command.manifest.sig' => ['bytes' => "signature\n", 'mode' => 0644],
        ];
        $signer = new \WlsGatewayPackageSigner();
        $owned = $this->windowsBootstrapFixture(
            '.owned-bootstrap.signing-quarantine-' . \str_repeat('a', 32),
            $expected,
        );
        $ownedIdentities = $this->windowsBootstrapIdentities($signer, $owned);
        $remove->invoke($signer, $owned, $expected, $ownedIdentities);
        self::assertDirectoryDoesNotExist($owned);

        $unowned = $this->windowsBootstrapFixture(
            '.unowned-bootstrap.signing-quarantine-' . \str_repeat('b', 32),
            $expected,
        );
        $unownedIdentities = $this->windowsBootstrapIdentities($signer, $unowned);
        self::assertTrue(\unlink(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
        ));
        self::assertNotFalse(\file_put_contents(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
            "arbitrary-pre-existing-output\n",
        ));
        self::assertTrue(\chmod(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
            0755,
        ));
        try {
            $remove->invoke($signer, $unowned, $expected, $unownedIdentities);
            self::fail('Rollback must not delete a bootstrap directory without exact ownership proof.');
        } catch (\RuntimeException $exception) {
            self::assertTrue(
                \str_contains($exception->getMessage(), 'identity changed')
                    || \str_contains($exception->getMessage(), 'ownership proof'),
            );
        }
        self::assertDirectoryExists($unowned);
        self::assertFileExists(
            $unowned . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
        );
    }

    public function testSignerLockRejectsConcurrentInterleavingForSamePackage(): void
    {
        $fixture = $this->productionSigningFixture('exclusive-signing-lock');
        $acquire = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'acquireSigningLock',
        );
        $release = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'releaseSigningLock',
        );
        $first = $acquire->invoke($fixture['signer'], $fixture['package']);
        try {
            $acquire->invoke($fixture['signer'], $fixture['package']);
            self::fail('A second signer must not interleave while the package lock is held.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('already locked', $exception->getMessage());
        } finally {
            $release->invoke($fixture['signer'], $first);
        }
    }

    public function testSignerLockRootIsPrivateAndOutsideCandidateWritableNamespace(): void
    {
        $fixture = $this->productionSigningFixture('protected-signing-lock-root');
        $legacySiblingLock = $fixture['package'] . '.signing.lock';
        self::assertNotFalse(\file_put_contents($legacySiblingLock, "candidate-owned\n"));
        self::assertTrue(\chmod($this->root, 0777));
        $acquire = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'acquireSigningLock',
        );
        $release = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'releaseSigningLock',
        );

        try {
            $lock = $acquire->invoke($fixture['signer'], $fixture['package']);
            try {
                $lockPath = (string)$lock['path'];
                $lockRoot = \dirname($lockPath);
                self::assertNotSame($legacySiblingLock, $lockPath);
                self::assertFalse(\str_starts_with(
                    \str_replace('\\', '/', $lockRoot) . '/',
                    \str_replace('\\', '/', $this->root) . '/',
                ));
                $rootStatus = \lstat($lockRoot);
                self::assertIsArray($rootStatus);
                self::assertSame(0700, ((int)$rootStatus['mode']) & 0777);
                if (\function_exists('posix_geteuid')) {
                    self::assertSame((int)\posix_geteuid(), (int)$rootStatus['uid']);
                }
                self::assertSame(
                    "candidate-owned\n",
                    (string)\file_get_contents($legacySiblingLock),
                );
            } finally {
                $release->invoke($fixture['signer'], $lock);
            }
        } finally {
            self::assertTrue(\chmod($this->root, 0700));
        }
    }

    public function testSignerRejectsGroupReadableSecretKeyFile(): void
    {
        $fixture = $this->productionSigningFixture('private-signing-key-policy');
        $secret = $fixture['sign_options']['signing-key-file'];
        self::assertTrue(\chmod($secret, 0640));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('A group-readable release signing key must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('signing key permissions', $exception->getMessage());
        }
        self::assertFileDoesNotExist(
            $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
    }

    public function testSignerRejectsActualComponentModeThatContradictsManifest(): void
    {
        $fixture = $this->productionSigningFixture('actual-component-mode-policy');
        $php = $fixture['package'] . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'php';
        self::assertTrue(\chmod($php, 0777));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('A writable executable must not be signed under a declared 0755 mode.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('component mode or owner', $exception->getMessage());
        }
        self::assertFileDoesNotExist(
            $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
    }

    public function testEarliestCrashLeavesOnlyRandomPrivateStagingAndDoesNotReserveActiveState(): void
    {
        $fixture = $this->productionSigningFixture('earliest-staging-crash');
        $orphan = $fixture['package'] . '.signing-stage-' . \bin2hex(\random_bytes(16));
        self::assertTrue(\mkdir($orphan, 0700));
        self::assertNotFalse(\file_put_contents(
            $orphan . DIRECTORY_SEPARATOR . 'partial-record.json',
            "partial\n",
        ));

        $signed = $fixture['signer']->sign($fixture['sign_options']);
        self::assertTrue($signed['release_ready']);
        self::assertDirectoryExists($orphan);
        self::assertDirectoryDoesNotExist($fixture['package'] . '.signing-transaction');
        self::assertDirectoryExists($fixture['package'] . '.signing-complete');
    }

    public function testSignerRecoversProofBoundAbandonedStageBeforeNewSigning(): void
    {
        $fixture = $this->productionSigningFixture('proof-bound-abandoned-stage');
        $fixture['signer']->sign($fixture['sign_options']);
        $package = $fixture['package'];
        $complete = $package . '.signing-complete';
        $record = $this->json($complete . DIRECTORY_SEPARATOR . 'record.json');
        $stage = $package . '.signing-stage-' . (string)$record['transaction_id'];
        $manifest = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $signature = $package . DIRECTORY_SEPARATOR . 'manifest.sig';
        self::assertTrue(\rename(
            $manifest,
            $complete . DIRECTORY_SEPARATOR . 'signed-manifest.json',
        ));
        self::assertTrue(\rename(
            $signature,
            $complete . DIRECTORY_SEPARATOR . 'manifest.sig',
        ));
        self::assertTrue(\rename(
            $complete . DIRECTORY_SEPARATOR . 'quarantine'
                . DIRECTORY_SEPARATOR . 'original-unsigned-manifest.json',
            $manifest,
        ));
        self::assertTrue(\rename($complete, $stage));

        $signed = $fixture['signer']->sign($fixture['sign_options']);

        self::assertTrue($signed['release_ready']);
        self::assertDirectoryDoesNotExist($stage);
        self::assertDirectoryExists($package . '.signing-complete');
    }

    public function testUnprovedAbandonedStagesAreRetainedButBounded(): void
    {
        $fixture = $this->productionSigningFixture('bounded-unproved-staging');
        $stages = [];
        for ($index = 0; $index < 9; $index++) {
            $stage = $fixture['package'] . '.signing-stage-'
                . \str_pad(\dechex($index + 1), 32, '0', STR_PAD_LEFT);
            self::assertTrue(\mkdir($stage, 0700));
            self::assertNotFalse(\file_put_contents(
                $stage . DIRECTORY_SEPARATOR . 'unproved',
                "unproved\n",
            ));
            $stages[] = $stage;
        }

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('Unbounded unproved signing staging must stop new release publication.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('abandoned signing-stage bound', $exception->getMessage());
        }
        foreach ($stages as $stage) {
            self::assertDirectoryExists($stage);
            self::assertFileExists($stage . DIRECTORY_SEPARATOR . 'unproved');
        }
        self::assertFileDoesNotExist(
            $fixture['package'] . DIRECTORY_SEPARATOR . 'manifest.sig',
        );
    }

    public function testActiveArtifactMismatchIsQuarantinedWithoutDeletion(): void
    {
        $fixture = $this->productionSigningFixture('active-proof-mismatch');
        $fixture['signer']->sign($fixture['sign_options']);
        $active = $fixture['package'] . '.signing-transaction';
        $complete = $fixture['package'] . '.signing-complete';
        $quarantine = $fixture['package'] . '.signing-quarantine';
        self::assertTrue(\rename($complete, $active));
        $rollback = $active . DIRECTORY_SEPARATOR . 'unsigned-manifest.json';
        self::assertNotFalse(\file_put_contents($rollback, "replacement\n"));

        try {
            $fixture['signer']->sign($fixture['sign_options']);
            self::fail('An active artifact replacement must be isolated and reported.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('quarantined', $exception->getMessage());
        }
        self::assertDirectoryDoesNotExist($active);
        self::assertDirectoryExists($quarantine);
        self::assertSame(
            "replacement\n",
            (string)\file_get_contents(
                $quarantine . DIRECTORY_SEPARATOR . 'unsigned-manifest.json',
            ),
        );
    }

    public function testQuarantineDetectsReplacementBetweenIdentityCheckAndRename(): void
    {
        $signer = new \WlsGatewayPackageSigner();
        $identityMethod = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'securePathIdentity',
        );
        $quarantineMethod = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'quarantineExactFile',
        );
        $source = $this->root . DIRECTORY_SEPARATOR . 'toctou-source';
        $quarantineDirectory = $this->root . DIRECTORY_SEPARATOR . 'toctou-quarantine';
        self::assertNotFalse(\file_put_contents($source, "owned\n"));
        self::assertTrue(\chmod($source, 0600));
        self::assertTrue(\mkdir($quarantineDirectory, 0700));
        $sourceIdentity = $identityMethod->invoke($signer, $source, false, 0600);
        $quarantineIdentity = $identityMethod->invoke(
            $signer,
            $quarantineDirectory,
            true,
            0700,
        );
        self::assertTrue(\unlink($source));
        self::assertNotFalse(\file_put_contents($source, "replacement\n"));
        self::assertTrue(\chmod($source, 0600));

        try {
            $quarantineMethod->invoke(
                $signer,
                $source,
                $quarantineDirectory . DIRECTORY_SEPARATOR . 'owned',
                "owned\n",
                $sourceIdentity,
                $quarantineIdentity,
                0600,
                'TOCTOU fixture',
            );
            self::fail('A replacement must be detected before it can be moved or deleted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('identity changed', $exception->getMessage());
        }
        self::assertSame("replacement\n", (string)\file_get_contents($source));
        self::assertFileDoesNotExist(
            $quarantineDirectory . DIRECTORY_SEPARATOR . 'owned',
        );
    }

    public function testWindowsBootstrapStagingAndQuarantineStayInTargetVolumeParent(): void
    {
        $signer = new \WlsGatewayPackageSigner();
        $bootstrapPaths = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'bootstrapTransactionPaths',
        );
        $packageParent = $this->root . DIRECTORY_SEPARATOR . 'package-volume';
        $outputParent = $this->root . DIRECTORY_SEPARATOR . 'bootstrap-volume';
        self::assertTrue(\mkdir($packageParent, 0700));
        self::assertTrue(\mkdir($outputParent, 0700));
        $package = $packageParent . DIRECTORY_SEPARATOR . 'package';
        $output = $outputParent . DIRECTORY_SEPARATOR . 'bootstrap';
        $transactionId = \str_repeat('a', 32);
        $paths = $bootstrapPaths->invoke($signer, $output, $transactionId);

        self::assertSame($outputParent, \dirname($paths['stage']));
        self::assertSame($outputParent, \dirname($paths['quarantine']));
        self::assertNotSame(\dirname($package), \dirname($paths['stage']));
        self::assertStringContainsString($transactionId, \basename($paths['stage']));
        self::assertStringContainsString($transactionId, \basename($paths['quarantine']));
    }

    public function testDarwinDependencyAuditSupportsLlvmCrossFormatFallback(): void
    {
        if (\PHP_OS_FAMILY !== 'Darwin') {
            self::markTestSkipped('The Mach-O LLVM fallback fixture is macOS-specific.');
        }
        $llvm = '';
        foreach ([
            '/opt/homebrew/opt/llvm/bin/llvm-readobj',
            '/usr/local/opt/llvm/bin/llvm-readobj',
            '/usr/bin/llvm-readobj',
        ] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                $llvm = $candidate;
                break;
            }
        }
        if ($llvm === '') {
            self::markTestSkipped('llvm-readobj is unavailable.');
        }
        $oldPath = \getenv('PATH');
        try {
            \putenv('PATH=' . \dirname($llvm));
            \WlsGatewayDependencyAuditor::assertBaseSystemOnly(
                'mach-o-system-fixture',
                '/bin/ls',
                'Darwin',
            );
            self::assertTrue(true);
        } finally {
            $oldPath === false ? \putenv('PATH') : \putenv('PATH=' . $oldPath);
        }
    }

    public function testWindowsDependencyAllowlistAcceptsOnlyBaseSystemCrt(): void
    {
        $method = new \ReflectionMethod(
            \WlsGatewayDependencyAuditor::class,
            'windowsSystemLibrary',
        );
        self::assertTrue($method->invoke(null, 'KERNEL32.dll'));
        self::assertTrue($method->invoke(null, 'msvcrt.dll'));
        self::assertFalse($method->invoke(null, 'libssp-0.dll'));
        self::assertFalse($method->invoke(null, 'vcruntime140.dll'));
    }

    public function testWindowsGnuObjdumpFallbackParsesOnlyPeImportTable(): void
    {
        $method = new \ReflectionMethod(
            \WlsGatewayDependencyAuditor::class,
            'gnuObjdumpNeededLibraries',
        );
        $output = <<<'OUTPUT'
fixture.exe:     file format pei-x86-64

The Import Tables (interpreted .idata section contents)
	DLL Name: KERNEL32.dll
	DLL Name: bcrypt.dll
	DLL Name: KERNEL32.dll
OUTPUT;
        self::assertSame(
            ['kernel32.dll', 'bcrypt.dll'],
            $method->invoke(null, $output),
        );
    }

    public function testWindowsGnuObjdumpFallbackRejectsUnrecognizedOutput(): void
    {
        $method = new \ReflectionMethod(
            \WlsGatewayDependencyAuditor::class,
            'gnuObjdumpNeededLibraries',
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not recognized as a PE import table');
        $method->invoke(
            null,
            "not-a-pe: file format elf64-x86-64\nDLL Name: KERNEL32.dll\n",
        );
    }

    /**
     * @return array<string,string>
     */
    private function options(string $output, string $profile): array
    {
        return $this->inputs + [
            'output' => $output,
            'profile' => $profile,
            'version' => '2.0.0-fixture',
            'platform' => \PHP_OS_FAMILY,
            'arch' => $this->normalizedArch(),
        ];
    }

    private function executable(string $name, string $expectedArgument): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        $source = $path . '.c';
        $nameLiteral = \json_encode($name, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $expectedLiteral = \json_encode(
            $expectedArgument,
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        $markerLiteral = \json_encode(
            $path . '.executed',
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
        self::assertNotFalse(\file_put_contents(
            $source,
            <<<C
#include <stdio.h>
#include <string.h>

int main(int argc, char **argv) {
    const char *name = {$nameLiteral};
    const char *expected = {$expectedLiteral};
    if (strcmp(name, "php") == 0) {
        if (argc >= 2
            && (strcmp(argv[1], "-l") == 0 || strcmp(argv[1], "--version") == 0)) {
            return 0;
        }
        return argc == 3 && strcmp(argv[2], "--self-test") == 0 ? 0 : 1;
    }
    FILE *marker = fopen({$markerLiteral}, "wb");
    if (marker != NULL) {
        fclose(marker);
    }
    return argc == 2 && strcmp(argv[1], expected) == 0 ? 0 : 1;
}
C,
        ));
        $compiler = '';
        foreach (['/usr/bin/cc', '/usr/bin/clang', '/usr/bin/gcc'] as $candidate) {
            if (\is_file($candidate) && \is_executable($candidate)) {
                $compiler = $candidate;
                break;
            }
        }
        if ($compiler === '') {
            self::markTestSkipped('A native C compiler is required for package fixtures.');
        }
        $process = \proc_open(
            [$compiler, '-O2', $source, '-o', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($process);
        $output = (string)\stream_get_contents($pipes[1])
            . (string)\stream_get_contents($pipes[2]);
        @\fclose($pipes[1]);
        @\fclose($pipes[2]);
        self::assertSame(0, \proc_close($process), $output);
        self::assertTrue(\is_executable($path));
        return $path;
    }

    /**
     * @return array{
     *   signer:\WlsGatewayPackageSigner,
     *   sign_options:array<string,string>,
     *   package:string,
     *   unsigned_manifest:string,
     *   audit_receipt:string,
     *   audit_receipt_bytes:string
     * }
     */
    private function productionSigningFixture(string $name): array
    {
        $keyPair = \sodium_crypto_sign_keypair();
        $secret = \sodium_crypto_sign_secretkey($keyPair);
        $public = \sodium_crypto_sign_publickey($keyPair);
        $secretFile = $this->root . DIRECTORY_SEPARATOR . $name . '.secret';
        self::assertNotFalse(\file_put_contents(
            $secretFile,
            \base64_encode($secret) . PHP_EOL,
        ));
        self::assertTrue(\chmod($secretFile, 0600));
        $trustedKeys = $this->root . DIRECTORY_SEPARATOR . $name . '.trusted.json';
        self::assertNotFalse(\file_put_contents(
            $trustedKeys,
            \json_encode([
                'schema_version' => 1,
                'keys' => [[
                    'id' => 'fixture-release-key',
                    'algorithm' => 'ed25519',
                    'enabled' => true,
                    'public_key_base64' => \base64_encode($public),
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $definitions = [];
        foreach ([
            'controller',
            'php',
            'nginx',
            'wls-gateway-broker',
            'wls-gateway-launcher',
        ] as $component) {
            $path = $this->inputs[$component];
            $definitions[$component] = [
                'version' => 'fixture-1',
                'source_url' => 'https://example.invalid/' . $component,
                'source_sha256' => \hash_file('sha256', $path),
                'binary_sha256' => \hash_file('sha256', $path),
                'license' => 'test-only',
                'self_contained' => $component !== 'controller',
            ];
        }
        $provenance = $this->root . DIRECTORY_SEPARATOR . $name . '.provenance.json';
        self::assertNotFalse(\file_put_contents(
            $provenance,
            \json_encode([
                'schema_version' => 1,
                'target' => [
                    'platform' => \PHP_OS_FAMILY,
                    'arch' => $this->normalizedArch(),
                ],
                'components' => $definitions,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        $package = $this->root . DIRECTORY_SEPARATOR . $name;
        (new \WlsGatewayPackageBuilder())->build(
            $this->options($package, 'production') + ['provenance' => $provenance],
        );
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $unsignedManifest = (string)\file_get_contents($manifestFile);
        $auditReceipt = $this->root . DIRECTORY_SEPARATOR . $name . '.audit.json';
        $signer = new \WlsGatewayPackageSigner();
        $audit = $signer->audit([
            'package' => $package,
            'receipt-output' => $auditReceipt,
        ]);
        $signOptions = [
            'package' => $package,
            'audit-receipt' => $auditReceipt,
            'expected-audit-environment-sha256' => (string)$audit['audit_environment_sha256'],
            'signing-key-id' => 'fixture-release-key',
            'signing-key-file' => $secretFile,
            'trusted-keys' => $trustedKeys,
        ];
        \sodium_memzero($secret);

        return [
            'signer' => $signer,
            'sign_options' => $signOptions,
            'package' => $package,
            'unsigned_manifest' => $unsignedManifest,
            'audit_receipt' => $auditReceipt,
            'audit_receipt_bytes' => (string)\file_get_contents($auditReceipt),
        ];
    }

    private function tamperAuditReceipt(string $file): void
    {
        $receipt = $this->json($file);
        $receipt['manifest_sha256'] = \str_repeat('0', 64);
        self::assertNotFalse(\file_put_contents(
            $file,
            \json_encode(
                $receipt,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
    }

    /** @param array<string,array{bytes:string,mode:int}> $files */
    private function windowsBootstrapFixture(string $name, array $files): string
    {
        $directory = $this->root . DIRECTORY_SEPARATOR . $name;
        self::assertTrue(\mkdir($directory, 0700));
        foreach ($files as $leaf => $definition) {
            $file = $directory . DIRECTORY_SEPARATOR . $leaf;
            self::assertNotFalse(\file_put_contents($file, $definition['bytes']));
            self::assertTrue(\chmod($file, $definition['mode']));
        }
        return $directory;
    }

    /** @return array<string,array<string,int|string>> */
    private function windowsBootstrapIdentities(
        \WlsGatewayPackageSigner $signer,
        string $directory,
    ): array {
        $identity = new \ReflectionMethod(
            \WlsGatewayPackageSigner::class,
            'securePathIdentity',
        );
        return [
            'bootstrap_parent' => $identity->invoke(
                $signer,
                \dirname($directory),
                true,
            ),
            'bootstrap_stage' => $identity->invoke($signer, $directory, true, 0700),
            'bootstrap_helper' => $identity->invoke(
                $signer,
                $directory . DIRECTORY_SEPARATOR . 'wls-bounded-command.exe',
                false,
                0755,
            ),
            'bootstrap_manifest' => $identity->invoke(
                $signer,
                $directory . DIRECTORY_SEPARATOR . 'wls-bounded-command.manifest.json',
                false,
                0644,
            ),
            'bootstrap_signature' => $identity->invoke(
                $signer,
                $directory . DIRECTORY_SEPARATOR . 'wls-bounded-command.manifest.sig',
                false,
                0644,
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function json(string $file): array
    {
        return \json_decode(
            (string)\file_get_contents($file),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    private function normalizedArch(): string
    {
        return match (\strtolower((string)\php_uname('m'))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => \strtolower((string)\php_uname('m')),
        };
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink()
                ? @\rmdir($path)
                : @\unlink($path);
        }
        @\rmdir($root);
    }
}
