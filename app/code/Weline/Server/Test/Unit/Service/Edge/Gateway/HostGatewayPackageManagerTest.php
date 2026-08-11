<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayGuardianGenerationHead;
use Weline\Server\Service\Edge\Gateway\GatewayGuardianTransitionProtocol;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayInitialBootstrapJournal;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPlatformServiceInstaller;
use Weline\Server\Service\Edge\Gateway\GatewayProjectStateFilesystem;
use Weline\Server\Service\Edge\Gateway\GatewayRebootstrapCrashSimulation;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

final class HostGatewayPackageManagerTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';
    /** @var array<string,string> */
    private array $certificateTrustBundles = [];
    private GatewayPaths $paths;

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE',
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256',
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT',
            'WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-gateway-package-'
            . \bin2hex(\random_bytes(8));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . DIRECTORY_SEPARATOR . 'host');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=22080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=22443');
        \putenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE');
        \putenv('WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256');
        \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        \putenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT');
        $this->paths = new GatewayPaths();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
    }

    public function testPackageLockContentionCannotOutliveLifecycleDeadline(): void
    {
        $this->paths->ensureDirectories();
        $lockFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'package-install.lock';
        $handle = \fopen($lockFile, 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(\flock($handle, LOCK_EX | LOCK_NB));
        $started = \hrtime(true) / 1_000_000_000;
        try {
            (new HostGatewayPackageManager($this->paths))
                ->assertNoActiveRebootstrap(
                    'deadline regression',
                    $started + 0.15,
                );
            self::fail('Contended package lock must honor the outer deadline.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Timed out acquiring the host-gateway installation lock',
                $exception->getMessage(),
            );
            self::assertLessThan(
                1.0,
                \hrtime(true) / 1_000_000_000 - $started,
            );
        } finally {
            @\flock($handle, LOCK_UN);
            @\fclose($handle);
        }
    }

    /**
     * @dataProvider initialBootstrapCrashPhases
     */
    public function testInitialBootstrapJournalReplaysEveryCommittedCrashWindow(
        string $fault,
        string $expectedPhase,
    ): void {
        $package = $this->createPackage('initial-crash-' . $fault);
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $packages = new HostGatewayPackageManager(
            $this->paths,
            platform: $platform,
        );
        $manager = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );
        \putenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT=' . $fault);

        $interrupted = $manager->install(
            $package,
            'default',
            (\hrtime(true) / 1_000_000_000) + 20.0,
        );

        self::assertSame('BOOTSTRAP_INTERRUPTED', $interrupted['state']);
        $journal = \json_decode((string)\file_get_contents(
            $this->paths->initialBootstrapJournalFile(),
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($journal);
        self::assertSame($expectedPhase, $journal['phase']);
        self::assertSame('BOOTSTRAP_RECOVERY_REQUIRED', $manager->prepare(
            ['ok' => false, 'ready' => false, 'state' => 'UNAVAILABLE'],
            (\hrtime(true) / 1_000_000_000) + 5.0,
        )['state']);

        \putenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT');
        $replayed = $manager->install(
            $package,
            'default',
            (\hrtime(true) / 1_000_000_000) + 20.0,
        );

        self::assertSame('TEST_PACKAGE_INSTALLED', $replayed['state']);
        self::assertSame('B', $this->paths->activeSlot());
        $committed = \json_decode((string)\file_get_contents(
            $this->paths->initialBootstrapJournalFile(),
        ), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('VERIFIED', $committed['phase']);
        self::assertSame('', $committed['package_path']);
        self::assertSame('REPAIR_REQUIRED', $manager->prepare(
            ['ok' => false, 'ready' => false, 'state' => 'DATA_PLANE_DOWN'],
            (\hrtime(true) / 1_000_000_000) + 5.0,
        )['state']);
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function initialBootstrapCrashPhases(): iterable
    {
        yield 'stage after host identity' => ['stage-after-host-id', 'PREPARING'];
        yield 'stage returned before journal advance' => ['after-stage', 'PREPARING'];
        yield 'definition committed before return assignment' => ['definition-after-commit', 'STAGED'];
        yield 'definition journal committed' => ['after-definition', 'DEFINITION_INSTALLED'];
        yield 'activation committed before journal advance' => ['after-activate', 'DEFINITION_INSTALLED'];
        yield 'start returned before journal advance' => ['after-start', 'ACTIVATED'];
    }

    public function testInitialBootstrapJournalRejectsDifferentPackageFingerprint(): void
    {
        $first = $this->createPackage('initial-fingerprint-first');
        $second = $this->createPackage('initial-fingerprint-second');
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $packages = new HostGatewayPackageManager(
            $this->paths,
            platform: $platform,
        );
        $manager = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );
        \putenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT=stage-after-host-id');
        self::assertSame('BOOTSTRAP_INTERRUPTED', $manager->install(
            $first,
            'default',
            (\hrtime(true) / 1_000_000_000) + 20.0,
        )['state']);
        \putenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT');

        $result = $manager->install(
            $second,
            'default',
            (\hrtime(true) / 1_000_000_000) + 20.0,
        );

        self::assertSame('REPAIR_REQUIRED', $result['state']);
        self::assertFalse(\file_exists($this->paths->activeSlotFile()));
    }

    public function testStagedBootstrapReplayDoesNotReadTheDeletedBootstrapProject(): void
    {
        $package = $this->createPackage('staged-project-independence');
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $packages = new HostGatewayPackageManager(
            $this->paths,
            platform: $platform,
        );
        $manager = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );
        \putenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT=after-definition');
        self::assertSame('BOOTSTRAP_INTERRUPTED', $manager->install(
            $package,
            'default',
            (\hrtime(true) / 1_000_000_000) + 20.0,
        )['state']);
        \putenv('WLS_GATEWAY_TEST_INITIAL_BOOTSTRAP_FAULT');
        $this->removeTree($package);

        $replayed = $manager->install(
            $package,
            'default',
            (\hrtime(true) / 1_000_000_000) + 20.0,
        );

        self::assertSame('TEST_PACKAGE_INSTALLED', $replayed['state']);
        self::assertSame('B', $this->paths->activeSlot());
        self::assertTrue(\is_file($this->paths->launcherFile()));
    }

    public function testProjectPackageSwapAfterJournalBeginCannotReachPlatformDefinition(): void
    {
        $first = $this->createPackage('stage-toctou-first');
        $second = $this->createPackage('stage-toctou-second');
        $displaced = $this->root . DIRECTORY_SEPARATOR . 'stage-toctou-displaced';
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $swapped = false;
        $packages = new HostGatewayPackageManager(
            $this->paths,
            platform: $platform,
            beforeStageVerification: static function () use (
                $first,
                $second,
                $displaced,
                &$swapped,
            ): void {
                if ($swapped) {
                    return;
                }
                self::assertTrue(\rename($first, $displaced));
                self::assertTrue(\rename($second, $first));
                $swapped = true;
            },
        );
        $manager = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );

        $result = $manager->install(
            $first,
            'default',
            (\hrtime(true) / 1_000_000_000) + 20.0,
        );

        self::assertTrue($swapped);
        self::assertSame('INSTALL_FAILED', $result['state']);
        self::assertStringContainsString('fingerprint', \strtolower($result['reason']));
        self::assertFalse(\file_exists($this->paths->serviceDefinitionFile()));
        self::assertFalse(\file_exists($this->paths->platformServiceMetadataFile()));
        self::assertFalse(\file_exists($this->paths->activeSlotFile()));
        self::assertFalse(\file_exists($this->paths->initialBootstrapJournalFile()));
    }

    public function testTestPackageStagesAsImmutableSlotAndNeverClaimsReleaseReady(): void
    {
        $package = $this->createPackage();
        $manager = new HostGatewayPackageManager($this->paths);

        $staged = $manager->stage($package, 'default');
        self::assertFalse($staged['release_ready']);
        self::assertTrue($staged['test_mode']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $staged['runtime_generation']);
        self::assertFileExists(
            $staged['slot_dir'] . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
                . $this->binaryName('wls-gateway-broker'),
        );
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertFileExists(
                $staged['slot_dir'] . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
                    . 'wls-bounded-command.exe',
            );
        }
        self::assertFileExists($this->paths->launcherFile());

        $manager->activate($staged['slot']);
        self::assertSame($staged['slot'], $this->paths->activeSlot());
        if (\PHP_OS_FAMILY !== 'Windows') {
            $parent = \stat($this->paths->trustDir());
            self::assertIsArray($parent);
            $state = \stat($this->paths->activeSlotFile());
            self::assertIsArray($state);
            self::assertSame($parent['uid'], $state['uid']);
            self::assertSame($parent['gid'], $state['gid']);
        }
        self::assertFileDoesNotExist($this->paths->previousSlotFile());
        $installed = $manager->installedManifest($staged['slot']);
        self::assertFalse($installed['release_ready']);
        self::assertTrue($installed['test_mode']);
        self::assertSame('native-broker-v1', $installed['security_profile']);
        self::assertSame(
            HostGatewayPackageManager::DURABLE_STATE_CONTRACT,
            $installed['durable_state_contract'],
        );
    }

    public function testInstalledGatewayNoLongerDependsOnBootstrapProjectPackage(): void
    {
        $package = $this->createPackage('bootstrap-project-source');
        $packageCanonical = (string)\realpath($package);
        self::assertNotSame('', $packageCanonical);
        $manager = new HostGatewayPackageManager($this->paths);
        $staged = $manager->stage($package, 'default');
        $manager->activate((string)$staged['slot']);

        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $service = $installer->installDefinition('default');
        $definition = (string)\file_get_contents((string)$service['path']);
        $installedManifest = (string)\file_get_contents(
            $staged['slot_dir'] . DIRECTORY_SEPARATOR . 'manifest.json',
        );

        $this->removeTree($package);

        self::assertDirectoryDoesNotExist($package);
        self::assertStringNotContainsString($packageCanonical, $definition);
        self::assertStringNotContainsString($packageCanonical, $installedManifest);
        self::assertStringContainsString($this->paths->home(), $definition);
        self::assertStringContainsString($this->paths->guardianFile(), $definition);
        self::assertFileExists($this->paths->launcherFile());
        self::assertFileExists($this->paths->activeSlotFile());
        self::assertSame((string)$staged['slot'], $this->paths->activeSlot());
        self::assertDirectoryExists($this->paths->slotDir((string)$staged['slot']));
        self::assertSame(
            (string)$staged['runtime_generation'],
            (string)$manager->installedManifest((string)$staged['slot'])['runtime_generation'],
        );
    }

    public function testPackageWithoutExactDurableStateContractIsRejected(): void
    {
        $package = $this->createPackage('missing-durable-contract');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode(
            (string)\file_get_contents($manifestFile),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        unset($manifest['durable_state_contract']['snapshot_namespace']);
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'does not declare the exact WLS 2.0 durable-state contract v2',
        );
        (new HostGatewayPackageManager($this->paths))->verifyPackage(
            $package,
            'default',
        );
    }

    public function testProductionSlotAndGlobalLauncherModesUseDistinctContracts(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $lines = \explode("\n", $source);
        $methodSource = static function (string $name) use ($lines): string {
            $method = new \ReflectionMethod(HostGatewayPackageManager::class, $name);
            return \implode("\n", \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
        };

        self::assertStringContainsString(
            'return ($packageMode & 0111) !== 0 ? 0555 : 0444;',
            $methodSource('installedComponentMode'),
        );
        self::assertStringContainsString(
            'return $this->paths->isTestMode() ? 0755 : 0550;',
            $methodSource('stableLauncherPosixMode'),
        );
        self::assertStringContainsString(
            '$mode = $this->stableLauncherPosixMode();',
            $methodSource('copyStableLauncher'),
        );
        $globalProof = $methodSource('verifiedStableLauncherUpgradeProof');
        self::assertStringContainsString(
            '$this->assertStableLauncherPermissions($launcher);',
            $globalProof,
        );
        self::assertStringNotContainsString(
            "launcherAfter['mode']",
            $globalProof,
            'Global 0550 must not be compared to an immutable slot launcher at 0555.',
        );
    }

    public function testPackageWithoutStableLauncherRollbackTargetProofIsRejected(): void
    {
        $package = $this->createPackage('missing-stable-launcher-proof');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode(
            (string)\file_get_contents($manifestFile),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        unset($manifest['capabilities']['stable_launcher_rollback_target_proof']);
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Gateway package capability is missing: stable_launcher_rollback_target_proof',
        );
        (new HostGatewayPackageManager($this->paths))->verifyPackage(
            $package,
            'default',
        );
    }

    public function testTamperTraversalAndSymlinkEntriesAreRejected(): void
    {
        $package = $this->createPackage();
        $manager = new HostGatewayPackageManager($this->paths);
        $nginx = $package . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
            . $this->binaryName('nginx');
        self::assertNotFalse(\file_put_contents($nginx, "#!/bin/sh\nexit 7\n"));
        try {
            $manager->verifyPackage($package, 'default');
            self::fail('A modified package component must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('verification failed', $exception->getMessage());
        }

        $package = $this->createPackage('traversal');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode((string)\file_get_contents($manifestFile), true);
        self::assertIsArray($manifest);
        $manifest['components']['../escape'] = $manifest['components']['LICENSES.txt'];
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        try {
            $manager->verifyPackage($package, 'default');
            self::fail('A traversal component path must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'unsafe platform segment',
                $exception->getMessage(),
            );
        }

        if (\PHP_OS_FAMILY !== 'Windows') {
            $package = $this->createPackage('symlink');
            $component = $package . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'controller.php';
            self::assertTrue(\unlink($component));
            self::assertTrue(\symlink('/etc/hosts', $component));
            try {
                $manager->verifyPackage($package, 'default');
                self::fail('A symbolic-link component must be rejected.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('missing or is a link', $exception->getMessage());
            }
        }
    }

    public function testManifestComponentAndDirectoryLimitsFailBeforeFileTraversal(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $package = $this->createPackage('component-limit');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode((string)\file_get_contents($manifestFile), true);
        self::assertIsArray($manifest);
        $definition = $manifest['components']['LICENSES.txt'];
        for ($index = 0; $index <= HostGatewayPackageManager::MAX_PACKAGE_COMPONENTS; ++$index) {
            $manifest['components']['extra-' . $index] = $definition;
        }
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        try {
            $manager->verifyPackage($package, 'default');
            self::fail('An oversized signed component map must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('component limit', $exception->getMessage());
        }

        $package = $this->createPackage('directory-limit');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode((string)\file_get_contents($manifestFile), true);
        self::assertIsArray($manifest);
        $definition = $manifest['components']['LICENSES.txt'];
        $paths = \intdiv(HostGatewayPackageManager::MAX_PACKAGE_DIRECTORIES, 3) + 1;
        for ($index = 0; $index < $paths; ++$index) {
            $manifest['components'][
                'd' . $index . '/s' . $index . '/t' . $index . '/file'
            ] = $definition;
        }
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        try {
            $manager->verifyPackage($package, 'default');
            self::fail('An oversized signed directory topology must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('directory limit', $exception->getMessage());
        }

        $package = $this->createPackage('path-depth-limit');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode((string)\file_get_contents($manifestFile), true);
        self::assertIsArray($manifest);
        $manifest['components'][
            \implode('/', \array_fill(
                0,
                HostGatewayPackageManager::MAX_PACKAGE_PATH_DEPTH + 1,
                'd',
            ))
        ] = $manifest['components']['LICENSES.txt'];
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        try {
            $manager->verifyPackage($package, 'default');
            self::fail('An oversized signed path depth must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('depth limit', $exception->getMessage());
        }
    }

    public function testManifestRejectsFileAndDirectoryPrefixCollisions(): void
    {
        $package = $this->createPackage('prefix-collision');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode((string)\file_get_contents($manifestFile), true);
        self::assertIsArray($manifest);
        $manifest['components']['LICENSES.txt/inventory']
            = $manifest['components']['LICENSES.txt'];
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('collides with a declared directory');
        (new HostGatewayPackageManager($this->paths))->verifyPackage(
            $package,
            'default',
        );
    }

    public function testManifestCannotShadowInstalledReleaseMetadata(): void
    {
        $package = $this->createPackage('reserved-release-namespace');
        $manifestFile = $package . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode((string)\file_get_contents($manifestFile), true);
        self::assertIsArray($manifest);
        $manifest['components']['release/manifest.json']
            = $manifest['components']['LICENSES.txt'];
        self::assertNotFalse(\file_put_contents(
            $manifestFile,
            \json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reserved installed release namespace');
        (new HostGatewayPackageManager($this->paths))->verifyPackage(
            $package,
            'default',
        );
    }

    public function testUpgradeRejectsPreReleaseSlotWithoutContractV2BeforePointerSwitch(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('contract-v2-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $this->rewriteInstalledManifest(
            $initial['slot'],
            static function (array $manifest): array {
                unset($manifest['durable_state_contract']);
                return $manifest;
            },
        );
        $candidate = $manager->stage(
            $this->createPackage('contract-v2-candidate'),
            'default',
        );

        try {
            $manager->beginUpgradeActivation($candidate);
            self::fail('A pre-release host slot must not enter an A/B v2 transaction.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }
        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertFileDoesNotExist($this->paths->upgradeIntentFile());
    }

    public function testUpgradeRejectsAnActiveSlotWithoutStableLauncherProofBeforeIntent(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('launcher-proof-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $this->rewriteInstalledManifest(
            $initial['slot'],
            static function (array $manifest): array {
                $manifest['capabilities']['stable_launcher_rollback_target_proof'] = false;
                return $manifest;
            },
        );
        $candidate = $manager->stage(
            $this->createPackage('launcher-proof-candidate'),
            'default',
        );

        try {
            $manager->beginUpgradeActivation($candidate);
            self::fail('An unproved stable launcher must not create an upgrade intent.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'stable_launcher_rollback_target_proof',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }
        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertFileDoesNotExist($this->paths->upgradeIntentFile());
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testUpgradeRejectsStableLauncherIdentityMismatchBeforeIntent(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('launcher-identity-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('launcher-identity-candidate'),
            'default',
        );
        $identity = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        self::assertNotFalse(\file_put_contents(
            $identity,
            \str_repeat('0', 64) . PHP_EOL,
        ));

        try {
            $manager->beginUpgradeActivation($candidate);
            self::fail('A mismatched global launcher identity must not create an intent.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }

        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertFileDoesNotExist($this->paths->upgradeIntentFile());
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testUpgradeRejectsStableLauncherModeMismatchBeforeIntent(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Windows ACLs replace POSIX launcher mode bits.');
        }
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('launcher-mode-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('launcher-mode-candidate'),
            'default',
        );
        self::assertTrue(\chmod($this->paths->launcherFile(), 0644));

        try {
            $manager->beginUpgradeActivation($candidate);
            self::fail('A mode-mismatched global launcher must not create an intent.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }

        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertFileDoesNotExist($this->paths->upgradeIntentFile());
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testUpgradeRejectsSlotLauncherByteMismatchBeforeIntent(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('slot-launcher-bytes-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('slot-launcher-bytes-candidate'),
            'default',
        );
        $slotLauncher = $initial['slot_dir'] . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . $this->binaryName('wls-gateway-launcher');
        self::assertNotFalse(\file_put_contents(
            $slotLauncher,
            "#!/bin/sh\n# forged-launcher\nexit 0\n",
        ));

        try {
            $manager->beginUpgradeActivation($candidate);
            self::fail('Changed slot launcher bytes must not create an upgrade intent.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }

        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertFileDoesNotExist($this->paths->upgradeIntentFile());
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testUpgradeRollbackRejectsTargetWithoutContractV2BeforePointerSwitch(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('contract-v2-rollback-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('contract-v2-rollback-candidate'),
            'default',
        );
        $observation = $manager->beginUpgradeActivation($candidate);
        $this->rewriteInstalledManifest(
            $initial['slot'],
            static function (array $manifest): array {
                unset($manifest['durable_state_contract']);
                return $manifest;
            },
        );

        try {
            $manager->rollbackUpgradeActivation(
                $candidate['slot'],
                $initial['slot'],
                $observation['rollback_context'],
            );
            self::fail('Automatic rollback must reject an old draft target slot.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }
        self::assertSame($candidate['slot'], $this->paths->activeSlot());
    }

    public function testRetainedV2IntentCannotRollbackUnderAnUnprovedStableLauncher(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('retained-launcher-proof-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('retained-launcher-proof-candidate'),
            'default',
        );
        $observation = $manager->beginUpgradeActivation($candidate);
        $intentFile = $this->paths->upgradeIntentFile();
        $intentBefore = (string)\file_get_contents($intentFile);
        $this->rewriteInstalledManifest(
            $initial['slot'],
            static function (array $manifest): array {
                unset($manifest['capabilities']['stable_launcher_rollback_target_proof']);
                return $manifest;
            },
        );

        try {
            $manager->rollbackUpgradeActivation(
                $candidate['slot'],
                $initial['slot'],
                $observation['rollback_context'],
            );
            self::fail('A retained v2 intent must not run under an unproved launcher.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }
        self::assertSame($candidate['slot'], $this->paths->activeSlot());
        self::assertSame($intentBefore, \file_get_contents($intentFile));
        self::assertFileDoesNotExist(
            $this->paths->stateDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rollback.request',
        );
        self::assertDirectoryExists($this->paths->slotDir($initial['slot']));
    }

    public function testDiscardStagedPreservesBothSlotsAndAValidLiveUpgradeIntent(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('discard-live-intent-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('discard-live-intent-candidate'),
            'default',
        );
        $manager->beginUpgradeActivation($candidate);
        $intentFile = $this->paths->upgradeIntentFile();
        $intent = (string)\file_get_contents($intentFile);

        try {
            $manager->discardStaged($initial['slot']);
            self::fail('A live A/B transaction must fence both referenced slots.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('blocks deletion', $exception->getMessage());
        }

        self::assertSame($candidate['slot'], $this->paths->activeSlot());
        self::assertSame($initial['slot'] . PHP_EOL, \file_get_contents(
            $this->paths->previousSlotFile(),
        ));
        self::assertSame($intent, \file_get_contents($intentFile));
        self::assertDirectoryExists($initial['slot_dir']);
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testDiscardStagedPreservesMalformedIntentForExplicitRebootstrap(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('discard-malformed-intent-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('discard-malformed-intent-candidate'),
            'default',
        );
        $intentFile = $this->paths->upgradeIntentFile();
        $malformed = "WLS-UPGRADE/2\ntruncated=true\n";
        self::assertNotFalse(\file_put_contents($intentFile, $malformed));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($intentFile, 0600));
        }

        try {
            $manager->discardStaged($candidate['slot']);
            self::fail('Ambiguous upgrade evidence must survive staged-slot discard.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }

        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertSame($malformed, \file_get_contents($intentFile));
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testDiscardStagedRejectsLegacyV1IntentAndPreservesEvidence(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('discard-v1-intent-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('discard-v1-intent-candidate'),
            'default',
        );
        $prepared = \time();
        $payload = "WLS-UPGRADE/1\n"
            . 'host_id=' . \trim((string)\file_get_contents(
                $this->paths->hostIdFile(),
            )) . "\n"
            . 'from=' . $initial['slot'] . "\n"
            . 'to=' . $candidate['slot'] . "\n"
            . 'prepared_at=' . $prepared . "\n"
            . 'deadline=' . ($prepared + 300) . "\n"
            . 'runtime_generation=' . $candidate['runtime_generation'] . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $secret = \hex2bin(\trim((string)\file_get_contents(
            $this->paths->adminTokenFile(),
        )));
        self::assertIsString($secret);
        try {
            $intent = $payload . 'signature='
                . \hash_hmac('sha256', $payload, $secret) . "\n";
        } finally {
            \sodium_memzero($secret);
        }
        self::assertNotFalse(\file_put_contents(
            $this->paths->upgradeIntentFile(),
            $intent,
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($this->paths->upgradeIntentFile(), 0600));
        }

        try {
            $manager->discardStaged($candidate['slot']);
            self::fail('A legacy intent must require explicit full host rebootstrap.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }

        self::assertSame($intent, \file_get_contents(
            $this->paths->upgradeIntentFile(),
        ));
        self::assertDirectoryExists($candidate['slot_dir']);
        self::assertSame($initial['slot'], $this->paths->activeSlot());
    }

    public function testDiscardStagedPreservesOrphanRollbackAndStateEvidence(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('discard-orphan-evidence-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('discard-orphan-evidence-candidate'),
            'default',
        );
        $evidence = [
            $this->paths->stateDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rollback.request' => "orphan-request\n",
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'upgrade-state' => "orphan-state\n",
        ];

        foreach ($evidence as $path => $contents) {
            self::assertNotFalse(\file_put_contents($path, $contents));
            if (\PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(\chmod($path, 0600));
            }
            try {
                $manager->discardStaged($candidate['slot']);
                self::fail('Orphan recovery evidence must fence staged-slot deletion.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'explicit full host rebootstrap',
                    $exception->getMessage(),
                );
            }
            self::assertSame($contents, \file_get_contents($path));
            self::assertDirectoryExists($candidate['slot_dir']);
            self::assertTrue(\unlink($path));
        }
    }

    public function testDiscardStagedPreservesValidRetentionAndRollbackEvidence(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('discard-valid-evidence-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('discard-valid-evidence-candidate'),
            'default',
        );
        $retentionFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'slot-retention';
        $retention = "WLS-SLOT-RETENTION/1\n"
            . 'slot=' . $candidate['slot'] . "\n"
            . 'retain_until=' . (\time() + 86_400) . "\n";
        self::assertNotFalse(\file_put_contents($retentionFile, $retention));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($retentionFile, 0600));
        }

        try {
            $manager->discardStaged($candidate['slot']);
            self::fail('Valid slot-retention evidence must fence deletion.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('blocks deletion', $exception->getMessage());
        }
        self::assertSame($retention, \file_get_contents($retentionFile));
        self::assertDirectoryExists($candidate['slot_dir']);
        self::assertTrue(\unlink($retentionFile));

        $rolledBackFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'upgrade-rolled-back';
        $rolledBack = "WLS-UPGRADE-ROLLED-BACK/3\n"
            . 'intent_sha256=' . \str_repeat('a', 64) . "\n"
            . 'intent_nonce=' . \str_repeat('b', 32) . "\n"
            . 'from=' . $initial['slot'] . "\n"
            . 'to=' . $candidate['slot'] . "\n"
            . 'runtime_generation=' . $candidate['runtime_generation'] . "\n"
            . 'at=' . \time() . "\n";
        self::assertNotFalse(\file_put_contents($rolledBackFile, $rolledBack));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($rolledBackFile, 0600));
        }

        try {
            $manager->discardStaged($candidate['slot']);
            self::fail('Valid rollback evidence must fence deletion.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('blocks deletion', $exception->getMessage());
        }
        self::assertSame($rolledBack, \file_get_contents($rolledBackFile));
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testDiscardStagedRemovesOnlyTheExactInactiveSlotWithoutEvidence(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('discard-clean-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('discard-clean-candidate'),
            'default',
        );

        $manager->discardStaged($candidate['slot']);

        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertDirectoryExists($initial['slot_dir']);
        self::assertDirectoryDoesNotExist($candidate['slot_dir']);
        self::assertFileDoesNotExist($this->paths->upgradeIntentFile());
    }

    public function testDiscardStagedDeletionRemainsInsideTheInstallLockFinalFence(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $discard = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'discardStagedWithinDeadline',
        );
        $discardSource = \implode("\n", \array_slice(
            \explode("\n", $source),
            $discard->getStartLine() - 1,
            $discard->getEndLine() - $discard->getStartLine() + 1,
        ));
        $installLock = \strpos($discardSource, '$this->withInstallLock(');
        $remove = \strpos(
            $discardSource,
            '$this->removeDiscardTargetLocked($slot, $active);',
        );
        $lockClosureEnd = \strpos($discardSource, 'if ($initialCleanup)');
        self::assertIsInt($installLock);
        self::assertIsInt($remove);
        self::assertIsInt($lockClosureEnd);
        self::assertTrue($installLock < $remove && $remove < $lockClosureEnd);

        $helper = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'removeDiscardTargetLocked',
        );
        $helperSource = \implode("\n", \array_slice(
            \explode("\n", $source),
            $helper->getStartLine() - 1,
            $helper->getEndLine() - $helper->getStartLine() + 1,
        ));
        $processFence = \strpos(
            $helperSource,
            '$this->assertSlotHasNoLiveProcesses(',
        );
        $treeSelection = \strpos(
            $helperSource,
            '$entries = $this->collectRemovableTree($directory);',
        );
        $activeFence = \strpos($helperSource, '$active = $this->activeSlotOrEmpty();');
        $transactionFence = \strpos(
            $helperSource,
            '$this->assertNoSlotDeletionRecoveryTransactionLocked(',
        );
        $identityFence = \strpos($helperSource, '$after = @\\lstat($directory);');
        $unlink = \strpos(
            $helperSource,
            '$this->removeCollectedTree($directory, $entries);',
        );
        self::assertIsInt($processFence);
        self::assertIsInt($treeSelection);
        self::assertIsInt($activeFence);
        self::assertIsInt($transactionFence);
        self::assertIsInt($identityFence);
        self::assertIsInt($unlink);
        self::assertTrue(
            $processFence < $treeSelection
                && $treeSelection < $activeFence
                && $activeFence < $transactionFence
                && $transactionFence < $identityFence
                && $identityFence < $unlink,
        );
    }

    public function testUpgradeIntentPublicationHasLockedLauncherProofBeforeAndAfterMutation(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $lines = \explode("\n", $source);
        $methodSource = static function (string $name) use ($lines): string {
            $method = new \ReflectionMethod(HostGatewayPackageManager::class, $name);
            return \implode("\n", \array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1,
            ));
        };

        $begin = $methodSource('beginUpgradeActivationWithinDeadline');
        $before = \strpos(
            $begin,
            '$launcherProof = $this->verifiedStableLauncherUpgradeProof(',
        );
        $intentWrite = \strpos(
            $begin,
            '$this->atomicWrite($this->paths->upgradeIntentFile(), $intent, 0600);',
        );
        $after = \strpos(
            $begin,
            '$launcherProofAfter = $this->verifiedStableLauncherUpgradeProof(',
        );
        self::assertIsInt($before);
        self::assertIsInt($intentWrite);
        self::assertIsInt($after);
        self::assertTrue($before < $intentWrite && $intentWrite < $after);

        $rollback = $methodSource('rollbackUpgradeActivationWithinDeadline');
        $rollbackBefore = \strpos(
            $rollback,
            '$launcherProof = $this->verifiedStableLauncherUpgradeProof(',
        );
        $pointerWrite = \strpos(
            $rollback,
            '$this->paths->activeSlotFile(),',
        );
        $rollbackAfter = \strpos(
            $rollback,
            '$launcherProofAfter = $this->verifiedStableLauncherUpgradeProof(',
        );
        self::assertIsInt($rollbackBefore);
        self::assertIsInt($pointerWrite);
        self::assertIsInt($rollbackAfter);
        self::assertTrue(
            $rollbackBefore < $pointerWrite && $pointerWrite < $rollbackAfter,
        );

        $proof = $methodSource('verifiedStableLauncherSlotProof')
            . $methodSource('verifiedStableLauncherUpgradeProof');
        self::assertGreaterThanOrEqual(
            2,
            \substr_count($proof, 'stable_launcher_rollback_target_proof'),
        );
        self::assertStringContainsString('$releaseLauncherMode', $proof);
        self::assertStringContainsString('$installedLauncherMode', $proof);
        self::assertStringContainsString('$this->paths->launcherFile()', $proof);
        self::assertStringContainsString("'stable-launcher.sha256'", $proof);
        self::assertStringContainsString(
            '$this->assertStableLauncherPermissions($launcher);',
            $proof,
        );
        self::assertStringContainsString(
            'A/B slots do not declare one stable launcher and CA trust generation.',
            $proof,
        );
        self::assertStringContainsString(
            'explicit full host rebootstrap ',
            $source,
        );
        self::assertStringContainsString(
            'from a signed package; ordinary ',
            $source,
        );
        self::assertStringNotContainsString('server:gateway:repair', $proof);
    }

    public function testInstallationRollbackRejectsTargetWithoutContractV2(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('contract-v2-install-rollback-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('contract-v2-install-rollback-candidate'),
            'default',
        );
        self::assertNotFalse(\file_put_contents(
            $this->paths->activeSlotFile(),
            $candidate['slot'] . PHP_EOL,
        ));
        $this->rewriteInstalledManifest(
            $initial['slot'],
            static function (array $manifest): array {
                unset($manifest['durable_state_contract']);
                return $manifest;
            },
        );

        try {
            $manager->rollbackActivation(
                $candidate['slot'],
                $initial['slot'],
            );
            self::fail('Installation rollback must reject an old draft target slot.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }
        self::assertSame($candidate['slot'], $this->paths->activeSlot());
    }

    public function testUpgradeRollbackRejectsAForgedSignedIntent(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('intent-initial'), 'default');
        $manager->activate($initial['slot']);
        $candidate = $manager->stage($this->createPackage('intent-candidate'), 'default');
        $observation = $manager->beginUpgradeActivation($candidate);
        $intentFile = $this->paths->upgradeIntentFile();
        $intent = (string)\file_get_contents($intentFile);
        self::assertMatchesRegularExpression('/signature=[a-f0-9]{64}\n\z/D', $intent);
        $lastSignatureOffset = \strlen($intent) - 2;
        $intent[$lastSignatureOffset] = $intent[$lastSignatureOffset] === 'f' ? 'e' : 'f';
        self::assertNotFalse(\file_put_contents($intentFile, $intent));

        try {
            $manager->rollbackUpgradeActivation(
                $candidate['slot'],
                $initial['slot'],
                $observation['rollback_context'],
            );
            self::fail('A forged retained intent must require explicit rebootstrap.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
            self::assertInstanceOf(\RuntimeException::class, $exception->getPrevious());
            self::assertStringContainsString(
                'authentication failed',
                $exception->getPrevious()->getMessage(),
            );
        }
        self::assertSame($intent, \file_get_contents($intentFile));
        self::assertSame($candidate['slot'], $this->paths->activeSlot());
    }

    public function testUpgradeRollbackCollectsOnlyAFullyBoundCurrentRequestBackup(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('rollback-clean-initial'), 'default');
        $manager->activate($initial['slot']);
        $candidate = $manager->stage($this->createPackage('rollback-clean-candidate'), 'default');
        $observation = $manager->beginUpgradeActivation($candidate);
        $intent = (string)\file_get_contents($this->paths->upgradeIntentFile());
        self::assertSame(1, \preg_match(
            '/nonce=([a-f0-9]{32})/D',
            $intent,
            $matches,
        ));
        $request = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'upgrade-rollback.request';
        $contents = "WLS-UPGRADE-ROLLBACK/3\n"
            . 'intent_sha256=' . \hash('sha256', $intent) . "\n"
            . 'intent_nonce=' . (string)$matches[1] . "\n"
            . 'from=' . $candidate['slot'] . "\n"
            . 'to=' . $initial['slot'] . "\n"
            . 'host_boot_id=' . $observation['host_boot_id'] . "\n"
            . 'requested_monotonic_ms='
                . $observation['prepared_monotonic_ms'] . "\n"
            . 'request_nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        self::assertNotFalse(\file_put_contents($request, $contents));
        $backup = $request . '.wls-backup-' . \str_repeat('a', 16);
        self::assertTrue(\copy($request, $backup));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($request, 0600));
            self::assertTrue(\chmod($backup, 0600));
        }

        $manager->rollbackUpgradeActivation(
            $candidate['slot'],
            $initial['slot'],
            $observation['rollback_context'],
        );

        self::assertFileDoesNotExist($backup);
        self::assertSame($contents, \file_get_contents($request));
        self::assertSame($initial['slot'], $this->paths->activeSlot());
    }

    public function testUpgradeRollbackRetainsBackupWhenPairedRequestIsMissingOrCorrupt(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('rollback-evidence-initial'), 'default');
        $manager->activate($initial['slot']);
        $candidate = $manager->stage($this->createPackage('rollback-evidence-candidate'), 'default');
        $observation = $manager->beginUpgradeActivation($candidate);
        $intent = (string)\file_get_contents($this->paths->upgradeIntentFile());
        self::assertSame(1, \preg_match(
            '/nonce=([a-f0-9]{32})/D',
            $intent,
            $matches,
        ));
        $request = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'upgrade-rollback.request';
        $valid = "WLS-UPGRADE-ROLLBACK/3\n"
            . 'intent_sha256=' . \hash('sha256', $intent) . "\n"
            . 'intent_nonce=' . (string)$matches[1] . "\n"
            . 'from=' . $candidate['slot'] . "\n"
            . 'to=' . $initial['slot'] . "\n"
            . 'host_boot_id=' . $observation['host_boot_id'] . "\n"
            . 'requested_monotonic_ms='
                . $observation['prepared_monotonic_ms'] . "\n"
            . 'request_nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $backup = $request . '.wls-backup-' . \str_repeat('b', 16);
        self::assertNotFalse(\file_put_contents($backup, $valid));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }

        try {
            $manager->rollbackUpgradeActivation(
                $candidate['slot'],
                $initial['slot'],
                $observation['rollback_context'],
            );
            self::fail('A missing paired rollback request must retain recovery evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('paired target is missing', $exception->getMessage());
        }
        self::assertFileExists($backup);
        self::assertFileExists($this->paths->upgradeIntentFile());
        self::assertSame($candidate['slot'], $this->paths->activeSlot());

        self::assertNotFalse(\file_put_contents($request, "corrupt\n"));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($request, 0600));
        }
        try {
            $manager->rollbackUpgradeActivation(
                $candidate['slot'],
                $initial['slot'],
                $observation['rollback_context'],
            );
            self::fail('A corrupt paired rollback request must retain recovery evidence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('malformed or bound', $exception->getMessage());
        }
        self::assertSame("corrupt\n", \file_get_contents($request));
        self::assertSame($valid, \file_get_contents($backup));
        self::assertFileExists($this->paths->upgradeIntentFile());
        self::assertSame($candidate['slot'], $this->paths->activeSlot());
    }

    #[DataProvider('wallClockJumpProvider')]
    public function testAutomaticUpgradeRollbackUsesSameBootMonotonicWindow(
        int $wallAfterJump,
    ): void {
        $wall = 1_700_000_000;
        $monotonic = 2_000_000;
        $bootId = \str_repeat('a', 64);
        $manager = new HostGatewayPackageManager(
            paths: $this->paths,
            wallClock: static function () use (&$wall): int {
                return $wall;
            },
            monotonicClockMilliseconds: static function () use (&$monotonic): int {
                return $monotonic;
            },
            bootIdentity: static function () use (&$bootId): string {
                return $bootId;
            },
        );
        $initial = $manager->stage(
            $this->createPackage('monotonic-initial-' . $wallAfterJump),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('monotonic-candidate-' . $wallAfterJump),
            'default',
        );
        $observation = $manager->beginUpgradeActivation($candidate);
        $context = $observation['rollback_context'];
        self::assertSame(
            900_000,
            $context['rollback_deadline_monotonic_ms']
                - $context['prepared_monotonic_ms'],
        );

        $wall = $wallAfterJump;
        $monotonic += 60_000;
        $manager->rollbackUpgradeActivation(
            $candidate['slot'],
            $initial['slot'],
            $context,
        );

        self::assertSame($initial['slot'], $this->paths->activeSlot());
        $request = (string)\file_get_contents(
            $this->paths->stateDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rollback.request',
        );
        self::assertStringStartsWith("WLS-UPGRADE-ROLLBACK/3\n", $request);
        self::assertStringContainsString('host_boot_id=' . $bootId . "\n", $request);
        self::assertStringContainsString(
            'requested_monotonic_ms=' . $monotonic . "\n",
            $request,
        );
        self::assertStringNotContainsString('at=' . $wallAfterJump . "\n", $request);
    }

    /** @return iterable<string,array{int}> */
    public static function wallClockJumpProvider(): iterable
    {
        yield 'wall clock jumps backward' => [1];
        yield 'wall clock jumps forward' => [4_100_000_000];
    }

    public function testAutomaticUpgradeRollbackRejectsAnotherBootAndRetainsRecoveryIntent(): void
    {
        $wall = 1_700_000_000;
        $monotonic = 3_000_000;
        $bootId = \str_repeat('b', 64);
        $manager = new HostGatewayPackageManager(
            paths: $this->paths,
            wallClock: static function () use (&$wall): int {
                return $wall;
            },
            monotonicClockMilliseconds: static function () use (&$monotonic): int {
                return $monotonic;
            },
            bootIdentity: static function () use (&$bootId): string {
                return $bootId;
            },
        );
        $initial = $manager->stage(
            $this->createPackage('cross-boot-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('cross-boot-candidate'),
            'default',
        );
        $observation = $manager->beginUpgradeActivation($candidate);
        $bootId = \str_repeat('c', 64);
        $monotonic = 100_000;

        try {
            $manager->rollbackUpgradeActivation(
                $candidate['slot'],
                $initial['slot'],
                $observation['rollback_context'],
            );
            self::fail('A prior-boot PHP rollback context must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'another host boot',
                $exception->getMessage(),
            );
        }

        self::assertSame($candidate['slot'], $this->paths->activeSlot());
        self::assertFileExists($this->paths->upgradeIntentFile());
        self::assertFileDoesNotExist(
            $this->paths->stateDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rollback.request',
        );

        $bootId = \str_repeat('b', 64);
        $monotonic = (int)$observation['rollback_context'][
            'rollback_deadline_monotonic_ms'
        ] + 1;
        try {
            $manager->rollbackUpgradeActivation(
                $candidate['slot'],
                $initial['slot'],
                $observation['rollback_context'],
            );
            self::fail('An expired monotonic rollback context must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'outside its same-boot monotonic',
                $exception->getMessage(),
            );
        }
        self::assertSame($candidate['slot'], $this->paths->activeSlot());
        self::assertFileExists($this->paths->upgradeIntentFile());
    }

    #[DataProvider('terminalUpgradeStateV3Provider')]
    public function testTerminalV3UpgradeStateCanBeCollectedAfterIntentConsumption(
        string $phase,
        int $observationStarted,
        int $observationDeadline,
    ): void {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('terminal-v3-' . \strtolower($phase)),
            'default',
        );
        $manager->activate($initial['slot']);
        $state = $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'upgrade-state';
        self::assertFileDoesNotExist($this->paths->upgradeIntentFile());
        self::assertNotFalse(\file_put_contents(
            $state,
            "WLS-UPGRADE-STATE/3\n"
                . 'intent_sha256=' . \str_repeat('a', 64) . "\n"
                . 'intent_nonce=' . \str_repeat('b', 32) . "\n"
                . "from=A\nto=B\n"
                . 'runtime_generation=' . \str_repeat('c', 64) . "\n"
                . 'boot_id=' . \str_repeat('d', 64) . "\n"
                . 'phase=' . $phase . "\n"
                . "attempts=1\n"
                . "prepared_monotonic_ms=500\n"
                . 'observation_started_monotonic_ms='
                    . $observationStarted . "\n"
                . 'observation_deadline_monotonic_ms='
                    . $observationDeadline . "\n"
                . "total_deadline_monotonic_ms=900500\n",
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($state, 0600));
        }

        (new \ReflectionMethod(
            $manager,
            'removeTerminalOrphanUpgradeState',
        ))->invoke($manager);

        self::assertFileDoesNotExist($state);
    }

    /** @return iterable<string,array{string,int,int}> */
    public static function terminalUpgradeStateV3Provider(): iterable
    {
        yield 'committed candidate' => ['COMMITTED', 1_000, 301_000];
        yield 'healthy old-slot rollback' => ['ROLLED_BACK', 0, 0];
    }

    public function testMalformedTerminalV3UpgradeStateStillBlocksCollection(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('terminal-v3-malformed'),
            'default',
        );
        $manager->activate($initial['slot']);
        $state = $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'upgrade-state';
        self::assertNotFalse(\file_put_contents(
            $state,
            "WLS-UPGRADE-STATE/3\n"
                . 'intent_sha256=' . \str_repeat('a', 64) . "\n"
                . 'intent_nonce=' . \str_repeat('b', 32) . "\n"
                . "from=A\nto=B\n"
                . 'runtime_generation=' . \str_repeat('c', 64) . "\n"
                . 'boot_id=' . \str_repeat('d', 64) . "\n"
                . "phase=COMMITTED\nattempts=1\n"
                . "prepared_monotonic_ms=500\n"
                . "observation_started_monotonic_ms=0\n"
                . "observation_deadline_monotonic_ms=0\n"
                . "total_deadline_monotonic_ms=900500\n",
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($state, 0600));
        }

        try {
            (new \ReflectionMethod(
                $manager,
                'removeTerminalOrphanUpgradeState',
            ))->invoke($manager);
            self::fail('Malformed terminal V3 state must remain a recovery fence.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'non-terminal or ambiguously bound',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($state);
    }

    public function testHostStateRecoveryBackupsRequireWholeClosureValidation(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('host-recovery-initial'), 'default');
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('host-recovery-candidate'),
            'default',
        );
        $manager->beginUpgradeActivation($candidate);
        $stateTargets = [
            $this->paths->activeSlotFile(),
            $this->paths->previousSlotFile(),
            $this->paths->upgradeIntentFile(),
            $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'stable-launcher.sha256',
            $this->paths->hostIdFile(),
            $this->paths->adminTokenFile(),
        ];
        $backups = [];
        foreach ($stateTargets as $index => $target) {
            self::assertFileExists($target);
            $backup = $target . '.wls-backup-'
                . \str_pad(\dechex($index + 1), 16, '0', STR_PAD_LEFT);
            self::assertTrue(\copy($target, $backup));
            if (\PHP_OS_FAMILY !== 'Windows') {
                self::assertTrue(\chmod(
                    $backup,
                    \fileperms($target) & 0777,
                ));
            }
            $backups[] = $backup;
        }
        $installLock = new \ReflectionMethod($manager, 'withInstallLock');

        $installLock->invoke($manager, static fn (): null => null);

        foreach ($backups as $backup) {
            self::assertFileDoesNotExist($backup);
        }

        $activeBackup = $this->paths->activeSlotFile()
            . '.wls-backup-' . \str_repeat('a', 16);
        $previousBackup = $this->paths->previousSlotFile()
            . '.wls-backup-' . \str_repeat('b', 16);
        self::assertTrue(\copy($this->paths->activeSlotFile(), $activeBackup));
        self::assertTrue(\copy($this->paths->previousSlotFile(), $previousBackup));
        self::assertNotFalse(\file_put_contents($previousBackup, "X\n"));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($activeBackup, 0640));
            self::assertTrue(\chmod($previousBackup, 0640));
        }

        try {
            $installLock->invoke($manager, static fn (): null => null);
            self::fail('A malformed later backup must fail the whole host-state closure.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'previous-slot recovery backup is invalid',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($activeBackup);
        self::assertFileExists($previousBackup);

        self::assertTrue(\copy(
            $this->paths->previousSlotFile(),
            $previousBackup,
        ));
        self::assertNotFalse(\file_put_contents(
            $this->paths->previousSlotFile(),
            "X\n",
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($previousBackup, 0640));
            self::assertTrue(\chmod($this->paths->previousSlotFile(), 0640));
        }
        try {
            $installLock->invoke($manager, static fn (): null => null);
            self::fail('A malformed later current target must preserve every earlier backup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'previous-slot recovery target is invalid',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($activeBackup);
        self::assertFileExists($previousBackup);
    }

    public function testHostRecoveryPreservesArtifactsWhenRetainedIntentLosesLauncherProof(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage(
            $this->createPackage('host-recovery-launcher-proof-initial'),
            'default',
        );
        $manager->activate($initial['slot']);
        $candidate = $manager->stage(
            $this->createPackage('host-recovery-launcher-proof-candidate'),
            'default',
        );
        $manager->beginUpgradeActivation($candidate);
        $intentFile = $this->paths->upgradeIntentFile();
        $intent = (string)\file_get_contents($intentFile);
        $activeBackup = $this->paths->activeSlotFile()
            . '.wls-backup-' . \str_repeat('c', 16);
        self::assertTrue(\copy($this->paths->activeSlotFile(), $activeBackup));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($activeBackup, 0640));
        }
        $this->rewriteInstalledManifest(
            $initial['slot'],
            static function (array $manifest): array {
                $manifest['capabilities'][
                    'stable_launcher_rollback_target_proof'
                ] = false;
                return $manifest;
            },
        );
        $installLock = new \ReflectionMethod($manager, 'withInstallLock');

        try {
            $installLock->invoke($manager, static fn (): null => null);
            self::fail('Recovery cleanup must not collect evidence under an unproved launcher.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap',
                $exception->getMessage(),
            );
        }

        self::assertFileExists($activeBackup);
        self::assertSame($intent, \file_get_contents($intentFile));
        self::assertDirectoryExists($initial['slot_dir']);
        self::assertDirectoryExists($candidate['slot_dir']);
    }

    public function testHostRecoveryInventoryCoversEveryReusableAtomicTarget(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $inventory = new \ReflectionMethod($manager, 'hostAtomicRecoveryTargets');
        $targets = $inventory->invoke($manager);

        self::assertIsArray($targets);
        self::assertSame([
            'active-slot',
            'previous-slot',
            'host-id',
            'admin-token',
            'stable-launcher-identity',
            'guardian-identity',
            'ca-bundle-baseline',
            'upgrade-intent',
            'upgrade-rollback-request',
            'slot-retention',
            'failed-initial-cleanup',
            'rebootstrap-journal',
            'rebootstrap-start-authorization',
        ], \array_keys($targets));
        self::assertSame(
            $this->paths->stateDir() . DIRECTORY_SEPARATOR
                . 'upgrade-rollback.request',
            $targets['upgrade-rollback-request']['path'],
        );
        self::assertSame(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'slot-retention',
            $targets['slot-retention']['path'],
        );
        self::assertSame(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'failed-initial-cleanup.intent',
            $targets['failed-initial-cleanup']['path'],
        );
        self::assertSame(
            $this->paths->rebootstrapJournalFile(),
            $targets['rebootstrap-journal']['path'],
        );
        self::assertSame(
            $this->paths->rebootstrapStartAuthorizationFile(),
            $targets['rebootstrap-start-authorization']['path'],
        );
        self::assertSame(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'stable-launcher.sha256',
            $targets['stable-launcher-identity']['path'],
        );
        self::assertSame(
            $this->paths->guardianDigestFile(),
            $targets['guardian-identity']['path'],
        );
    }

    public function testRebootstrapHostRecoveryBindingsCanOnlyAdvance(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'host-recovery-one-way',
        );
        $journal = \json_decode(
            (string)\file_get_contents($this->paths->rebootstrapJournalFile()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $compatibility = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'assertRebootstrapRecoveryDocumentsCompatible',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $retained = [...$journal, 'retained_backup_state' => 'RETAINED'];
        $collected = [...$retained, 'retained_backup_state' => 'COLLECTED'];

        $compatibility->invoke($packages, $journal, $retained, 'test backup');
        $compatibility->invoke($packages, $retained, $collected, 'test backup');

        try {
            $compatibility->invoke(
                $packages,
                $collected,
                $retained,
                'test backup',
            );
            self::fail('Recovery must not reverse COLLECTED to RETAINED.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'reverses the gateway rebootstrap retained-backup state',
                $exception->getMessage(),
            );
        }

        $boundEpoch = [
            ...$journal,
            'new_gateway_epoch' => $fixture['new_gateway_epoch'],
        ];
        $compatibility->invoke(
            $packages,
            $journal,
            $boundEpoch,
            'test backup',
        );
        try {
            $compatibility->invoke(
                $packages,
                $boundEpoch,
                $journal,
                'test backup',
            );
            self::fail('Recovery must not erase an observed gateway epoch.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'reverses or changes gateway rebootstrap binding new_gateway_epoch',
                $exception->getMessage(),
            );
        }

        $policyBound = [
            ...$journal,
            'derived_policy_sha256' => \str_repeat('f', 64),
        ];
        $compatibility->invoke(
            $packages,
            $journal,
            $policyBound,
            'test backup',
        );
        try {
            $compatibility->invoke(
                $packages,
                $policyBound,
                $journal,
                'test backup',
            );
            self::fail('Recovery must not erase a bound derived-state policy.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'reverses or changes gateway rebootstrap binding derived_policy_sha256',
                $exception->getMessage(),
            );
        }

        $collectionBound = [
            ...$journal,
            'backup_collection_nonce' => \str_repeat('a', 32),
            'backup_collection_device' => '1a',
            'backup_collection_inode' => '2b',
        ];
        $compatibility->invoke(
            $packages,
            $journal,
            $collectionBound,
            'test backup',
        );
        try {
            $compatibility->invoke(
                $packages,
                $collectionBound,
                $journal,
                'test backup',
            );
            self::fail('Recovery must not erase a bound collection root.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'reverses or changes gateway rebootstrap binding backup_collection_nonce',
                $exception->getMessage(),
            );
        }

        try {
            $packages->advanceRebootstrapPhase(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'PREPARED',
                'PREPARED',
                ['new_gateway_epoch' => $fixture['new_gateway_epoch']],
            );
            self::fail('Epoch evidence must be limited to the observation transition.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString(
                'is not allowed for PREPARED->PREPARED',
                $exception->getMessage(),
            );
        }
    }

    public function testFirstLauncherIdentityPublicationPreservesUnpairedRecoveryEvidence(): void
    {
        $this->paths->ensureDirectories();
        $identity = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        $backup = $identity . '.wls-backup-' . \str_repeat('c', 16);
        self::assertNotFalse(\file_put_contents(
            $backup,
            \str_repeat('d', 64) . "\n",
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($backup, 0600));
        }
        $manager = new HostGatewayPackageManager($this->paths);
        $installLock = new \ReflectionMethod($manager, 'withInstallLock');

        try {
            $installLock->invoke($manager, static fn (): null => null);
            self::fail('An unpaired first-publication backup must block destructive cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'paired target is missing',
                $exception->getMessage(),
            );
        }
        self::assertFileExists($backup);
        self::assertFileDoesNotExist($identity);
    }

    public function testPlatformServiceDefinitionUsesStableLauncherAndSystemScopeContract(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installed = $installer->installDefinition('ipv4-only');

        self::assertSame('test-session', $installed['kind']);
        self::assertTrue($installed['test_mode']);
        $definition = (string)\file_get_contents($installed['path']);
        self::assertStringContainsString($this->paths->guardianFile(), $definition);
        self::assertStringContainsString('ipv4-only', $definition);
        self::assertStringNotContainsString('LaunchAgents', $definition);
        self::assertStringNotContainsString('systemd/user', $definition);
    }

    public function testPlatformServiceTemplatesRaiseGatewayOpenFileLimit(): void
    {
        $templateDirectory = \dirname(__DIR__, 5)
            . DIRECTORY_SEPARATOR . 'env' . DIRECTORY_SEPARATOR . 'gateway';
        $systemd = (string)\file_get_contents(
            $templateDirectory . DIRECTORY_SEPARATOR . 'systemd.service.template'
        );
        $launchd = (string)\file_get_contents(
            $templateDirectory . DIRECTORY_SEPARATOR . 'launchd.plist.template'
        );

        self::assertStringContainsString('LimitNOFILE=65536', $systemd);
        self::assertSame(2, \substr_count($launchd, '<key>NumberOfFiles</key>'));
        self::assertSame(2, \substr_count($launchd, '<integer>65536</integer>'));
    }

    public function testProjectEndpointAccessPreparationKeepsTestEnrollmentLocal(): void
    {
        $project = $this->root . DIRECTORY_SEPARATOR . 'project';
        self::assertTrue(\mkdir($project, 0700, true));
        $status = @\lstat($project);
        self::assertIsArray($status);

        $result = (new GatewayPlatformServiceInstaller($this->paths))
            ->authorizeProjectRuntimeRead(
                $project,
                \PHP_OS_FAMILY === 'Windows' ? null : (int)$status['uid'],
                \PHP_OS_FAMILY === 'Windows' ? null : (int)$status['gid'],
            );

        self::assertFalse($result['applied']);
        self::assertTrue($result['test_mode']);
        self::assertSame('test-session', $result['service_identity']);
        $projectRoot = \realpath($project);
        self::assertIsString($projectRoot);
        $identities = $projectRoot . DIRECTORY_SEPARATOR . 'var'
            . DIRECTORY_SEPARATOR . 'server'
            . DIRECTORY_SEPARATOR . 'gateway-identities';
        self::assertSame($identities, $result['identities_dir']);
        self::assertSame($identities, $result['instances_dir']);
        self::assertDirectoryExists($identities);
        self::assertDirectoryDoesNotExist(
            $projectRoot . DIRECTORY_SEPARATOR . 'var'
                . DIRECTORY_SEPARATOR . 'server'
                . DIRECTORY_SEPARATOR . 'instances',
        );
    }

    public function testProjectEndpointAccessContractFailsClosedUntilNativeHelperExists(): void
    {
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/GatewayPlatformServiceInstaller.php'
        );
        self::assertStringContainsString(
            'requires the native handle-relative ACL helper',
            $source,
        );
        self::assertStringNotContainsString(
            'project endpoint ACL authorization',
            $source,
        );
        self::assertStringNotContainsString(
            'Windows project ACL target verification',
            $source,
        );
        self::assertStringNotContainsString(
            'project endpoint ACL revocation',
            $source,
        );
        $enroll = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Console/Server/Gateway/Enroll.php'
        );
        self::assertStringNotContainsString(
            '->authorizeProjectRuntimeRead(',
            $enroll,
        );
        self::assertStringNotContainsString(
            "\$payload['endpoint_access_prepared']",
            $enroll,
        );
        self::assertStringContainsString("'broker_auth_snap'", $enroll);
        self::assertStringContainsString('validateCredentialReceipt(', $enroll);
    }

    public function testExplicitHostInstallKeepsTestPackageNonReadyAndHighPortOnly(): void
    {
        $package = $this->createPackage('host-install');
        $packages = new HostGatewayPackageManager($this->paths);
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $host = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );

        $result = $host->install($package, 'default');

        self::assertTrue($result['ok']);
        self::assertFalse($result['ready']);
        self::assertTrue($result['test_mode']);
        self::assertFalse($result['release_ready']);
        self::assertSame('TEST_PACKAGE_INSTALLED', $result['state']);
        self::assertFileExists($this->paths->serviceDefinitionFile());
        self::assertSame(22080, $this->paths->publicHttpPort());
        self::assertSame(22443, $this->paths->publicHttpsPort());
    }

    public function testLatePortRaceRollsBackOnlyTheNewGatewayActivation(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $package = $this->createPackage('late-port-race-install');
        $this->paths->ensureDirectories();
        $bootstrapJournal = new GatewayInitialBootstrapJournal($this->paths);
        $journal = $bootstrapJournal->beginOrResume(
            $packages->verifyPackage($package, 'default'),
            'default',
        );
        $staged = $packages->stage(
            $package,
            'default',
        );
        $journal = $bootstrapJournal->advance($journal, 'STAGED', [
            'slot' => (string)$staged['slot'],
            'runtime_generation' => (string)$staged['runtime_generation'],
            'previous_active_slot' => (string)$staged['previous_active_slot'],
        ]);
        $service = $platform->installDefinition('default');
        $journal = $bootstrapJournal->advance(
            $journal,
            'DEFINITION_INSTALLED',
            ['service_kind' => (string)$service['kind']],
        );
        $packages->activate((string)$staged['slot']);
        $journal = $bootstrapJournal->advance($journal, 'ACTIVATED');
        $journal = $bootstrapJournal->advance($journal, 'ROLLING_BACK');
        $host = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );
        $rollback = new \ReflectionMethod(
            GatewayHostManager::class,
            'rollbackInitialActivationAfterReadinessFailure',
        );

        $result = $rollback->invoke(
            $host,
            $service,
            $staged,
            'PORT_TAKEN',
            ['reason' => 'untrusted PORT_TAKEN diagnostic'],
            (\hrtime(true) / 1_000_000_000) + 30.0,
            $journal,
        );

        self::assertFalse($result['ok']);
        self::assertFalse($result['ready']);
        self::assertSame('PORT_TAKEN', $result['state']);
        self::assertSame('unknown', $result['owner']);
        self::assertStringStartsWith('PORT_TAKEN:', $result['reason']);
        self::assertStringNotContainsString(
            'untrusted PORT_TAKEN diagnostic',
            $result['reason'],
        );
        self::assertFileDoesNotExist($this->paths->activeSlotFile());
        self::assertFileDoesNotExist($this->paths->serviceDefinitionFile());
        self::assertFileDoesNotExist($this->paths->platformServiceMetadataFile());
        self::assertDirectoryDoesNotExist(
            $this->paths->slotDir((string)$staged['slot']),
        );
    }

    public function testInitialInstallReconcilesActiveSlotCommittedBeforeDirectorySyncFailure(): void
    {
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE='
                . 'directory_fsync_after_rename_failed',
        );
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256='
                . \hash('sha256', $this->paths->activeSlotFile()),
        );
        $package = $this->createPackage('initial-active-post-rename');
        $packages = new HostGatewayPackageManager($this->paths);
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $host = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );

        $result = $host->install($package, 'default');

        self::assertTrue($result['ok']);
        self::assertSame('TEST_PACKAGE_INSTALLED', $result['state']);
        self::assertSame($result['slot'], $this->paths->activeSlot());
        self::assertFileExists($this->paths->serviceDefinitionFile());
        self::assertDirectoryExists($this->paths->slotDir((string)$result['slot']));
        self::assertFileDoesNotExist(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR
                . 'failed-initial-cleanup.intent',
        );
    }

    public function testInitialActivationDoesNotAcceptACommittedPointerWithChangedManifest(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $staged = $manager->stage(
            $this->createPackage('initial-active-wrong-after-image'),
            'default',
        );
        $slotProof = (new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'verifiedStableLauncherSlotProof',
        ))->invoke(
            $manager,
            $staged['slot'],
            'test first-activation fence',
        );
        (new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'ensureTrustBundleBaselineLocked',
        ))->invoke($manager, $slotProof['ca_bundle_sha256']);
        $fence = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'firstActivationSlotFence',
        );
        $expected = $fence->invoke($manager, $staged['slot']);
        self::assertIsArray($expected);
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE='
                . 'directory_fsync_after_rename_failed',
        );
        \putenv(
            'WLS_GATEWAY_TEST_PROJECT_STATE_FAILURE_TARGET_SHA256='
                . \hash('sha256', $this->paths->activeSlotFile()),
        );
        $atomicWrite = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'atomicWrite',
        );
        try {
            $atomicWrite->invoke(
                $manager,
                $this->paths->activeSlotFile(),
                $staged['slot'] . PHP_EOL,
                0640,
            );
            self::fail('The injected post-rename directory sync failure was not raised.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'after publication',
                $exception->getMessage(),
            );
        }
        self::assertSame($staged['slot'], $this->paths->activeSlot());

        $manifest = $staged['slot_dir'] . DIRECTORY_SEPARATOR . 'manifest.json';
        self::assertTrue(\chmod($manifest, 0600));
        self::assertNotFalse(\file_put_contents(
            $manifest,
            (string)\file_get_contents($manifest) . "\n",
        ));
        $matches = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'firstActivationAfterImageMatches',
        );
        self::assertFalse($matches->invoke(
            $manager,
            $staged['slot'],
            $expected,
        ));
    }

    public function testLegacyPromotionStagesBeforeActivationAndAbortRestoresEmptyHostState(): void
    {
        $host = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            new HostGatewayPackageManager($this->paths),
            new GatewayPlatformServiceInstaller($this->paths),
        );

        $staged = $host->stageLegacyPromotion(
            $this->createPackage('legacy-promotion'),
            'default',
        );

        self::assertTrue($staged['promotion_staged']);
        self::assertContains($staged['slot'], ['A', 'B']);
        self::assertFileDoesNotExist($this->paths->activeSlotFile());
        self::assertFileExists($this->paths->serviceDefinitionFile());
        self::assertFileExists($this->paths->platformServiceMetadataFile());

        $activated = $host->activateLegacyPromotion($staged);
        self::assertSame('TEST_PROMOTION_ACTIVATED', $activated['state']);
        self::assertSame($staged['slot'], $this->paths->activeSlot());

        $host->abortLegacyPromotion($staged, true);
        self::assertFileDoesNotExist($this->paths->activeSlotFile());
        self::assertFileDoesNotExist($this->paths->serviceDefinitionFile());
        self::assertFileDoesNotExist($this->paths->platformServiceMetadataFile());
        self::assertDirectoryDoesNotExist($this->paths->slotDir((string)$staged['slot']));
    }

    public function testLatePortRaceQuiescesPromotionButRetainsJournalRecoveryMaterial(): void
    {
        $host = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            new HostGatewayPackageManager($this->paths),
            new GatewayPlatformServiceInstaller($this->paths),
        );
        $staged = $host->stageLegacyPromotion(
            $this->createPackage('late-port-race-promotion'),
            'default',
        );
        $host->activateLegacyPromotion($staged);
        $failure = new \ReflectionMethod(
            GatewayHostManager::class,
            'failLegacyPromotionForTrustedPortTaken',
        );

        $invalid = $staged;
        $invalid['service']['kind'] = 'invalid-service-kind';
        try {
            $failure->invoke(
                $host,
                $invalid,
                (\hrtime(true) / 1_000_000_000) + 30.0,
            );
            self::fail('A failed quiesce must preserve a stable PORT_TAKEN failure.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('PORT_TAKEN:', $exception->getMessage());
            self::assertStringContainsString(
                'remain for promotion-journal recovery',
                $exception->getMessage(),
            );
        }
        self::assertSame($staged['slot'], $this->paths->activeSlot());
        self::assertFileExists($this->paths->serviceDefinitionFile());
        self::assertFileExists($this->paths->platformServiceMetadataFile());
        self::assertDirectoryExists(
            $this->paths->slotDir((string)$staged['slot']),
        );

        try {
            $failure->invoke(
                $host,
                $staged,
                (\hrtime(true) / 1_000_000_000) + 30.0,
            );
            self::fail('A trusted late public-port race must fail promotion.');
        } catch (\RuntimeException $exception) {
            self::assertStringStartsWith('PORT_TAKEN:', $exception->getMessage());
            self::assertStringContainsString(
                'did not stop or modify it',
                $exception->getMessage(),
            );
            self::assertStringContainsString(
                'restore and probe the legacy owner',
                $exception->getMessage(),
            );
        }

        self::assertSame($staged['slot'], $this->paths->activeSlot());
        self::assertFileExists($this->paths->serviceDefinitionFile());
        self::assertFileExists($this->paths->platformServiceMetadataFile());
        self::assertDirectoryExists(
            $this->paths->slotDir((string)$staged['slot']),
        );

        // Model the existing promotion journal's final step after the legacy
        // owner has been restored and publicly probed.
        $host->abortLegacyPromotion($staged, true);
        self::assertFileDoesNotExist($this->paths->activeSlotFile());
        self::assertFileDoesNotExist($this->paths->serviceDefinitionFile());
        self::assertFileDoesNotExist($this->paths->platformServiceMetadataFile());
        self::assertDirectoryDoesNotExist(
            $this->paths->slotDir((string)$staged['slot']),
        );
    }

    public function testLegacyPromotionReadinessBudgetCoversColdPublicationWindows(): void
    {
        $budget = new \ReflectionMethod(
            GatewayHostManager::class,
            'legacyPromotionReadinessTimeoutSeconds',
        );

        self::assertGreaterThanOrEqual(
            45.0,
            $budget->invoke(null),
            'Production cold start performs shadow and public stability windows before ready.',
        );
    }

    public function testPromotionInstanceLookupUsesProjectScopedControllerBuckets(): void
    {
        $projectUuid = '123e4567-e89b-42d3-a456-426614174099';
        $instanceName = 'shared-instance-name';
        $expected = [
            'instance_id' => $instanceName,
            'generation' => 17,
            'status' => 'ACTIVE',
        ];
        $lookup = new \ReflectionMethod(
            GatewayHostManager::class,
            'promotionInstanceLease',
        );
        $lookup->setAccessible(true);

        self::assertSame(
            $expected,
            $lookup->invoke(null, [
                $projectUuid => [$instanceName => $expected],
                '223e4567-e89b-42d3-a456-426614174099' => [
                    $instanceName => [...$expected, 'generation' => 99],
                ],
            ], $projectUuid, $instanceName),
        );
        self::assertNull($lookup->invoke(
            null,
            [$projectUuid => [$instanceName => $expected]],
            '323e4567-e89b-42d3-a456-426614174099',
            $instanceName,
        ));
        self::assertSame(
            $expected + ['project_uuid' => $projectUuid],
            $lookup->invoke(null, [
                $expected + ['project_uuid' => $projectUuid],
                [...$expected,
                    'project_uuid' => '423e4567-e89b-42d3-a456-426614174099',
                    'generation' => 99,
                ],
            ], $projectUuid, $instanceName),
        );
    }

    public function testPromotionActivationReservesASeparateStabilityWindow(): void
    {
        $timedOut = new \ReflectionMethod(
            GatewayHostManager::class,
            'promotionActivationTimedOut',
        );
        $timedOut->setAccessible(true);

        self::assertFalse($timedOut->invoke(null, 119.999, 120.0, 12.0));
        self::assertFalse($timedOut->invoke(null, 120.0, 120.0, 12.0));
        self::assertFalse($timedOut->invoke(null, 131.999, 120.0, 12.0));
        self::assertTrue($timedOut->invoke(null, 132.0, 120.0, 12.0));
    }

    public function testPromotionProjectIdentityScopePreservesTheVerifiedOwner(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || !\function_exists('posix_getegid')
        ) {
            self::markTestSkipped('POSIX effective identity is required.');
        }
        $projectRoot = $this->root . DIRECTORY_SEPARATOR . 'project-owner';
        self::assertTrue(\mkdir($projectRoot, 0700, true));
        $owner = \lstat($projectRoot);
        self::assertIsArray($owner);
        if (!\function_exists('posix_getpwuid')) {
            self::markTestSkipped('POSIX account lookup is required.');
        }
        $account = \posix_getpwuid((int)$owner['uid']);
        $ownerHome = \is_array($account)
            ? \realpath((string)($account['dir'] ?? ''))
            : false;
        if (!\is_string($ownerHome) || $ownerHome === '') {
            self::markTestSkipped('The project owner home is unavailable.');
        }
        $beforeUid = \posix_geteuid();
        $beforeGid = \posix_getegid();
        $beforeEnvironment = [];
        foreach (['HOME', 'XDG_STATE_HOME', 'WLS_EDGE_STATE_HOME'] as $name) {
            $beforeEnvironment[$name] = \getenv($name);
        }
        \putenv('HOME=/tmp/wls-promotion-root-home');
        \putenv('XDG_STATE_HOME=/tmp/wls-promotion-root-state');
        \putenv('WLS_EDGE_STATE_HOME=/tmp/wls-promotion-root-edge-state');
        $host = new GatewayHostManager($this->paths);
        $scope = new \ReflectionMethod(
            GatewayHostManager::class,
            'withPromotionProjectOwnerIdentity',
        );

        try {
            $observed = $scope->invoke(
                $host,
                $projectRoot,
                static fn (): array => [
                    'uid' => \posix_geteuid(),
                    'gid' => \posix_getegid(),
                    'home' => \getenv('HOME'),
                    'xdg_state_home' => \getenv('XDG_STATE_HOME'),
                    'edge_state_home' => \getenv('WLS_EDGE_STATE_HOME'),
                ],
            );

            self::assertSame((int)$owner['uid'], $observed['uid']);
            self::assertSame((int)$owner['gid'], $observed['gid']);
            self::assertSame($ownerHome, $observed['home']);
            self::assertFalse($observed['xdg_state_home']);
            self::assertFalse($observed['edge_state_home']);
            self::assertSame('/tmp/wls-promotion-root-home', \getenv('HOME'));
            self::assertSame('/tmp/wls-promotion-root-state', \getenv('XDG_STATE_HOME'));
            self::assertSame(
                '/tmp/wls-promotion-root-edge-state',
                \getenv('WLS_EDGE_STATE_HOME'),
            );
            self::assertSame($beforeUid, \posix_geteuid());
            self::assertSame($beforeGid, \posix_getegid());
        } finally {
            foreach ($beforeEnvironment as $name => $value) {
                \putenv($value === false ? $name : $name . '=' . $value);
            }
        }
    }

    public function testLegacyPromotionAbortDetectsSlotActivatedBeforeReadinessFailure(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $host = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            new GatewayPlatformServiceInstaller($this->paths),
        );
        $staged = $host->stageLegacyPromotion(
            $this->createPackage('legacy-post-activation-failure'),
            'default',
        );
        $packages->activate((string)$staged['slot']);

        $host->abortLegacyPromotion($staged, false);

        self::assertFileDoesNotExist($this->paths->activeSlotFile());
        self::assertFileDoesNotExist($this->paths->serviceDefinitionFile());
        self::assertDirectoryDoesNotExist($this->paths->slotDir((string)$staged['slot']));
        self::assertFileDoesNotExist($this->paths->launcherFile());
        self::assertFileDoesNotExist(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'stable-launcher.sha256',
        );
    }

    public function testVerifiedAdminStoppedIntentCanBeClearedButTamperFailsClosed(): void
    {
        $this->paths->ensureDirectories();
        $secret = \bin2hex(\random_bytes(32));
        $key = \hex2bin($secret);
        self::assertIsString($key);
        self::assertNotFalse(\file_put_contents($this->paths->adminTokenFile(), $secret));
        $payload = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'epoch=' . \bin2hex(\random_bytes(16)) . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $intent = $payload . 'signature='
            . \hash_hmac('sha256', $payload, $key) . "\n";
        \sodium_memzero($key);
        self::assertNotFalse(\file_put_contents(
            $this->paths->adminStoppedIntentFile(),
            $intent,
        ));
        $host = new GatewayHostManager($this->paths);
        $clear = new \ReflectionMethod($host, 'clearAdminStoppedIntent');
        self::assertSame($intent, $clear->invoke($host));
        self::assertFileDoesNotExist($this->paths->adminStoppedIntentFile());

        $tampered = \str_replace('nonce=', 'nonce=f', $intent);
        self::assertNotFalse(\file_put_contents(
            $this->paths->adminStoppedIntentFile(),
            $tampered,
        ));
        try {
            $clear->invoke($host);
            self::fail('A tampered stop intent must not re-enable the platform service.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('signature is invalid', $exception->getMessage());
        }
        self::assertFileExists($this->paths->adminStoppedIntentFile());
    }

    public function testFailedStartRestoreCannotOverwriteANewerAdminStopIntent(): void
    {
        $this->paths->ensureDirectories();
        $secret = \bin2hex(\random_bytes(32));
        $key = \hex2bin($secret);
        self::assertIsString($key);
        self::assertNotFalse(\file_put_contents($this->paths->adminTokenFile(), $secret));
        $signedIntent = static function (string $nonce, int $at) use ($key): string {
            $payload = "WLS-ADMIN-STOPPED/1\n"
                . 'host_id=' . \str_repeat('a', 32) . "\n"
                . 'epoch=' . \str_repeat('b', 32) . "\n"
                . 'at=' . $at . "\n"
                . 'nonce=' . $nonce . "\n";
            return $payload . 'signature='
                . \hash_hmac('sha256', $payload, $key) . "\n";
        };
        $old = $signedIntent(\str_repeat('c', 32), \time());
        $new = $signedIntent(\str_repeat('d', 32), \time() + 1);
        \sodium_memzero($key);
        self::assertNotFalse(\file_put_contents(
            $this->paths->adminStoppedIntentFile(),
            $old,
        ));

        $host = new GatewayHostManager($this->paths);
        $clear = new \ReflectionMethod($host, 'clearAdminStoppedIntent');
        $restore = new \ReflectionMethod($host, 'restoreAdminStoppedIntent');
        self::assertSame($old, $clear->invoke($host));
        self::assertFileDoesNotExist($this->paths->adminStoppedIntentFile());

        // Model a concurrent native Broker STOP after explicit start cleared
        // the old generation but before the platform start failed.
        self::assertNotFalse(\file_put_contents(
            $this->paths->adminStoppedIntentFile(),
            $new,
        ));
        $restore->invoke($host, $old);

        self::assertSame(
            $new,
            \file_get_contents($this->paths->adminStoppedIntentFile()),
            'Failure compensation must preserve the newer authoritative stop generation.',
        );
    }

    public function testMissingAdminStopTargetWithRecoveryEvidenceFailsClosed(): void
    {
        $this->paths->ensureDirectories();
        $secret = \bin2hex(\random_bytes(32));
        self::assertNotFalse(\file_put_contents($this->paths->adminTokenFile(), $secret));
        $target = $this->paths->adminStoppedIntentFile();
        $backup = $target . '.wls-backup-' . \str_repeat('e', 16);
        self::assertNotFalse(\file_put_contents($backup, "unresolved-stop-evidence\n"));

        $host = new GatewayHostManager($this->paths);
        $clear = new \ReflectionMethod($host, 'clearAdminStoppedIntent');
        try {
            $clear->invoke($host);
            self::fail('Unresolved stop recovery evidence was ignored.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('paired target', $exception->getMessage());
        }
        self::assertFileExists($backup);
        self::assertFileDoesNotExist($target);
    }

    public function testHostUpgradeWritesSignedObservationBeforeSwitchingWholeSlot(): void
    {
        $host = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            new HostGatewayPackageManager($this->paths),
            new GatewayPlatformServiceInstaller($this->paths),
        );
        $installed = $host->install($this->createPackage('initial'), 'default');
        $initialSlot = (string)$installed['slot'];
        self::assertContains($initialSlot, ['A', 'B']);
        self::assertSame($initialSlot, $this->paths->activeSlot());
        $stableLauncherDigest = \hash_file('sha256', $this->paths->launcherFile());
        self::assertIsString($stableLauncherDigest);
        self::assertSame(
            $stableLauncherDigest . PHP_EOL,
            \file_get_contents(
                $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'stable-launcher.sha256'
            ),
        );
        self::assertNotFalse(\file_put_contents(
            $this->paths->serviceDefinitionFile(),
            "stale-platform-definition\n",
        ));

        $upgraded = $host->upgrade($this->createPackage('candidate'), 'default');
        $candidateSlot = $initialSlot === 'A' ? 'B' : 'A';
        self::assertTrue($upgraded['accepted']);
        self::assertSame('TEST_UPGRADE_OBSERVING', $upgraded['state']);
        self::assertSame($candidateSlot, $upgraded['slot']);
        self::assertArrayNotHasKey(
            'rollback_context',
            $upgraded['observation'],
            'The internal automatic-rollback capability must not leave the upgrade call.',
        );
        self::assertSame($candidateSlot, $this->paths->activeSlot());
        self::assertSame(
            $stableLauncherDigest,
            \hash_file('sha256', $this->paths->launcherFile()),
            'An ordinary A/B runtime upgrade must not replace the stable host bootstrap.',
        );
        self::assertSame(
            $initialSlot . "\n",
            \file_get_contents($this->paths->previousSlotFile()),
        );
        $definition = (string)\file_get_contents($this->paths->serviceDefinitionFile());
        self::assertStringNotContainsString('stale-platform-definition', $definition);
        self::assertStringContainsString($this->paths->guardianFile(), $definition);
        self::assertSame(
            0600,
            \fileperms($this->paths->serviceDefinitionFile()) & 0777,
        );
        $intent = (string)\file_get_contents($this->paths->upgradeIntentFile());
        self::assertSame(1, \preg_match(
            '/\A(WLS-UPGRADE\\/2\\n'
                . 'host_id=[a-f0-9]{32}\\n'
                . 'from=[AB]\\n'
                . 'to=[AB]\\n'
                . 'prepared_at=[0-9]+\\n'
                . 'deadline=[0-9]+\\n'
                . 'runtime_generation=[a-f0-9]{64}\\n'
                . 'host_boot_id=[a-f0-9]{64}\\n'
                . 'prepared_monotonic_ms=[0-9]+\\n'
                . 'activation_deadline_monotonic_ms=[0-9]+\\n'
                . 'rollback_deadline_monotonic_ms=[0-9]+\\n'
                . 'nonce=[a-f0-9]{32}\\n)'
                . 'signature=([a-f0-9]{64})\\n\z/D',
            $intent,
            $matches,
        ));
        $secret = \trim((string)\file_get_contents($this->paths->adminTokenFile()));
        $key = \hex2bin($secret);
        self::assertIsString($key);
        self::assertSame(
            \hash_hmac('sha256', (string)$matches[1], $key),
            (string)$matches[2],
        );
        \sodium_memzero($key);
    }

    public function testStableLauncherTamperIsRejectedBeforeRuntimeUpgrade(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('initial-tamper'), 'default');
        $manager->activate($initial['slot']);
        self::assertNotFalse(\file_put_contents(
            $this->paths->launcherFile(),
            "#!/bin/sh\nexit 71\n",
        ));

        try {
            $manager->stage($this->createPackage('candidate-tamper'), 'default');
            self::fail('A tampered stable launcher must block an ordinary runtime upgrade.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'identity verification failed',
                $exception->getMessage(),
            );
        }
        self::assertDirectoryDoesNotExist(
            $this->paths->slotDir($initial['slot'] === 'A' ? 'B' : 'A'),
        );
    }

    public function testPackageLockRecoversStableLauncherCandidateAndHostStateTemporaries(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('recovery-initial'), 'default');
        $manager->activate($initial['slot']);

        $launcher = $this->paths->launcherFile();
        $launcherCandidate = $launcher . '.candidate.' . \str_repeat('a', 16);
        self::assertTrue(\copy($launcher, $launcherCandidate));
        self::assertTrue(\chmod($launcherCandidate, 0755));
        $hostTemporary = $this->paths->hostIdFile()
            . '.tmp-' . \str_repeat('b', 24);
        $tokenTemporary = $this->paths->adminTokenFile()
            . '.tmp-' . \str_repeat('c', 24);
        self::assertNotFalse(\file_put_contents($hostTemporary, 'partial-host'));
        self::assertNotFalse(\file_put_contents($tokenTemporary, 'partial-token'));
        self::assertTrue(\chmod($hostTemporary, 0600));
        self::assertTrue(\chmod($tokenTemporary, 0600));

        $manager->stage($this->createPackage('recovery-candidate'), 'default');

        self::assertFileDoesNotExist($launcherCandidate);
        self::assertFileDoesNotExist($hostTemporary);
        self::assertFileDoesNotExist($tokenTemporary);
        self::assertFileExists($launcher);
    }

    public function testPackageLockRestoresAMissingLauncherFromATrustedCandidate(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('launcher-recovery'), 'default');
        $manager->activate($initial['slot']);
        $launcher = $this->paths->launcherFile();
        $expectedDigest = \hash_file('sha256', $launcher);
        self::assertIsString($expectedDigest);
        $candidate = $launcher . '.candidate.' . \str_repeat('d', 16);
        self::assertTrue(\rename($launcher, $candidate));

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $targetPresentInsideLock = $recovery->invoke(
            $manager,
            static fn (): bool => \is_file($launcher),
        );

        self::assertTrue($targetPresentInsideLock);
        self::assertFileDoesNotExist($candidate);
        self::assertSame($expectedDigest, \hash_file('sha256', $launcher));
    }

    public function testPackageRecoveryPreservesEveryArtifactWhenOnePairedTargetIsMissing(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('missing-pair'), 'default');
        $manager->activate($initial['slot']);
        $hostTemporary = $this->paths->hostIdFile()
            . '.tmp-' . \str_repeat('e', 24);
        $tokenTemporary = $this->paths->adminTokenFile()
            . '.tmp-' . \str_repeat('f', 24);
        self::assertNotFalse(\file_put_contents($hostTemporary, 'partial-host'));
        self::assertNotFalse(\file_put_contents($tokenTemporary, 'partial-token'));
        self::assertTrue(\chmod($hostTemporary, 0600));
        self::assertTrue(\chmod($tokenTemporary, 0600));
        self::assertTrue(\unlink($this->paths->adminTokenFile()));

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $exception = $this->captureRuntimeException(
            static fn (): mixed => $recovery->invoke(
                $manager,
                static fn (): null => null,
            ),
        );
        self::assertStringContainsString('paired target', $exception->getMessage());

        self::assertFileExists($hostTemporary);
        self::assertFileExists($tokenTemporary);
    }

    public function testPackageRecoveryRejectsMalformedReservedLeavesBeforeAnyCleanup(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('malformed-residue'), 'default');
        $manager->activate($initial['slot']);
        $valid = $this->paths->hostIdFile()
            . '.tmp-' . \str_repeat('1', 24);
        $malformed = $this->paths->adminTokenFile()
            . '.tmp-' . \str_repeat('G', 24);
        self::assertNotFalse(\file_put_contents($valid, 'partial-host'));
        self::assertNotFalse(\file_put_contents($malformed, 'partial-token'));
        self::assertTrue(\chmod($valid, 0600));
        self::assertTrue(\chmod($malformed, 0600));

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $exception = $this->captureRuntimeException(
            static fn (): mixed => $recovery->invoke(
                $manager,
                static fn (): null => null,
            ),
        );
        self::assertStringContainsString('malformed reserved leaf', $exception->getMessage());

        self::assertFileExists($valid);
        self::assertFileExists($malformed);
    }

    public function testPackageRecoveryEnforcesPerTargetTemporaryQuotaBeforeCleanup(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('temporary-quota'), 'default');
        $manager->activate($initial['slot']);
        $paths = [];
        for ($index = 0; $index < 9; ++$index) {
            $path = $this->paths->hostIdFile() . '.tmp-'
                . \str_pad(\dechex($index + 1), 24, '0', STR_PAD_LEFT);
            self::assertNotFalse(\file_put_contents($path, 'partial-' . $index));
            self::assertTrue(\chmod($path, 0600));
            $paths[] = $path;
        }

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $exception = $this->captureRuntimeException(
            static fn (): mixed => $recovery->invoke(
                $manager,
                static fn (): null => null,
            ),
        );
        self::assertStringContainsString('temporary quota', $exception->getMessage());
        foreach ($paths as $path) {
            self::assertFileExists($path);
        }
    }

    public function testPackageRecoveryPreservesLauncherCandidatesWhenIdentityIsCorrupt(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('candidate-identity'), 'default');
        $manager->activate($initial['slot']);
        $candidate = $this->paths->launcherFile()
            . '.candidate.' . \str_repeat('2', 16);
        self::assertTrue(\copy($this->paths->launcherFile(), $candidate));
        self::assertTrue(\chmod($candidate, 0755));
        self::assertNotFalse(\file_put_contents(
            $this->paths->trustDir() . DIRECTORY_SEPARATOR . 'stable-launcher.sha256',
            "corrupt\n",
        ));

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $exception = $this->captureRuntimeException(
            static fn (): mixed => $recovery->invoke(
                $manager,
                static fn (): null => null,
            ),
        );
        self::assertStringContainsString(
            'launcher identity',
            \strtolower($exception->getMessage()),
        );
        self::assertFileExists($candidate);
    }

    public function testPackageRecoveryRejectsMalformedLauncherCandidateBeforeAnyCleanup(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('malformed-candidate'), 'default');
        $manager->activate($initial['slot']);
        $temporary = $this->paths->hostIdFile()
            . '.tmp-' . \str_repeat('3', 24);
        $malformed = $this->paths->launcherFile()
            . '.candidate.' . \str_repeat('Z', 16);
        self::assertNotFalse(\file_put_contents($temporary, 'partial-host'));
        self::assertTrue(\chmod($temporary, 0600));
        self::assertTrue(\copy($this->paths->launcherFile(), $malformed));
        self::assertTrue(\chmod($malformed, 0755));

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $exception = $this->captureRuntimeException(
            static fn (): mixed => $recovery->invoke(
                $manager,
                static fn (): null => null,
            ),
        );
        self::assertStringContainsString('malformed reserved leaf', $exception->getMessage());
        self::assertFileExists($temporary);
        self::assertFileExists($malformed);
    }

    public function testPackageRecoveryEnforcesLauncherCandidateQuotaBeforeCleanup(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('candidate-quota'), 'default');
        $manager->activate($initial['slot']);
        $candidates = [];
        for ($index = 0; $index < 9; ++$index) {
            $candidate = $this->paths->launcherFile() . '.candidate.'
                . \str_pad(\dechex($index + 1), 16, '0', STR_PAD_LEFT);
            self::assertTrue(\copy($this->paths->launcherFile(), $candidate));
            self::assertTrue(\chmod($candidate, 0755));
            $candidates[] = $candidate;
        }

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $exception = $this->captureRuntimeException(
            static fn (): mixed => $recovery->invoke(
                $manager,
                static fn (): null => null,
            ),
        );
        self::assertStringContainsString('candidate quota', $exception->getMessage());
        foreach ($candidates as $candidate) {
            self::assertFileExists($candidate);
        }
    }

    public function testPackageRecoveryRejectsWindowsCaseAliasedReservedLeaves(): void
    {
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::markTestSkipped('Windows reserved namespaces are case-insensitive.');
        }
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('windows-case-alias'), 'default');
        $manager->activate($initial['slot']);
        $alias = \dirname($this->paths->hostIdFile()) . DIRECTORY_SEPARATOR
            . \strtoupper(\basename($this->paths->hostIdFile()))
            . '.TMP-' . \str_repeat('a', 24);
        self::assertNotFalse(\file_put_contents($alias, 'partial-host'));

        $recovery = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'withInstallLock',
        );
        $exception = $this->captureRuntimeException(
            static fn (): mixed => $recovery->invoke(
                $manager,
                static fn (): null => null,
            ),
        );
        self::assertStringContainsString('malformed reserved leaf', $exception->getMessage());
        self::assertFileExists($alias);
    }

    public function testLegacyStableLauncherIdentityMigratesOnlyFromVerifiedActiveSlot(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('legacy-initial'), 'default');
        $manager->activate($initial['slot']);
        $identityFile = $this->paths->trustDir() . DIRECTORY_SEPARATOR
            . 'stable-launcher.sha256';
        self::assertTrue(\unlink($identityFile));
        $stableDigest = \hash_file('sha256', $this->paths->launcherFile());
        self::assertIsString($stableDigest);

        $candidate = $manager->stage($this->createPackage('legacy-candidate'), 'default');

        self::assertSame($initial['slot'] === 'A' ? 'B' : 'A', $candidate['slot']);
        self::assertSame(
            $stableDigest . PHP_EOL,
            \file_get_contents($identityFile),
        );
        self::assertSame(
            $stableDigest,
            \hash_file('sha256', $this->paths->launcherFile()),
        );
    }

    public function testUpgradeReadinessBudgetCoversAllControllerPhases(): void
    {
        $budget = new \ReflectionMethod(
            GatewayHostManager::class,
            'upgradeReadinessTimeoutSeconds',
        );
        self::assertSame(60.0, $budget->invoke(null));
        $source = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/Service/Edge/Gateway/GatewayHostManager.php'
        );
        self::assertStringContainsString('\\hrtime(true)', $source);
        self::assertStringNotContainsString('\\microtime(true) + 30.0', $source);
    }

    public function testIncompatibleBootstrapGenerationIsRejectedBeforeSlotActivation(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('stable-initial'), 'default');
        $manager->activate($initial['slot']);
        try {
            $manager->stage(
                $this->createPackage('stable-incompatible', true),
                'default',
            );
            self::fail('An incompatible bootstrap generation must be rejected before activation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'explicit full host rebootstrap from a signed package',
                $exception->getMessage(),
            );
        }
        self::assertSame($initial['slot'], $this->paths->activeSlot());
        self::assertDirectoryDoesNotExist(
            $this->paths->slotDir($initial['slot'] === 'A' ? 'B' : 'A'),
        );
    }

    public function testNativeControlPlaneHandoffContractsArePresent(): void
    {
        $gateway = \dirname(__DIR__, 5) . '/Service/Edge/Gateway';
        $packages = (string)\file_get_contents($gateway . '/HostGatewayPackageManager.php');
        $platform = (string)\file_get_contents($gateway . '/GatewayPlatformServiceInstaller.php');
        $posix = (string)\file_get_contents($gateway . '/Native/posix/wls_gateway_launcher.c');
        $posixBroker = (string)\file_get_contents($gateway . '/Native/posix/wls_gateway_broker.c');
        $windows = (string)\file_get_contents($gateway . '/Native/windows/wls_gateway_launcher.c');
        $broker = (string)\file_get_contents($gateway . '/Native/windows/wls_gateway_broker.c');
        $controller = (string)\file_get_contents(
            \dirname(__DIR__, 5) . '/bin/wls_gateway_controller.php',
        );
        self::assertStringContainsString(
            '->secureInstalledRuntimeSlot(',
            $packages,
        );
        self::assertStringContainsString(
            '$this->activeOperationDeadline(),',
            $packages,
        );
        self::assertStringContainsString('package-install.lock', $packages);
        self::assertStringNotContainsString(
            "stateDir() . DIRECTORY_SEPARATOR . 'install.lock'",
            $packages,
        );
        self::assertStringContainsString(
            'public function secureInstalledRuntimeSlot(',
            $platform,
        );
        self::assertStringContainsString(
            "'windows_named_pipe_deadline_transport'",
            $packages,
        );
        self::assertStringContainsString(
            'bounded named-pipe deadline transport capability',
            $packages,
        );
        self::assertStringContainsString(
            "'--pipe-deadline-self-test'",
            $packages,
        );
        self::assertStringContainsString(
            'initial installation cannot deadlock on its own ordering',
            $platform,
        );
        self::assertStringContainsString(
            'GatewayBoundedTreeWalker::collect($normalizedRoot, true)',
            $platform,
        );
        self::assertStringContainsString(
            "\$dataPlaneReadable ? 0555 : 0550",
            $platform,
        );
        self::assertStringContainsString("'_welinegateway_nginx'", $platform);
        self::assertStringContainsString("'weline-gateway-nginx'", $platform);
        self::assertStringContainsString(
            '$this->paths->sealedSnapshotsDir() => [0, $dataPlaneGid, 0710]',
            $platform,
        );
        self::assertStringContainsString(
            '$this->paths->snapshotCandidatesDir() => [$uid, $gid, 0700]',
            $platform,
        );
        self::assertStringContainsString(
            'WLS Gateway data-plane identity belongs to the Controller group.',
            $platform,
        );
        self::assertStringContainsString(
            'An installed stable launcher can predate the current project',
            $platform,
        );
        self::assertStringContainsString(
            '$this->restart($kind, $deadlineMonotonic);',
            $platform,
        );
        self::assertStringNotContainsString("'--kill-whom=main'", $platform);
        self::assertStringNotContainsString("'control', self::SERVICE_NAME, '6'", $platform);
        self::assertStringContainsString('$this->waitForWindowsServiceState(1);', $platform);
        self::assertStringContainsString('$this->waitForWindowsServiceState(4);', $platform);
        self::assertStringContainsString("'unrestricted'", $platform);
        self::assertStringContainsString("'sidtype'", $platform);
        self::assertStringContainsString('Set-WlsExactAcl', $platform);
        self::assertStringContainsString('AreAccessRulesProtected', $platform);
        self::assertStringContainsString("'/setowner'", $platform);
        self::assertStringContainsString("['/bin/chmod', '-RN'", $platform);
        self::assertStringContainsString('WLS_CONTROL_TREE_RELOAD', $posix);
        self::assertStringContainsString('signal_number == SIGHUP', $posix);
        self::assertStringContainsString(
            'static int wls_reap_controller(',
            $posixBroker,
        );
        self::assertStringContainsString(
            'waitpid(controller_pid, controller_status, options)',
            $posixBroker,
        );
        self::assertStringContainsString('wls_release_controller_socket(controller_socket)', $posixBroker);
        self::assertStringContainsString('broker controller restart attempt', $posixBroker);
        self::assertStringContainsString('broker controller restart ready', $posixBroker);
        self::assertStringContainsString('broker controller restart exhausted', $posixBroker);
        self::assertStringContainsString('pthread_join(thread, NULL)', $posixBroker);
        self::assertStringContainsString('PR_SET_NO_NEW_PRIVS', $posixBroker);
        self::assertStringNotContainsString('CAP_NET_BIND_SERVICE', $posixBroker);
        self::assertStringContainsString(
            'peer.uid == (unsigned long)wls_data_plane_identity.uid',
            $posixBroker,
        );
        self::assertStringContainsString(
            "\$this->paths->runDir() => [0, \$gid, 0771]",
            $platform,
        );
        self::assertStringContainsString('broker-fencing-token', $posixBroker);
        self::assertStringContainsString('\"action_protocol\":2', $posixBroker);
        self::assertStringContainsString('SECURITY_RESERVE', $posixBroker);
        self::assertStringContainsString('AUTH_PREPARE', $posixBroker);
        self::assertStringContainsString('ATOMIC_REPLACE', $posixBroker);
        self::assertStringContainsString(
            '#define WLS_MAX_REQUEST (4U * 1024U * 1024U)',
            $posixBroker,
        );
        self::assertStringContainsString(
            '#define WLS_MAX_ATOMIC_NONCE_WAL (20U * 1024U * 1024U)',
            $posixBroker,
        );
        self::assertStringContainsString(
            '#define WLS_MAX_ATOMIC_STATE (64U * 1024U * 1024U)',
            $posixBroker,
        );
        self::assertStringContainsString('wls_atomic_target_maximum', $posixBroker);
        self::assertStringContainsString(
            '#define WLS_ATOMIC_REPLACE_BACKUPS_MAXIMUM 64U',
            $posixBroker,
        );
        self::assertStringContainsString(
            '#define WLS_ATOMIC_REPLACE_DIRECTORY_ENTRIES_MAXIMUM 8192U',
            $posixBroker,
        );
        self::assertStringContainsString(
            'static const struct wls_atomic_target_limit wls_atomic_targets[]',
            $posixBroker,
        );
        self::assertStringContainsString(
            'wls_recover_atomic_replace_backups_target(',
            $posixBroker,
        );
        self::assertStringContainsString(
            'wls_recover_atomic_replace_backups(home)',
            $posixBroker,
        );
        self::assertStringContainsString(
            'wls_atomic_replace_backup_name(',
            $posixBroker,
        );
        self::assertStringContainsString(
            'target_status.st_nlink == 2',
            $posixBroker,
        );
        self::assertStringContainsString(
            'candidates[index].status.st_ino != target_status.st_ino',
            $posixBroker,
        );
        self::assertStringContainsString(
            'pthread_mutex_lock(&wls_atomic_replace_mutex)',
            $posixBroker,
        );
        self::assertStringContainsString(
            'commit_ambiguous ? "COMMIT_AMBIGUOUS" : "ATOMIC_REPLACE_FAILED"',
            $posixBroker,
        );
        self::assertStringContainsString(
            '{"state/neutral-cert.pem", WLS_MAX_REQUEST}',
            $posixBroker,
        );
        self::assertStringContainsString(
            '{"state/lease-checkpoint.json", WLS_MAX_REQUEST}',
            $posixBroker,
        );
        self::assertStringContainsString(
            '{"state/neutral-key.pem", WLS_MAX_REQUEST}',
            $posixBroker,
        );
        self::assertStringContainsString('ATOMIC_REPLACE_CLEANUP', $posixBroker);
        self::assertStringContainsString('CLEANUP_PENDING', $posixBroker);
        self::assertStringContainsString(
            'replaced && backup_created && !committed',
            $posixBroker,
        );
        self::assertStringNotContainsString(
            'expected_size > WLS_MAX_REQUEST',
            $posixBroker,
        );
        self::assertStringContainsString('PROCESS_ATTEST', $posixBroker);
        self::assertStringContainsString('WLS-UPGRADE-STATE/3', $posix);
        self::assertStringContainsString('SERVICE_ACCEPT_PARAMCHANGE', $windows);
        self::assertStringContainsString('SERVICE_CONTROL_PARAMCHANGE', $windows);
        self::assertStringContainsString('wls_classify_broker_exit', $windows);
        self::assertStringContainsString('wls_publish_broker_stop_event', $windows);
        self::assertStringContainsString('wls_unpublish_broker_stop_event', $windows);
        self::assertStringContainsString('wls_service_reload_generation', $windows);
        self::assertStringContainsString('reload_failed', $windows);
        self::assertStringNotContainsString('wls_service_reload_requested', $windows);
        self::assertStringContainsString(
            'int platform_signal = wls_take_shutdown_signal();',
            $posix,
        );
        self::assertStringContainsString(
            'exit_code == WLS_CONTROL_TREE_RELOAD',
            $posix,
        );
        $windowsControl = \substr(
            $windows,
            (int)\strpos($windows, 'static DWORD WINAPI wls_service_control('),
            (int)\strpos($windows, 'static HANDLE wls_create_supervision_job(void)')
                - (int)\strpos($windows, 'static DWORD WINAPI wls_service_control('),
        );
        self::assertTrue(
            \strpos($windowsControl, 'InterlockedExchange(&wls_service_stop_requested, 1);')
                < \strpos($windowsControl, 'wls_report_service_pending('),
            'Windows stop ownership must be visible before STOP_PENDING can race Broker exit.',
        );
        self::assertStringContainsString(
            'SERVICE_STOP_PENDING,',
            $windowsControl,
        );
        self::assertStringContainsString('IsProcessInJob', $windows);
        self::assertStringContainsString('bin/nginx.exe', $windows);
        self::assertStringContainsString('--adopted-nginx-pid', $windows);
        self::assertStringContainsString('adopted_nginx_pid', $broker);
        self::assertStringContainsString('\"action_protocol\":2', $broker);
        self::assertStringContainsString('WLS-NGINX-PROCESS/2', $broker);
        self::assertStringContainsString('WLS-BROKER-LAUNCH/2', $broker);
        self::assertStringContainsString('SECURITY_RESERVE', $broker);
        self::assertStringContainsString('AUTH_PREPARE', $broker);
        self::assertStringContainsString('ATOMIC_REPLACE', $broker);
        self::assertStringContainsString(
            '#define WLS_MAX_REQUEST (4U * 1024U * 1024U)',
            $broker,
        );
        self::assertStringContainsString(
            '#define WLS_MAX_ATOMIC_NONCE_WAL (20ULL * 1024ULL * 1024ULL)',
            $broker,
        );
        self::assertStringContainsString(
            '#define WLS_MAX_ATOMIC_STATE (64ULL * 1024ULL * 1024ULL)',
            $broker,
        );
        self::assertStringContainsString('wls_win_atomic_target_maximum', $broker);
        self::assertStringContainsString(
            '#define WLS_ATOMIC_REPLACE_BACKUPS_MAXIMUM 64U',
            $broker,
        );
        self::assertStringContainsString(
            '#define WLS_ATOMIC_REPLACE_DIRECTORY_ENTRIES_MAXIMUM 8192U',
            $broker,
        );
        self::assertStringContainsString(
            'static const struct wls_win_atomic_target_limit wls_win_atomic_targets[]',
            $broker,
        );
        self::assertStringContainsString(
            'wls_win_recover_atomic_replace_backups_target(',
            $broker,
        );
        self::assertStringContainsString(
            'wls_win_recover_atomic_replace_backups(home, 1)',
            $broker,
        );
        self::assertStringContainsString(
            'wls_win_atomic_replace_backup_name(',
            $broker,
        );
        self::assertStringContainsString(
            '_wcsnicmp(leaf, prefix, prefix_length)',
            $broker,
        );
        self::assertStringContainsString(
            'wcsncmp(leaf, prefix, prefix_length)',
            $broker,
        );
        self::assertStringContainsString(
            'wls_win_atomic_owner_allowed(',
            $broker,
        );
        self::assertStringContainsString(
            'ERROR_SHARING_VIOLATION',
            $broker,
        );
        self::assertStringContainsString(
            'AcquireSRWLockExclusive(&wls_atomic_replace_lock)',
            $broker,
        );
        self::assertStringContainsString(
            'commit_ambiguous ? "COMMIT_AMBIGUOUS" : "ATOMIC_REPLACE_FAILED"',
            $broker,
        );
        self::assertStringContainsString(
            '{L"state\\\\neutral-cert.pem", WLS_MAX_REQUEST}',
            $broker,
        );
        self::assertStringContainsString(
            '{L"state\\\\lease-checkpoint.json", WLS_MAX_REQUEST}',
            $broker,
        );
        self::assertStringContainsString(
            '{L"state\\\\neutral-key.pem", WLS_MAX_REQUEST}',
            $broker,
        );
        self::assertStringContainsString('wls_win_read_digest_bounded', $broker);
        self::assertStringContainsString('BCryptHashData(hash, buffer, amount, 0U)', $broker);
        self::assertStringContainsString('ATOMIC_REPLACE_CLEANUP', $broker);
        self::assertStringContainsString('CLEANUP_PENDING', $broker);
        self::assertStringContainsString(
            'replaced && target_existed && !committed',
            $broker,
        );
        self::assertStringNotContainsString(
            'expected_size > WLS_MAX_REQUEST',
            $broker,
        );
        self::assertStringNotContainsString('(void)DeleteFileW(backup);', $broker);
        self::assertStringContainsString('PROCESS_ATTEST', $broker);
        self::assertStringContainsString('CLEANUP_PENDING', $controller);
        self::assertStringContainsString(
            'private const MAX_ATOMIC_REPLACE_CLEANUP_QUEUE = 16;',
            $controller,
        );
        self::assertStringContainsString(
            "\$targetMode = \\sprintf('%04o', \$mode);",
            $controller,
        );
        self::assertStringContainsString(
            'private function atomicReplaceCleanupQueue(): array',
            $controller,
        );
        self::assertStringContainsString(
            'private function retryPendingAtomicReplaceCleanup(',
            $controller,
        );
        self::assertStringContainsString(
            "'ATOMIC_REPLACE_CLEANUP'",
            $controller,
        );
        self::assertStringContainsString(
            'if (!$this->atomicWriteCommittedAfterImageMatches(',
            $controller,
        );
        self::assertStringContainsString(
            "\\hash_equals('COMMIT_AMBIGUOUS', \$exception->stableCode())",
            $controller,
        );
        self::assertStringContainsString(
            'private function reconcileAmbiguousNativeAtomicReplace(',
            $controller,
        );
        self::assertStringContainsString(
            "'ATOMIC_REPLACE_CLEANUP_PENDING'",
            $controller,
        );
        self::assertStringNotContainsString(
            'throw $throwable;',
            \substr(
                $controller,
                (int)\strpos(
                    $controller,
                    'private function retryPendingAtomicReplaceCleanup(',
                ),
                (int)\strpos(
                    $controller,
                    'private function reconcileCommittedAtomicWrite(',
                ) - (int)\strpos(
                    $controller,
                    'private function retryPendingAtomicReplaceCleanup(',
                ),
            ),
        );
        self::assertStringContainsString('WLS-UPGRADE-STATE/3', $windows);
        self::assertStringContainsString('expected_a', $broker);
        self::assertStringContainsString('expected_b', $broker);
        self::assertStringContainsString('CreateRestrictedToken', $broker);
        self::assertStringContainsString('before_time.ChangeTime', $broker);
        self::assertStringContainsString('FILE_SHARE_READ,', $broker);
    }

    public function testNativeWorkflowExecutesIsolatedPlatformRecoveryContracts(): void
    {
        $server = \dirname(__DIR__, 5);
        $repository = \dirname($server, 4);
        $native = $server . '/Service/Edge/Gateway/Native';
        $cmake = (string)\file_get_contents($native . '/CMakeLists.txt');
        $testBroker = (string)\file_get_contents(
            $native . '/windows/wls_gateway_test_broker.c',
        );
        $windowsBroker = (string)\file_get_contents(
            $native . '/windows/wls_gateway_broker.c',
        );
        $boundedCommand = (string)\file_get_contents(
            $native . '/windows/wls_bounded_command.c',
        );
        $fixture = (string)\file_get_contents(
            $server . '/Test/Integration/Service/Edge/Gateway/windows_service_recovery.php',
        );
        $workflow = (string)\file_get_contents(
            $repository . '/.github/workflows/wls-gateway-native.yml',
        );
        $launcherTest = (string)\file_get_contents(
            $server . '/Test/Integration/Service/Edge/Gateway/NativeGatewayLauncherTest.php',
        );

        self::assertStringContainsString('option(WLS_BUILD_TEST_HELPERS', $cmake);
        self::assertStringContainsString('WLS_NATIVE_TEST_HOOKS=1', $cmake);
        self::assertStringContainsString('wls-gateway-test-broker', $cmake);
        self::assertStringContainsString('wls-bounded-command', $cmake);
        self::assertStringContainsString('wls-gateway-guardian', $cmake);
        self::assertStringContainsString('WLS_GUARDIAN_EXECUTABLE=1', $cmake);
        self::assertStringNotContainsString(
            'install(TARGETS wls-gateway-test-broker',
            $cmake,
        );
        self::assertStringContainsString('state\\\\test-starts.log', $testBroker);
        self::assertStringContainsString('state\\\\test-hold', $testBroker);
        self::assertStringContainsString('if (!marker_existed', $testBroker);
        self::assertStringContainsString('OpenEventW(SYNCHRONIZE', $testBroker);
        self::assertStringContainsString('WAIT_OBJECT_0 ? 5 : 4', $testBroker);
        self::assertStringContainsString('#if defined(WLS_NATIVE_TEST_HOOKS)', $windowsBroker);
        self::assertStringContainsString('wls_allowed_private_reader', $windowsBroker);
        self::assertStringContainsString('GetAclInformation', $windowsBroker);
        self::assertStringContainsString('total != (uint64_t)before_size.EndOfFile.QuadPart', $windowsBroker);
        self::assertStringContainsString('--snapshot-private-test', $windowsBroker);
        self::assertStringContainsString('JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE', $boundedCommand);
        self::assertStringContainsString('CREATE_SUSPENDED', $boundedCommand);
        self::assertStringContainsString('PROC_THREAD_ATTRIBUTE_JOB_LIST', $boundedCommand);
        self::assertStringContainsString('IsProcessInJob', $boundedCommand);
        self::assertStringContainsString('TerminateJobObject', $boundedCommand);
        self::assertStringContainsString('PROC_THREAD_ATTRIBUTE_HANDLE_LIST', $boundedCommand);
        self::assertStringContainsString('wls-bounded-command-result/1', $boundedCommand);
        self::assertStringContainsString(
            'return wls_snapshot_command(argc, argv, 1);',
            $windowsBroker,
        );
        self::assertStringContainsString('Refusing to replace existing file', $fixture);
        self::assertStringContainsString('wlsMkdirExclusive(', $fixture);
        self::assertStringContainsString('wlsWriteExclusive(', $fixture);
        self::assertStringNotContainsString('WLS_WINDOWS_TEST_SERVICE', $fixture);
        self::assertStringNotContainsString('service-test', $fixture);
        self::assertStringContainsString('Broad-readable private key was accepted', $fixture);
        self::assertStringContainsString(
            'Unrelated readable SID on a private key was accepted',
            $fixture,
        );
        self::assertStringContainsString('Source reparse point was followed', $fixture);
        self::assertStringContainsString('Destination reparse point was followed', $fixture);
        self::assertStringContainsString('WLS_BUILD_TEST_HELPERS=ON', $workflow);
        self::assertStringContainsString(
            'wls-gateway-guardian.exe --self-test',
            $workflow,
        );
        self::assertStringContainsString('NativeGatewayBrokerTest.php', $workflow);
        self::assertSame(
            2,
            \substr_count($workflow, 'NativeGatewayAtomicCandidateRecoveryContractTest.php'),
            'Both POSIX and Windows native jobs must execute the atomic candidate recovery contract.',
        );
        self::assertSame(
            4,
            \substr_count($workflow, 'ManagedNginxInstallerAtomicRecoveryTest.php'),
            'Both triggers and both platform jobs must cover the installer crash-recovery contract.',
        );
        self::assertSame(
            4,
            \substr_count($workflow, 'NginxConfigPublicationTest.php'),
            'Both triggers and both platform jobs must cover the config publication crash-recovery contract.',
        );
        self::assertStringContainsString(
            'app/code/Weline/Server/Service/Edge/Nginx/ManagedNginxInstaller.php',
            $workflow,
        );
        self::assertStringContainsString(
            'app/code/Weline/Server/Service/Edge/Nginx/Runtime/NginxConfigPublication.php',
            $workflow,
        );
        self::assertStringContainsString('--bootstrap vendor/autoload.php', $workflow);
        self::assertStringNotContainsString('--bootstrap app/bootstrap_phpunit.php', $workflow);
        self::assertStringContainsString('if: always()', $workflow);
        self::assertStringContainsString('Windows path security integration', $workflow);
        self::assertStringContainsString('Windows bounded-command tree integration', $workflow);
        self::assertStringContainsString(
            'WLS_RUN_NATIVE_GATEWAY_WINDOWS_BOUNDED_COMMAND_INTEGRATION: "1"',
            $workflow,
        );
        self::assertStringContainsString(
            'WLS_RUN_NATIVE_GATEWAY_WINDOWS_PATH_INTEGRATION: "1"',
            $workflow,
        );
        self::assertStringNotContainsString('Windows SCM recovery integration', $workflow);
        self::assertStringNotContainsString(
            'WLS_RUN_NATIVE_GATEWAY_WINDOWS_SERVICE_INTEGRATION: "1"',
            $workflow,
        );
        self::assertStringContainsString('WLS_RUN_NATIVE_GATEWAY_SYSTEMD_INTEGRATION', $workflow);
        self::assertStringContainsString('WLS_RUN_NATIVE_GATEWAY_LAUNCHD_INTEGRATION', $workflow);
        self::assertStringContainsString(
            'WLS_RUN_NATIVE_GATEWAY_SYSTEM_LAUNCHD_INTEGRATION',
            $workflow,
        );
        self::assertStringContainsString(
            'testMacOsSystemLaunchDaemonRestartsUnexpectedCleanBrokerExit',
            $launcherTest,
        );
        self::assertStringContainsString(
            "\$marker = \$systemRoot . '/system-launchd-clean-exit-starts';",
            $launcherTest,
        );
        self::assertStringContainsString(
            "\$marker = \$systemRoot . '/systemd-clean-exit-starts';",
            $launcherTest,
        );
        self::assertStringContainsString('private function stopProcess(', $launcherTest);
    }

    public function testWindowsServiceFixtureGeneratesEphemeralSigningPair(): void
    {
        $fixture = \dirname(__DIR__, 5)
            . '/Test/Integration/Service/Edge/Gateway/windows_service_recovery.php';
        $keyFile = $this->root . DIRECTORY_SEPARATOR . 'windows-scm-key.json';
        self::assertTrue(\mkdir($this->root, 0700, true));
        $generated = $this->runCommand([
            PHP_BINARY,
            $fixture,
            'keygen',
            '--output=' . $keyFile,
        ]);
        self::assertSame(0, $generated['code'], $generated['output']);
        self::assertFileExists($keyFile);
        $key = \json_decode((string)\file_get_contents($keyFile), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($key);
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $key['public_key_hex']);
        $secret = \base64_decode((string)$key['secret_key_base64'], true);
        self::assertIsString($secret);
        self::assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, \strlen($secret));
        self::assertSame(
            $key['public_key_hex'],
            \bin2hex(\sodium_crypto_sign_publickey_from_secretkey($secret)),
        );
        \sodium_memzero($secret);
        \sodium_memzero($key['secret_key_base64']);
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0600, \fileperms($keyFile) & 0777);
        }
        $digest = \hash_file('sha256', $keyFile);
        self::assertIsString($digest);
        $second = $this->runCommand([
            PHP_BINARY,
            $fixture,
            'keygen',
            '--output=' . $keyFile,
        ]);
        self::assertNotSame(0, $second['code'], $second['output']);
        self::assertSame($digest, \hash_file('sha256', $keyFile));
    }

    public function testWindowsServiceQueryStateParserUsesStrictScNumericState(): void
    {
        $parse = new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'windowsServiceStateFromQuery',
        );
        self::assertSame(1, $parse->invoke(null, "STATE              : 1  STOPPED\r\n"));
        self::assertSame(3, $parse->invoke(
            null,
            "        TYPE               : 10  WIN32_OWN_PROCESS\r\n"
                . "        STATE              : 3  STOP_PENDING\r\n",
        ));
        self::assertSame(4, $parse->invoke(
            null,
            "SERVICE_NAME: weline-wls-gateway-v2\r\n"
                . "        STATE              : 4  RUNNING\r\n",
        ));
        self::assertNull($parse->invoke(null, "TYPE : 1  KERNEL_DRIVER\r\n"));
        self::assertNull($parse->invoke(null, "STATE : 8  UNKNOWN\r\n"));
    }

    public function testRebootstrapCandidateIsPermissionSealedAndNonceBound(): void
    {
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $packages = new HostGatewayPackageManager(
            paths: $this->paths,
            platform: $platform,
        );
        $initial = $packages->stage(
            $this->createPackage('rebootstrap-seal-old'),
            'default',
        );
        $packages->activate($initial['slot']);
        $candidatePackage = $this->createPackage(
            'rebootstrap-seal-new',
            true,
        );
        $nonce = \bin2hex(\random_bytes(16));

        $prepared = $packages->prepareRebootstrapCandidate(
            $candidatePackage,
            'default',
            $nonce,
        );

        self::assertSame('PREPARED', $prepared['phase']);
        self::assertSame(
            $prepared['runtime_generation'],
            $packages->prepareRebootstrapCandidate(
                $candidatePackage,
                'default',
                $nonce,
            )['runtime_generation'],
        );
        $launcher = $this->paths->rebootstrapCandidateDir($nonce)
            . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
            . $this->binaryName('wls-gateway-launcher');
        self::assertFileExists($launcher);
        $packageSource = (string)\file_get_contents(
            \dirname(__DIR__, 5)
                . '/Service/Edge/Gateway/HostGatewayPackageManager.php',
        );
        $sealAt = \strpos(
            $packageSource,
            '->secureRebootstrapCandidateRuntime(',
        );
        $selfTestAt = \strpos(
            $packageSource,
            '$this->runSlotSelfTests(',
            $sealAt,
        );
        self::assertIsInt($sealAt);
        self::assertIsInt($selfTestAt);
        self::assertLessThan($selfTestAt, $sealAt);

        try {
            $packages->prepareRebootstrapCandidate(
                $candidatePackage,
                'default',
                \bin2hex(\random_bytes(16)),
            );
            self::fail('A different nonce must not join an active rebootstrap.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'different gateway rebootstrap',
                $exception->getMessage(),
            );
        }
        try {
            $packages->prepareRebootstrapCandidate(
                $this->createPackage('rebootstrap-seal-other', true),
                'default',
                $nonce,
            );
            self::fail('A different package must not reuse a rebootstrap nonce.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'different gateway rebootstrap',
                $exception->getMessage(),
            );
        }
    }

    public function testRebootstrapPhysicallyHoldsCapacityBeforeStopAndReleasesAfterQuiescence(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'physical-capacity-order',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        try {
            $packages->advanceRebootstrapPhase(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'PREPARED',
                'STOP_COMMITTED',
                [
                    'admin_stopped_contents' => $fixture['admin_stopped_intent'],
                    'gateway_epoch' => $fixture['gateway_epoch'],
                ],
            );
            self::fail('A logical free-space check must not authorize stop.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('HELD', $exception->getMessage());
        }

        $held = $packages->ensureRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('HELD', $held['capacity_reserve_state']);
        self::assertSame(8_388_608, $held['capacity_reserve_bytes']);
        self::assertSame(128, $held['capacity_reserve_inodes']);
        self::assertDirectoryExists(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'PREPARED',
            'STOP_COMMITTED',
            [
                'admin_stopped_contents' => $fixture['admin_stopped_intent'],
                'gateway_epoch' => $fixture['gateway_epoch'],
            ],
        );
        $platform->stop((string)$fixture['snapshot']['kind']);
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        // The native helper may have already durably marked the HELD tree as
        // releasing before PHP persisted RELEASING. In that crash window the
        // strict pre-stop verify command must stay closed, while the
        // authenticated begin-release replay remains available.
        self::assertNotFalse(\file_put_contents(
            $this->paths->stateDir() . DIRECTORY_SEPARATOR . $fixture['nonce']
                . '.force-release-transition',
            "transition\n",
        ));
        $released = $packages->releaseRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'forward',
        );
        self::assertSame('RELEASED', $released['capacity_reserve_state']);
        self::assertSame('forward', $released['capacity_reserve_release_reason']);
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingDir($fixture['nonce']),
        );
    }

    public function testCapacityReleaseReplaysNativeTransitionBeforePhpJournalAndStaysReleasedAcrossPublishCrash(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'capacity-native-transition-before-php-journal',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );

        $this->assertRebootstrapCrash(
            fn (): array => $packages->releaseRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'forward',
            ),
            'capacity-reserve:after-native-begin-before-releasing-journal',
        );

        $beforePhpJournal = $packages->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($beforePhpJournal);
        self::assertSame('QUIESCED', $beforePhpJournal['phase']);
        self::assertSame('HELD', $beforePhpJournal['capacity_reserve_state']);
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        self::assertDirectoryExists(
            $this->paths->rebootstrapCapacityReleasingDir($fixture['nonce']),
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingReceiptFile(
                $fixture['nonce'],
            ),
        );

        $released = $packages->releaseRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'forward',
        );
        self::assertSame('RELEASED', $released['capacity_reserve_state']);
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingDir($fixture['nonce']),
        );

        $this->assertRebootstrapCrash(
            fn (): array => $packages->publishRebootstrapGeneration(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
            'after:DERIVED_MANIFEST_BOUND',
        );
        $beforePublish = $packages->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($beforePublish);
        self::assertSame('QUIESCED', $beforePublish['phase']);
        self::assertSame('RELEASED', $beforePublish['capacity_reserve_state']);
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingDir($fixture['nonce']),
        );

        $published = $packages->publishRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('NEW_GENERATION_PUBLISHED', $published['phase']);
        self::assertSame('RELEASED', $published['capacity_reserve_state']);
    }

    public function testPreStopCancellationBindsNativeHeldAllocationBeforeReleasingIt(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-native-held-before-manifest-cancel',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $this->assertRebootstrapCrash(
            fn (): array => $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
            'capacity-reserve:after-native-create-before-held-manifest',
        );

        $unbound = $packages->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($unbound);
        self::assertSame('PREPARED', $unbound['phase']);
        self::assertSame('ALLOCATING', $unbound['capacity_reserve_state']);
        self::assertDirectoryExists(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityHeldManifestFile(
                $fixture['nonce'],
            ),
        );

        $released = $packages->releaseRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'cancel',
        );
        self::assertSame('RELEASED', $released['capacity_reserve_state']);
        self::assertSame('cancel', $released['capacity_reserve_release_reason']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            (string)$released['capacity_reserve_manifest_sha256'],
        );
        self::assertFileExists(
            $this->paths->rebootstrapCapacityHeldManifestFile(
                $fixture['nonce'],
            ),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingDir($fixture['nonce']),
        );
    }

    public function testRebootstrapCapacityRejectsNestedForeignNamespaceBeforeAllocation(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped(
                'Windows nested-junction coverage runs in the native Windows recovery fixture.',
            );
        }
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-foreign-derived-namespace',
        );
        $foreign = $this->root . DIRECTORY_SEPARATOR . 'foreign-derived';
        self::assertTrue(\mkdir($foreign, 0700));
        $injected = $this->paths->sealedSnapshotsDir()
            . DIRECTORY_SEPARATOR . 'foreign-mounted-state';
        self::assertTrue(\symlink($foreign, $injected));

        try {
            $fixture['packages']->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail(
                'A linked or foreign-volume derived namespace must fail before reserve allocation.',
            );
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'link or special file',
                $exception->getMessage(),
            );
        }
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        $status = $fixture['packages']->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($status);
        self::assertSame('NONE', $status['capacity_reserve_state']);
    }

    public function testPreStopRollbackApiRequiresCancellationAndReleasesHeldCapacity(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'pre-stop-capacity-cancel',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $packages->ensureRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );

        try {
            $packages->beginRebootstrapRollback(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'invalid pre-stop rollback',
            );
            self::fail('Pre-stop rollback must use the cancellation path.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'cancelPreparedRebootstrap',
                $exception->getMessage(),
            );
        }
        $stillPrepared = $packages->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($stillPrepared);
        self::assertSame('PREPARED', $stillPrepared['phase']);
        self::assertSame('HELD', $stillPrepared['capacity_reserve_state']);

        $cancelled = $packages->cancelPreparedRebootstrap(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test pre-stop cancellation',
        );
        self::assertSame('ROLLING_BACK', $cancelled['phase']);
        self::assertSame('RELEASED', $cancelled['capacity_reserve_state']);
        self::assertSame(
            'ROLLED_BACK',
            $packages->completePreparedRebootstrapCancellation(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            )['phase'],
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingDir($fixture['nonce']),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCandidateDir($fixture['nonce']),
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapDerivedManifestFile($fixture['nonce']),
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapStartAuthorizationFile(),
        );
        self::assertSame($fixture['old_active_slot'], $this->paths->activeSlot());
        self::assertSame(
            $fixture['old_launcher_digest'],
            \hash_file('sha256', $this->paths->launcherFile()),
        );
    }

    public function testPreStopCancellationCompletionRejectsAnUnreleasedReserve(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'pre-stop-cancel-unreleased-reserve',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $packages->ensureRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'PREPARED',
            'ROLLING_BACK',
        );
        $before = (string)\file_get_contents(
            $this->paths->rebootstrapJournalFile(),
        );

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->completePreparedRebootstrapCancellation(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
        );

        self::assertStringContainsString(
            'requires NONE capacity or a cancel-bound RELEASED reserve',
            $exception->getMessage(),
        );
        self::assertSame(
            $before,
            (string)\file_get_contents($this->paths->rebootstrapJournalFile()),
        );
        $current = $packages->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($current);
        self::assertSame('ROLLING_BACK', $current['phase']);
        self::assertSame('HELD', $current['capacity_reserve_state']);
        self::assertSame($fixture['old_active_slot'], $this->paths->activeSlot());
        self::assertSame(
            $fixture['old_launcher_digest'],
            \hash_file('sha256', $this->paths->launcherFile()),
        );
    }

    public function testPreStopCancellationCompletionRejectsAnUncancelledPreparedPhase(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'pre-stop-cancel-uncancelled-phase',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $before = (string)\file_get_contents(
            $this->paths->rebootstrapJournalFile(),
        );

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->completePreparedRebootstrapCancellation(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
        );

        self::assertStringContainsString(
            'cannot finish from phase PREPARED',
            $exception->getMessage(),
        );
        self::assertSame(
            $before,
            (string)\file_get_contents($this->paths->rebootstrapJournalFile()),
        );
        self::assertSame(
            'PREPARED',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );
        self::assertSame($fixture['old_active_slot'], $this->paths->activeSlot());
    }

    public function testPreStopCancellationCompletionRejectsPublishedGeneration(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'pre-stop-cancel-published-generation',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
        );
        $before = (string)\file_get_contents(
            $this->paths->rebootstrapJournalFile(),
        );

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->completePreparedRebootstrapCancellation(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
        );

        self::assertStringContainsString(
            'post-stop or generation-mutation evidence',
            $exception->getMessage(),
        );
        self::assertSame(
            $before,
            (string)\file_get_contents($this->paths->rebootstrapJournalFile()),
        );
        self::assertSame(
            'NEW_GENERATION_PUBLISHED',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );
    }

    public function testPreStopCancellationApisRejectPostStopRollingBackJournal(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'post-stop-cancellation-fence',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $packages->ensureRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'PREPARED',
            'STOP_COMMITTED',
            [
                'admin_stopped_contents' => $fixture['admin_stopped_intent'],
                'gateway_epoch' => $fixture['gateway_epoch'],
            ],
        );
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $platform->stop((string)$fixture['snapshot']['kind']);
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        $packages->releaseRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'rollback',
        );
        $rollingBack = $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test post-stop cancellation fence',
        );
        self::assertSame('ROLLING_BACK', $rollingBack['phase']);
        self::assertNotSame('', $rollingBack['admin_stopped_digest']);
        $journalFile = $this->paths->rebootstrapJournalFile();
        $before = (string)\file_get_contents($journalFile);

        foreach ([
            fn (): array => $packages->cancelPreparedRebootstrap(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'must not cancel a stopped generation',
            ),
            fn (): array => $packages->completePreparedRebootstrapCancellation(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
        ] as $attempt) {
            try {
                $attempt();
                self::fail(
                    'A post-stop whole-generation rollback must not use a pre-stop cancellation API.',
                );
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'post-stop or generation-mutation evidence',
                    $exception->getMessage(),
                );
            }
            self::assertSame($before, (string)\file_get_contents($journalFile));
            $current = $packages->rebootstrapStatus($fixture['nonce']);
            self::assertIsArray($current);
            self::assertSame('ROLLING_BACK', $current['phase']);
            self::assertSame(
                $rollingBack['admin_stopped_digest'],
                $current['admin_stopped_digest'],
            );
        }
    }

    public function testPreStopCancellationBeforePlatformSnapshotCreatesOnlyCandidateQuarantine(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $initial = $packages->stage(
            $this->createPackage('pre-snapshot-cancel-old'),
            'default',
        );
        $packages->activate($initial['slot']);
        $nonce = \bin2hex(\random_bytes(16));
        $prepared = $packages->prepareRebootstrapCandidate(
            $this->createPackage('pre-snapshot-cancel-new', true),
            'default',
            $nonce,
        );
        self::assertSame('PREPARED', $prepared['phase']);
        self::assertNull($prepared['platform_snapshot']);

        $cancelled = $packages->cancelPreparedRebootstrap(
            $nonce,
            (string)$prepared['package_digest'],
            'default',
            'test cancellation before platform snapshot',
        );
        self::assertSame('ROLLING_BACK', $cancelled['phase']);
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCandidateDir($nonce),
        );
        self::assertDirectoryExists(
            $this->paths->rebootstrapRollbackNewGenerationDir($nonce)
                . DIRECTORY_SEPARATOR . 'candidate',
        );
        self::assertSame(
            'ROLLED_BACK',
            $packages->completePreparedRebootstrapCancellation(
                $nonce,
                (string)$prepared['package_digest'],
                'default',
            )['phase'],
        );
    }

    public function testRebootstrapJournalTamperFailsBeforeHostMutation(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $initial = $packages->stage(
            $this->createPackage('rebootstrap-tamper-old'),
            'default',
        );
        $packages->activate($initial['slot']);
        $nonce = \bin2hex(\random_bytes(16));
        $packages->prepareRebootstrapCandidate(
            $this->createPackage('rebootstrap-tamper-new', true),
            'default',
            $nonce,
        );
        $journalFile = $this->paths->rebootstrapJournalFile();
        $journal = \json_decode(
            (string)\file_get_contents($journalFile),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($journal);
        $journal['phase'] = 'QUIESCED';
        self::assertNotFalse(\file_put_contents(
            $journalFile,
            \json_encode(
                $journal,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($journalFile, 0600));
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');
        $packages->rebootstrapStatus($nonce);
    }

    public function testCaRotationBindsAndStashesTheCompleteDerivedTrustGeneration(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'ca-derived-stash',
            rotateTrust: true,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $derived = $this->seedRebootstrapDerivedGeneration('stash');
        $recoveryReserve = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'recovery.reserve';
        $recoveryReserveContents = "reserved-recovery-capacity\n";
        self::assertNotFalse(\file_put_contents(
            $recoveryReserve,
            $recoveryReserveContents,
        ));
        self::assertTrue(\chmod($recoveryReserve, 0600));
        $adminToken = (string)\file_get_contents($this->paths->adminTokenFile());
        $hostId = (string)\file_get_contents($this->paths->hostIdFile());
        $adminStopped = (string)\file_get_contents(
            $this->paths->adminStoppedIntentFile(),
        );

        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        $this->assertRebootstrapCrash(
            static fn (): array => self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
            'after:DERIVED_MANIFEST_BOUND',
        );

        $bound = $packages->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($bound);
        self::assertSame('QUIESCED', $bound['phase']);
        self::assertTrue($bound['trust_rotation']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $bound['old_derived_manifest_sha256'],
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $bound['derived_policy_sha256'],
        );
        $manifestContents = (string)\file_get_contents(
            $this->paths->rebootstrapDerivedManifestFile($fixture['nonce']),
        );
        self::assertSame(
            $bound['old_derived_manifest_sha256'],
            \hash('sha256', $manifestContents),
        );
        $manifest = \json_decode(
            $manifestContents,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(4, $manifest['schema_version']);
        if (\PHP_OS_FAMILY === 'Windows') {
            self::assertGreaterThan(0, $manifest['windows_acl_bytes']);
        } else {
            self::assertSame(0, $manifest['windows_acl_bytes']);
        }
        self::assertSame(
            $bound['old_ca_bundle_sha256'],
            $manifest['old_ca_bundle_sha256'],
        );
        self::assertSame(
            $bound['derived_policy_sha256'],
            $manifest['derived_policy_sha256'],
        );
        foreach (\array_keys($derived) as $category) {
            self::assertArrayHasKey($category, $manifest['categories']);
            self::assertMatchesRegularExpression(
                '/\Ahost\/[a-z0-9\/-]+\z/D',
                $manifest['categories'][$category]['root_id'],
                $category,
            );
            self::assertSame(
                \in_array($category, [
                    'runtime-temp',
                    'runtime-shadow',
                    'runtime-run',
                ], true) ? 'ephemeral' : 'restore',
                $manifest['categories'][$category]['policy'],
                $category,
            );
            self::assertIsArray(
                $manifest['categories'][$category]['preserved'],
            );
            self::assertMatchesRegularExpression(
                '/\A[a-z0-9-]+-v2\z/D',
                $manifest['categories'][$category]['authority_profile'],
                $category,
            );
            self::assertSame(
                true,
                $manifest['categories'][$category]['root']['present'],
                $category,
            );
            self::assertSame(
                $manifest['categories'][$category]['authority_profile'] . '-'
                    . ($manifest['categories'][$category]['preserved'] === []
                        ? 'recreate-sealed'
                        : 'preserve-identity'),
                $manifest['categories'][$category]['root']['authority_policy'],
                $category,
            );
            self::assertMatchesRegularExpression(
                \PHP_OS_FAMILY === 'Windows'
                    ? '/\A[a-f0-9]{8,32}\z/D'
                    : '/\A[0-9]+\z/D',
                $manifest['categories'][$category]['root']['device'],
                $category,
            );
            self::assertMatchesRegularExpression(
                \PHP_OS_FAMILY === 'Windows'
                    ? '/\A[a-f0-9]{8,32}\z/D'
                    : '/\A[0-9]+\z/D',
                $manifest['categories'][$category]['root']['inode'],
                $category,
            );
            self::assertNotSame(
                [],
                $manifest['categories'][$category]['entries'],
                $category,
            );
            foreach ($manifest['categories'][$category]['entries'] as $closure) {
                foreach ($closure['records'] as $record) {
                    if (\PHP_OS_FAMILY === 'Windows') {
                        self::assertSame(
                            $manifest['categories'][$category]['authority_profile'],
                            $record['acl_profile'],
                            $category,
                        );
                        self::assertContains(
                            $record['owner_sid'],
                            ['S-1-5-18', 'S-1-5-32-544'],
                            $category,
                        );
                        self::assertSame(
                            $record['acl_sha256'],
                            \hash(
                                'sha256',
                                (string)\base64_decode(
                                    $record['sddl_b64'],
                                    true,
                                ),
                            ),
                            $category,
                        );
                    } else {
                        self::assertArrayNotHasKey('acl_profile', $record);
                        self::assertArrayNotHasKey('owner_sid', $record);
                        self::assertArrayNotHasKey('sddl_b64', $record);
                        self::assertArrayNotHasKey('acl_sha256', $record);
                    }
                }
            }
        }
        self::assertArrayNotHasKey(
            'recovery.reserve',
            $manifest['categories']['state']['entries'],
        );
        self::assertContains(
            'recovery.reserve',
            $manifest['categories']['state']['preserved'],
        );
        self::assertContains(
            'admin.token',
            $manifest['categories']['trust']['preserved'],
        );
        $driftedManifest = $manifest;
        $driftedJournal = $bound;
        $driftedPolicy = \hash('sha256', 'test-derived-policy-drift');
        $driftedManifest['derived_policy_sha256'] = $driftedPolicy;
        $driftedJournal['derived_policy_sha256'] = $driftedPolicy;
        try {
            (new \ReflectionMethod(
                HostGatewayPackageManager::class,
                'assertRebootstrapDerivedManifestContract',
            ))->invoke($packages, $driftedManifest, $driftedJournal);
            self::fail('A bound transaction must reject derived-policy drift.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'policy differs from the in-flight host transaction',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            $adminToken,
            \file_get_contents($this->paths->adminTokenFile()),
        );
        self::assertSame($hostId, \file_get_contents($this->paths->hostIdFile()));
        self::assertSame(
            $adminStopped,
            \file_get_contents($this->paths->adminStoppedIntentFile()),
        );
        self::assertSame(
            $recoveryReserveContents,
            \file_get_contents($recoveryReserve),
        );

        $published = self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('NEW_GENERATION_PUBLISHED', $published['phase']);
        self::assertSame(
            $published['candidate_ca_bundle_sha256'] . "\n",
            \file_get_contents($this->paths->caBundleBaselineFile()),
        );
        $this->assertSeededDerivedGenerationStored(
            $derived,
            $fixture['nonce'],
        );
        self::assertSame(
            $adminToken,
            \file_get_contents($this->paths->adminTokenFile()),
        );
        self::assertSame($hostId, \file_get_contents($this->paths->hostIdFile()));
        self::assertSame(
            $adminStopped,
            \file_get_contents($this->paths->adminStoppedIntentFile()),
        );
        self::assertSame(
            $recoveryReserveContents,
            \file_get_contents($recoveryReserve),
        );
    }

    public function testCaRotationRecoversAPartialStashAndRollbackQuarantinesNewState(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'ca-partial-rollback',
            rotateTrust: true,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $derived = $this->seedRebootstrapDerivedGeneration('partial');
        $adminToken = (string)\file_get_contents($this->paths->adminTokenFile());
        $hostId = (string)\file_get_contents($this->paths->hostIdFile());
        $oldCaBaseline = (string)\file_get_contents(
            $this->paths->caBundleBaselineFile(),
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        $this->assertRebootstrapCrash(
            static fn (): array => self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
            'after:DERIVED_MANIFEST_BOUND',
        );

        $partial = $derived['state'];
        $derivedRoot = $this->paths->rebootstrapDerivedBackupDir(
            $fixture['nonce'],
        );
        self::assertTrue(\mkdir($derivedRoot, 0700));
        $storedState = $derivedRoot . DIRECTORY_SEPARATOR . 'state';
        self::assertTrue(\mkdir($storedState, 0700));
        self::assertTrue(\rename(
            $partial['top_level_path'],
            $storedState . DIRECTORY_SEPARATOR . $partial['leaf'],
        ));

        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $this->assertSeededDerivedGenerationStored(
            $derived,
            $fixture['nonce'],
        );

        $replacement = "new-generation-collision\n";
        self::assertNotFalse(\file_put_contents(
            $partial['payload_path'],
            $replacement,
        ));
        self::assertTrue(\chmod($partial['payload_path'], 0600));
        $newOnly = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'new-only-state.json';
        self::assertNotFalse(\file_put_contents(
            $newOnly,
            "new-generation-only\n",
        ));
        self::assertTrue(\chmod($newOnly, 0600));

        $rollingBack = $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test exact CA-generation rollback',
        );
        self::assertSame('ROLLING_BACK', $rollingBack['phase']);
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test exact CA-generation rollback',
        );
        $this->assertSeededDerivedGenerationRestored(
            $derived,
            $fixture['nonce'],
        );
        self::assertSame(
            $oldCaBaseline,
            \file_get_contents($this->paths->caBundleBaselineFile()),
        );
        self::assertSame(
            $replacement,
            \file_get_contents(
                $this->paths->rebootstrapNewDerivedQuarantineDir(
                    $fixture['nonce'],
                ) . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR
                    . $partial['leaf'],
            ),
        );
        self::assertSame(
            "new-generation-only\n",
            \file_get_contents(
                $this->paths->rebootstrapNewDerivedQuarantineDir(
                    $fixture['nonce'],
                ) . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR
                    . \basename($newOnly),
            ),
        );
        self::assertSame(
            $adminToken,
            \file_get_contents($this->paths->adminTokenFile()),
        );
        self::assertSame($hostId, \file_get_contents($this->paths->hostIdFile()));

        $platform->restoreRebootstrapDefinition(
            $fixture['nonce'],
            $fixture['snapshot'],
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
        );
        $packages->assertRebootstrapRollbackPreStartClosure(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
        );
        $this->acknowledgeRollbackGuardianTransition();
        $terminal = $packages->completeRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('ROLLED_BACK', $terminal['phase']);
    }

    public function testRollbackQuarantinesAndResealsAReplacedRecreatableDerivedRoot(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'derived-root-recreate',
            rotateTrust: true,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $derived = $this->seedRebootstrapDerivedGeneration('root-recreate');
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );

        $manifest = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapDerivedManifestFile(
                    $fixture['nonce'],
                ),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $rootProof = $manifest['categories']['snapshots-v2']['root'];
        $root = $this->paths->sealedSnapshotsDir();
        $displaced = $root . '.pre-replacement';
        self::assertTrue(\rename($root, $displaced));
        self::assertTrue(\rmdir($displaced));
        self::assertTrue(\mkdir($root, 0700));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($root, (int)$rootProof['mode']));
        }
        self::assertNotFalse(\file_put_contents(
            $root . DIRECTORY_SEPARATOR . 'new-generation.dat',
            "new-generation-root\n",
        ));

        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test recreatable root replacement',
        );
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test recreatable root replacement',
        );

        self::assertSame(
            $derived['snapshots-v2']['contents'],
            \file_get_contents($derived['snapshots-v2']['payload_path']),
        );
        self::assertSame(
            "new-generation-root\n",
            \file_get_contents(
                $this->paths->rebootstrapNewDerivedQuarantineDir(
                    $fixture['nonce'],
                ) . DIRECTORY_SEPARATOR . 'snapshots-v2'
                    . DIRECTORY_SEPARATOR . '.wls-root-after-image'
                    . DIRECTORY_SEPARATOR . 'new-generation.dat',
            ),
        );
        $restored = \lstat($root);
        self::assertIsArray($restored);
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(
                (int)$rootProof['mode'],
                (int)$restored['mode'] & 0777,
            );
            self::assertSame((int)$rootProof['uid'], (int)$restored['uid']);
            self::assertSame((int)$rootProof['gid'], (int)$restored['gid']);
        }
    }

    public function testSameCaDerivedWorkingMoveBeforeAclCrashReplaysIdempotently(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'derived-working-acl-replay',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $derived = $this->seedRebootstrapDerivedGeneration(
            'working-acl-replay',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );

        $this->assertRebootstrapCrash(
            static fn (): array => self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
            ),
            'derived-working:after-move-before-acl',
        );
        self::assertSame(
            'OLD_GENERATION_STASHED',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );

        $published = self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
        );
        self::assertSame('NEW_GENERATION_PUBLISHED', $published['phase']);
        $this->assertSeededDerivedGenerationRestored(
            $derived,
            $fixture['nonce'],
        );
    }

    public function testDerivedRollbackMoveBeforeAclCrashReplaysIdempotently(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'derived-rollback-acl-replay',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $derived = $this->seedRebootstrapDerivedGeneration(
            'rollback-acl-replay',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
        );
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test derived rollback ACL replay',
        );

        $this->assertRebootstrapCrash(
            static fn (): array => $packages->rollbackRebootstrapGeneration(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'test derived rollback ACL replay',
            ),
            'derived-rollback:after-move-before-acl',
        );
        self::assertSame(
            'ROLLING_BACK',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );

        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test derived rollback ACL replay',
        );
        $this->assertSeededDerivedGenerationRestored(
            $derived,
            $fixture['nonce'],
        );
    }

    public function testDerivedRootProofRejectsSameInodeParentAuthorityDrift(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX parent authority regression.');
        }
        $this->paths->ensureDirectories();
        $packages = new HostGatewayPackageManager($this->paths);
        $definitions = (new \ReflectionMethod(
            $packages,
            'rebootstrapDerivedNamespaces',
        ))->invoke($packages);
        self::assertIsArray($definitions);
        self::assertIsArray($definitions['snapshots-v2'] ?? null);
        $definition = $definitions['snapshots-v2'];
        $capture = new \ReflectionMethod(
            $packages,
            'captureRebootstrapDerivedRootProof',
        );
        $assertAt = new \ReflectionMethod(
            $packages,
            'assertRebootstrapDerivedRootAt',
        );
        $proof = $capture->invoke(
            $packages,
            $definition,
            'test derived root',
        );
        self::assertIsArray($proof);

        $parent = \dirname((string)$definition['root']);
        $before = \lstat($parent);
        self::assertIsArray($before);
        $originalMode = (int)$before['mode'] & 0777;
        $changedMode = $originalMode ^ 0010;
        try {
            self::assertTrue(\chmod($parent, $changedMode));
            $after = \lstat($parent);
            self::assertIsArray($after);
            self::assertSame((int)$before['dev'], (int)$after['dev']);
            self::assertSame((int)$before['ino'], (int)$after['ino']);
            self::assertSame($changedMode, (int)$after['mode'] & 0777);
            try {
                $assertAt->invoke(
                    $packages,
                    $proof,
                    $definition,
                    'test derived root recheck',
                );
                self::fail(
                    'Same-inode parent authority drift must invalidate the root proof.',
                );
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'authority or identity changed',
                    $exception->getMessage(),
                );
            }
        } finally {
            self::assertTrue(\chmod($parent, $originalMode));
        }
    }

    public function testDerivedRootProofRejectsGroupWritableRootAndParent(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX group-write authority regression.');
        }
        $this->paths->ensureDirectories();
        $packages = new HostGatewayPackageManager($this->paths);
        $definitions = (new \ReflectionMethod(
            $packages,
            'rebootstrapDerivedNamespaces',
        ))->invoke($packages);
        self::assertIsArray($definitions);
        self::assertIsArray($definitions['snapshots-v2'] ?? null);
        $definition = $definitions['snapshots-v2'];
        $capture = new \ReflectionMethod(
            $packages,
            'captureRebootstrapDerivedRootProof',
        );
        $root = (string)$definition['root'];
        $parent = \dirname($root);
        $parentStatus = \lstat($parent);
        self::assertIsArray($parentStatus);
        $parentMode = (int)$parentStatus['mode'] & 0777;
        try {
            self::assertTrue(\chmod($parent, $parentMode | 0020));
            $exception = $this->captureRuntimeException(
                fn (): array => $capture->invoke(
                    $packages,
                    $definition,
                    'test group-writable parent',
                ),
            );
            self::assertStringContainsString(
                'parent authority is unsafe',
                $exception->getMessage(),
            );
        } finally {
            self::assertTrue(\chmod($parent, $parentMode));
        }

        $rootStatus = \lstat($root);
        self::assertIsArray($rootStatus);
        $rootMode = (int)$rootStatus['mode'] & 0777;
        try {
            self::assertTrue(\chmod($root, 0720));
            $exception = $this->captureRuntimeException(
                fn (): array => $capture->invoke(
                    $packages,
                    $definition,
                    'test group-writable root',
                ),
            );
            self::assertStringContainsString(
                'authority is unsafe',
                $exception->getMessage(),
            );
        } finally {
            self::assertTrue(\chmod($root, $rootMode));
        }
    }

    public function testRollbackParentAuthorityDriftFailsBeforeRootQuarantine(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX parent mutation-order regression.');
        }
        $this->paths->ensureDirectories();
        $packages = new HostGatewayPackageManager($this->paths);
        $definitions = (new \ReflectionMethod(
            $packages,
            'rebootstrapDerivedNamespaces',
        ))->invoke($packages);
        self::assertIsArray($definitions);
        $definition = $definitions['snapshots-v2'];
        $root = (string)$definition['root'];
        $marker = $root . DIRECTORY_SEPARATOR . 'must-not-move';
        self::assertNotFalse(\file_put_contents($marker, "still-live\n"));
        $proof = (new \ReflectionMethod(
            $packages,
            'captureRebootstrapDerivedRootProof',
        ))->invoke($packages, $definition, 'test rollback parent proof');
        self::assertIsArray($proof);
        $quarantine = $this->root . DIRECTORY_SEPARATOR
            . 'parent-drift-quarantine';
        self::assertTrue(\mkdir($quarantine, 0700));
        $parent = \dirname($root);
        $status = \lstat($parent);
        self::assertIsArray($status);
        $mode = (int)$status['mode'] & 0777;
        $entries = 0;
        $bytes = 0;
        try {
            self::assertTrue(\chmod($parent, $mode ^ 0010));
            try {
                (new \ReflectionMethod(
                    $packages,
                    'reconcileRebootstrapDerivedRootForRollback',
                ))->invokeArgs($packages, [
                    'snapshots-v2',
                    $definition,
                    $proof,
                    $quarantine,
                    &$entries,
                    &$bytes,
                ]);
                self::fail(
                    'Parent authority drift must fail before root quarantine.',
                );
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'parent authority or identity changed',
                    $exception->getMessage(),
                );
            }
            self::assertFileExists($marker);
            self::assertFileDoesNotExist(
                $quarantine . DIRECTORY_SEPARATOR . '.wls-root-after-image',
            );
        } finally {
            self::assertTrue(\chmod($parent, $mode));
        }
    }

    public function testRollbackRejectsAReplacedPreservedDerivedRootBeforeRuntimeMutation(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'derived-root-preserved',
            rotateTrust: true,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $this->seedRebootstrapDerivedGeneration('root-preserved');
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $launcherBefore = \hash_file('sha256', $this->paths->launcherFile());
        self::assertIsString($launcherBefore);

        $state = $this->paths->stateDir();
        $displaced = $state . '.preserved-original';
        $mode = (int)(\lstat($state)['mode'] ?? 0700) & 0777;
        self::assertTrue(\rename($state, $displaced));
        self::assertTrue(\mkdir($state, 0700));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($state, $mode));
        }
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test preserved root replacement',
        );
        try {
            $packages->rollbackRebootstrapGeneration(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'test preserved root replacement',
            );
            self::fail('A replaced preserved derived root must be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'authority or identity changed',
                $exception->getMessage(),
            );
        }
        self::assertSame(
            $launcherBefore,
            \hash_file('sha256', $this->paths->launcherFile()),
        );
    }

    /** @return iterable<string,array{string}> */
    public static function rebootstrapWorkingGenerationRecoveryProvider(): iterable
    {
        foreach ([
            'root',
            'category',
            'leaf',
            'file-temp',
            'partial-deletion',
            'enospc',
        ] as $shape) {
            yield $shape => [$shape];
        }
    }

    #[DataProvider('rebootstrapWorkingGenerationRecoveryProvider')]
    public function testRollbackRemovesOnlyManifestBoundWorkingGenerationState(
        string $shape,
    ): void {
        $fixture = $this->createStoppedRebootstrapFixture(
            'working-generation-' . \str_replace('-', '', $shape),
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $derived = $this->seedRebootstrapDerivedGeneration(
            'working-' . \str_replace('-', '', $shape),
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        $this->assertRebootstrapCrash(
            static fn (): array => self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
            'after:OLD_GENERATION_STASHED',
        );
        $workingRoot = $this->seedPartialRebootstrapWorkingGeneration(
            $fixture['nonce'],
            $derived,
            $shape,
        );
        self::assertDirectoryExists($workingRoot);
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test working-generation rollback cleanup',
        );

        if (\hash_equals('partial-deletion', $shape)) {
            $this->assertRebootstrapCrash(
                static fn (): array => $packages
                    ->rollbackRebootstrapGeneration(
                        $fixture['nonce'],
                        $fixture['package_digest'],
                        'default',
                        'test working-generation rollback cleanup',
                    ),
                'working-generation:after-first-removal',
            );
            self::assertDirectoryExists($workingRoot);
        } elseif (\hash_equals('enospc', $shape)) {
            \putenv(
                'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                    . 'working-generation:enospc-before-removal',
            );
            try {
                $packages->rollbackRebootstrapGeneration(
                    $fixture['nonce'],
                    $fixture['package_digest'],
                    'default',
                    'test working-generation rollback cleanup',
                );
                self::fail('The simulated working-generation ENOSPC was not raised.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString(
                    'ENOSPC',
                    $exception->getMessage(),
                );
            } finally {
                \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
            }
            self::assertDirectoryExists($workingRoot);
        }

        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test working-generation rollback cleanup',
        );
        self::assertDirectoryDoesNotExist($workingRoot);
        $this->assertSeededDerivedGenerationRestored(
            $derived,
            $fixture['nonce'],
        );
        $platform->restoreRebootstrapDefinition(
            $fixture['nonce'],
            $fixture['snapshot'],
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
        );
        $this->acknowledgeRollbackGuardianTransition();
        $terminal = $packages->completeRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('ROLLED_BACK', $terminal['phase']);
        self::assertDirectoryDoesNotExist($workingRoot);
    }

    public function testRollbackRejectsAnUnmanifestedWorkingGenerationEntry(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'working-generation-unmanifested',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $this->seedRebootstrapDerivedGeneration('working-unmanifested');
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        $this->assertRebootstrapCrash(
            static fn (): array => self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
            'after:OLD_GENERATION_STASHED',
        );
        $workingRoot = $this->paths->rebootstrapBackupDir($fixture['nonce'])
            . DIRECTORY_SEPARATOR . 'working-generation';
        self::assertTrue(\mkdir($workingRoot, 0700));
        self::assertNotFalse(\file_put_contents(
            $workingRoot . DIRECTORY_SEPARATOR . 'not-a-category',
            "untrusted\n",
        ));
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test unmanifested working-generation rejection',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected working-generation category');
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test unmanifested working-generation rejection',
        );
    }

    /** @return iterable<string,array{string}> */
    public static function linkedWorkingGenerationProvider(): iterable
    {
        yield 'root symlink' => ['root-symlink'];
        yield 'category symlink' => ['category-symlink'];
        yield 'leaf symlink' => ['leaf-symlink'];
        yield 'leaf hard link' => ['leaf-hard-link'];
    }

    #[DataProvider('linkedWorkingGenerationProvider')]
    public function testRollbackNeverFollowsWorkingGenerationLinks(
        string $shape,
    ): void {
        if (\PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped(
                'Windows link creation requires privileges unavailable to this fixture.',
            );
        }
        $fixture = $this->createStoppedRebootstrapFixture(
            'working-generation-' . \str_replace('-', '', $shape),
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $derived = $this->seedRebootstrapDerivedGeneration(
            'working-link-' . \str_replace('-', '', $shape),
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        $this->assertRebootstrapCrash(
            static fn (): array => self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
            'after:OLD_GENERATION_STASHED',
        );
        $workingRoot = $this->paths->rebootstrapBackupDir($fixture['nonce'])
            . DIRECTORY_SEPARATOR . 'working-generation';
        $sentinel = $this->root . DIRECTORY_SEPARATOR . 'working-link-sentinel-'
            . \str_replace('-', '', $shape);
        if (\hash_equals('root-symlink', $shape)) {
            self::assertTrue(\mkdir($sentinel, 0700));
            self::assertTrue(\symlink($sentinel, $workingRoot));
        } else {
            self::assertTrue(\mkdir($workingRoot, 0700));
            $category = $workingRoot . DIRECTORY_SEPARATOR . 'state';
            if (\hash_equals('category-symlink', $shape)) {
                self::assertTrue(\mkdir($sentinel, 0700));
                self::assertTrue(\symlink($sentinel, $category));
            } else {
                self::assertTrue(\mkdir($category, 0700));
                self::assertSame(8, \file_put_contents($sentinel, 'sentinel'));
                $leaf = $category . DIRECTORY_SEPARATOR
                    . $derived['state']['leaf'];
                self::assertTrue(\hash_equals('leaf-symlink', $shape)
                    ? \symlink($sentinel, $leaf)
                    : \link($sentinel, $leaf));
            }
        }
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test linked working-generation rejection',
        );

        $exception = $this->captureRuntimeException(
            static fn (): array => $packages->rollbackRebootstrapGeneration(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'test linked working-generation rejection',
            ),
        );
        self::assertStringContainsString('linked', $exception->getMessage());
        self::assertTrue(\file_exists($sentinel));
    }

    public function testRolledBackTerminalRefusesAReappearedWorkingGeneration(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'working-generation-terminal',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test terminal working-generation fence',
        );
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test terminal working-generation fence',
        );
        $platform->restoreRebootstrapDefinition(
            $fixture['nonce'],
            $fixture['snapshot'],
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
        );
        $this->acknowledgeRollbackGuardianTransition();
        $workingRoot = $this->paths->rebootstrapBackupDir($fixture['nonce'])
            . DIRECTORY_SEPARATOR . 'working-generation';
        self::assertTrue(\mkdir($workingRoot, 0700));

        $exception = $this->captureRuntimeException(
            static fn (): array => $packages->completeRebootstrapRollback(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            ),
        );
        self::assertStringContainsString(
            'working-generation must be absent',
            $exception->getMessage(),
        );
        self::assertSame(
            'ROLLBACK_OBSERVING',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );
        self::assertTrue(\rmdir($workingRoot));
        self::assertSame(
            'ROLLED_BACK',
            $packages->completeRebootstrapRollback(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            )['phase'],
        );
    }

    public function testCaRotationRefusesToMutateTheHostUnderDiskPressure(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'ca-disk-pressure',
            rotateTrust: true,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        $marker = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'disk-pressure.marker';
        self::assertNotFalse(\file_put_contents(
            $marker,
            "latched\n",
        ));
        self::assertTrue(\chmod($marker, 0600));
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );

        try {
            self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Disk pressure must fence a CA trust-generation move.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'blocked while disk pressure is latched',
                $exception->getMessage(),
            );
        }

        self::assertSame(
            'QUIESCED',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapDerivedManifestFile($fixture['nonce']),
        );
        self::assertSame(
            $fixture['old_active_slot'],
            $this->paths->activeSlot(),
        );
        self::assertSame(
            $fixture['old_launcher_digest'],
            \hash_file('sha256', $this->paths->launcherFile()),
        );
    }

    public function testCaRotationCommitReceiptProjectsTheNewEpochAndBindsItOnce(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'ca-commit-epoch',
            rotateTrust: true,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $this->seedRebootstrapDerivedGeneration('commit');
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $platform->refreshDefinition('default');
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'NEW_GENERATION_PUBLISHED',
            'PLATFORM_REFRESHED',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'PLATFORM_REFRESHED',
            'START_AUTHORIZED',
        );
        $injectedBeforeStart = $this->paths->stateDir()
            . DIRECTORY_SEPARATOR . 'injected-before-start.json';
        self::assertNotFalse(\file_put_contents(
            $injectedBeforeStart,
            "injected\n",
        ));
        self::assertTrue(\chmod($injectedBeforeStart, 0600));
        try {
            $packages->assertRebootstrapPreStartClosure(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('START_AUTHORIZED must reject injected live derived state.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'unexpected live derived state',
                $exception->getMessage(),
            );
        }
        self::assertTrue(\unlink($injectedBeforeStart));
        $authorization = $this->paths->rebootstrapStartAuthorizationFile();
        $authorizationContents = (string)\file_get_contents($authorization);
        self::assertNotSame('', $authorizationContents);
        self::assertTrue(\unlink($authorization));
        try {
            $packages->assertRebootstrapPreStartClosure(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('START_AUTHORIZED must require its preserved native authorization.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'pre-start authorization has unsafe authority or permissions',
                $exception->getMessage(),
            );
        }
        self::assertNotFalse(\file_put_contents(
            $authorization,
            $authorizationContents,
        ));
        self::assertTrue(\chmod($authorization, 0600));
        $tamperedAuthorization = \preg_replace_callback(
            '/signature=([a-f0-9])/',
            static fn (array $match): string => 'signature='
                . ($match[1] === '0' ? '1' : '0'),
            $authorizationContents,
            1,
        );
        self::assertIsString($tamperedAuthorization);
        self::assertNotSame($authorizationContents, $tamperedAuthorization);
        self::assertNotFalse(\file_put_contents(
            $authorization,
            $tamperedAuthorization,
        ));
        try {
            $packages->assertRebootstrapPreStartClosure(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('START_AUTHORIZED must authenticate its native authorization.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'start authorization authentication failed',
                $exception->getMessage(),
            );
        }
        self::assertNotFalse(\file_put_contents(
            $authorization,
            $authorizationContents,
        ));
        self::assertSame(
            'START_AUTHORIZED',
            $packages->assertRebootstrapPreStartClosure(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            )['phase'],
        );
        $observing = $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'START_AUTHORIZED',
            'OBSERVING',
            ['new_gateway_epoch' => $fixture['new_gateway_epoch']],
        );
        self::assertSame(
            $fixture['new_gateway_epoch'],
            $observing['new_gateway_epoch'],
        );
        self::assertSame(
            $fixture['new_gateway_epoch'],
            $packages->advanceRebootstrapPhase(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'START_AUTHORIZED',
                'OBSERVING',
                ['new_gateway_epoch' => $fixture['new_gateway_epoch']],
            )['new_gateway_epoch'],
        );
        $differentEpoch = \str_repeat(
            $fixture['new_gateway_epoch'][0] === '0' ? '1' : '0',
            32,
        );
        try {
            $packages->advanceRebootstrapPhase(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'START_AUTHORIZED',
                'OBSERVING',
                ['new_gateway_epoch' => $differentEpoch],
            );
            self::fail('A recovered transaction must not rebind its new epoch.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'changed during rebootstrap observation',
                $exception->getMessage(),
            );
        }
        $this->acknowledgeCommitGuardianTransition();

        $retainedLauncher = $this->paths->rebootstrapBackupDir(
            $fixture['nonce'],
        ) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'launcher';
        $retainedLauncherContents = (string)\file_get_contents(
            $retainedLauncher,
        );
        $retainedLauncherMode = (int)\fileperms($retainedLauncher) & 0777;
        self::assertTrue(\unlink($retainedLauncher));
        try {
            $packages->commitRebootstrap(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('COMMITTED must not publish without the complete old backup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'retained gateway rebootstrap launcher inventory differs',
                \strtolower($exception->getMessage()),
            );
        }
        self::assertSame(
            'OBSERVING',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );
        self::assertNotFalse(\file_put_contents(
            $retainedLauncher,
            $retainedLauncherContents,
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod(
                $retainedLauncher,
                $retainedLauncherMode,
            ));
        }
        $unexpectedBackupLeaf = $this->paths->rebootstrapBackupDir(
            $fixture['nonce'],
        ) . DIRECTORY_SEPARATOR . 'unexpected-leaf';
        self::assertNotFalse(\file_put_contents(
            $unexpectedBackupLeaf,
            "unexpected\n",
        ));
        self::assertTrue(\chmod($unexpectedBackupLeaf, 0600));
        try {
            $packages->commitRebootstrap(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('COMMITTED must reject an extra backup inventory leaf.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'backup root differs from the signed transaction closure',
                $exception->getMessage(),
            );
        }
        self::assertTrue(\unlink($unexpectedBackupLeaf));
        self::assertSame(
            'OBSERVING',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );

        $terminal = $packages->commitRebootstrap(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('COMMITTED', $terminal['phase']);
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($fixture['nonce']),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(4, $receipt['schema_version']);
        self::assertTrue($receipt['trust_rotation']);
        self::assertSame('RETAINED', $receipt['retained_backup_state']);
        self::assertSame('RETAINED', $terminal['retained_backup_state']);
        self::assertSame('', $receipt['backup_collection_nonce']);
        self::assertSame('', $receipt['backup_collection_device']);
        self::assertSame('', $receipt['backup_collection_inode']);
        self::assertSame($fixture['gateway_epoch'], $receipt['old_gateway_epoch']);
        self::assertSame(
            $fixture['new_gateway_epoch'],
            $receipt['new_gateway_epoch'],
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $receipt['old_derived_manifest_sha256'],
        );

        $terminalResult = new \ReflectionMethod(
            GatewayHostManager::class,
            'rebootstrapTerminalResult',
        );
        $manager = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );
        $public = $terminalResult->invoke($manager, $terminal);
        self::assertTrue($public['accepted']);
        self::assertFalse($public['gateway_epoch_preserved']);
        self::assertSame(
            $fixture['new_gateway_epoch'],
            $public['gateway_epoch'],
        );
        self::assertSame(
            $fixture['new_gateway_epoch'],
            $public['active_gateway_epoch'],
        );

        $replayed = $packages->commitRebootstrap(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('COMMITTED', $replayed['phase']);
        self::assertSame('RETAINED', $replayed['retained_backup_state']);

        $this->removeTree(
            $this->paths->rebootstrapBackupDir($fixture['nonce']),
        );
        try {
            $packages->commitRebootstrap(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('A COMMITTED receipt must reject a missing retained backup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'Retained gateway rebootstrap backup is missing',
                $exception->getMessage(),
            );
        }
    }

    public function testRebootstrapRollbackRestoresWholeGenerationAndEpochContract(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture('rollback');
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        $published = self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('NEW_GENERATION_PUBLISHED', $published['phase']);
        self::assertNotSame(
            $fixture['old_launcher_digest'],
            \hash_file('sha256', $this->paths->launcherFile()),
        );

        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test rollback',
        );
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test rollback',
        );
        $platform->restoreRebootstrapDefinition(
            $fixture['nonce'],
            $fixture['snapshot'],
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
        );
        $packages->assertRebootstrapRollbackPreStartClosure(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
        );
        $this->acknowledgeRollbackGuardianTransition();
        $terminal = $packages->completeRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );

        self::assertSame('ROLLED_BACK', $terminal['phase']);
        self::assertSame('RETAINED', $terminal['retained_backup_state']);
        self::assertSame(
            $fixture['old_launcher_digest'],
            \hash_file('sha256', $this->paths->launcherFile()),
        );
        self::assertSame($fixture['old_active_slot'], $this->paths->activeSlot());
        self::assertFileExists($this->paths->adminStoppedIntentFile());
        $terminalResult = new \ReflectionMethod(
            GatewayHostManager::class,
            'rebootstrapTerminalResult',
        );
        $manager = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );
        $public = $terminalResult->invoke($manager, $terminal);
        self::assertIsArray($public);
        self::assertTrue($public['gateway_epoch_preserved']);
        self::assertFalse($public['accepted']);
        $packages->assertNoActiveRebootstrap('test rollback cleanup');
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapBackupDir($fixture['nonce']),
        );
        $rollbackReceipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($fixture['nonce']),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame(
            'COLLECTED',
            $rollbackReceipt['retained_backup_state'],
        );
    }

    /** @return iterable<string,array{string}> */
    public static function restoredOldDerivedLiveDriftProvider(): iterable
    {
        yield 'injected leaf' => ['injected'];
        yield 'changed leaf' => ['changed'];
        yield 'missing leaf' => ['missing'];
    }

    #[DataProvider('restoredOldDerivedLiveDriftProvider')]
    public function testRollbackStartAuthorizationRejectsRestoredOldDerivedLiveDrift(
        string $shape,
    ): void {
        $fixture = $this->createStoppedRebootstrapFixture(
            'rollback-live-' . $shape,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $derived = $this->seedRebootstrapDerivedGeneration(
            'rollback-live-' . $shape,
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test restored old derived live drift',
        );
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test restored old derived live drift',
        );
        $platform->restoreRebootstrapDefinition(
            $fixture['nonce'],
            $fixture['snapshot'],
        );

        if (\hash_equals('injected', $shape)) {
            $injected = $this->paths->stateDir() . DIRECTORY_SEPARATOR
                . 'injected-after-rollback.dat';
            self::assertNotFalse(\file_put_contents($injected, "injected\n"));
            self::assertTrue(\chmod($injected, 0600));
        } elseif (\hash_equals('changed', $shape)) {
            self::assertNotFalse(\file_put_contents(
                $derived['state']['payload_path'],
                "changed-after-rollback\n",
            ));
        } else {
            self::assertTrue(\unlink($derived['state']['payload_path']));
        }

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->advanceRebootstrapPhase(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'ROLLING_BACK',
                'ROLLBACK_START_AUTHORIZED',
            ),
        );
        self::assertStringContainsString(
            \hash_equals('changed', $shape)
                ? 'closure changed'
                : 'inventory differs',
            $exception->getMessage(),
        );
        self::assertSame(
            'ROLLING_BACK',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );
    }

    public function testRollbackPreStartDerivedInventoryIgnoresPreservedControlStateAndRevalidates(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'rollback-live-preserved',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $derived = $this->seedRebootstrapDerivedGeneration(
            'rollback-live-preserved',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test preserved rollback controls',
        );
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test preserved rollback controls',
        );
        $platform->restoreRebootstrapDefinition(
            $fixture['nonce'],
            $fixture['snapshot'],
        );
        $reserve = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . 'recovery.reserve';
        $reserveContents = "preserved-after-rollback\n";
        self::assertNotFalse(\file_put_contents($reserve, $reserveContents));
        self::assertTrue(\chmod($reserve, 0600));
        self::assertSame(
            $fixture['admin_stopped_intent'],
            \file_get_contents($this->paths->adminStoppedIntentFile()),
        );

        $authorized = $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
        );
        self::assertSame('ROLLBACK_START_AUTHORIZED', $authorized['phase']);
        $packages->assertRebootstrapRollbackPreStartClosure(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame($reserveContents, \file_get_contents($reserve));
        self::assertSame(
            $fixture['admin_stopped_intent'],
            \file_get_contents($this->paths->adminStoppedIntentFile()),
        );

        self::assertNotFalse(\file_put_contents(
            $derived['state']['payload_path'],
            "changed-after-authorization\n",
        ));
        $exception = $this->captureRuntimeException(
            fn (): array => $packages
                ->assertRebootstrapRollbackPreStartClosure(
                    $fixture['nonce'],
                    $fixture['package_digest'],
                    'default',
                ),
        );
        self::assertStringContainsString(
            'closure changed',
            $exception->getMessage(),
        );
    }

    public function testRollbackStartAuthorizationRejectsRecreatedOriginallyAbsentDerivedRoot(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'rollback-live-root-presence',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $derived = $this->seedRebootstrapDerivedGeneration(
            'rollback-live-root-presence',
        );
        $shadowRoot = $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow';
        self::assertTrue(\unlink(
            $derived['runtime-shadow']['top_level_path'],
        ));
        self::assertTrue(\rmdir($shadowRoot));
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->beginRebootstrapRollback(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test restored old derived root presence',
        );
        $packages->rollbackRebootstrapGeneration(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test restored old derived root presence',
        );
        $platform->restoreRebootstrapDefinition(
            $fixture['nonce'],
            $fixture['snapshot'],
        );
        self::assertDirectoryDoesNotExist($shadowRoot);
        self::assertTrue(\mkdir($shadowRoot, 0700));

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->advanceRebootstrapPhase(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'ROLLING_BACK',
                'ROLLBACK_START_AUTHORIZED',
            ),
        );
        self::assertStringContainsString(
            'root runtime-shadow authority or identity changed',
            $exception->getMessage(),
        );
        self::assertSame(
            'ROLLING_BACK',
            $packages->rebootstrapStatus($fixture['nonce'])['phase'],
        );
    }

    public function testRebootstrapCrashReentryCompletesStashAndTerminalReceipt(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture('crash');
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        \putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT=after:OLD_GENERATION_STASHED'
        );
        try {
            self::publishRebootstrapGenerationAfterCapacityRelease(
                $packages,
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('The persisted stash boundary must simulate a hard crash.');
        } catch (GatewayRebootstrapCrashSimulation) {
            self::assertSame(
                'OLD_GENERATION_STASHED',
                $packages->rebootstrapStatus($fixture['nonce'])['phase'],
            );
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $platform->refreshDefinition('default');
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'NEW_GENERATION_PUBLISHED',
            'PLATFORM_REFRESHED',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'PLATFORM_REFRESHED',
            'START_AUTHORIZED',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'START_AUTHORIZED',
            'OBSERVING',
            ['new_gateway_epoch' => $fixture['new_gateway_epoch']],
        );
        $this->acknowledgeCommitGuardianTransition();
        \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT=after:COMMITTED');
        try {
            $packages->commitRebootstrap(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('The terminal journal boundary must simulate a hard crash.');
        } catch (GatewayRebootstrapCrashSimulation) {
            $committed = $packages->rebootstrapStatus($fixture['nonce']);
            self::assertSame('COMMITTED', $committed['phase']);
            self::assertSame(
                'RETAINED',
                $committed['retained_backup_state'],
            );
            self::assertFileDoesNotExist(
                $this->paths->rebootstrapReceiptFile($fixture['nonce']),
            );
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $receiptStaging = $this->paths->rebootstrapReceiptFile(
            $fixture['nonce'],
        ) . '.tmp-' . \str_repeat('a', 24);
        self::assertNotFalse(\file_put_contents(
            $receiptStaging,
            "uncommitted-first-publication\n",
        ));
        self::assertTrue(\chmod($receiptStaging, 0600));
        $terminal = $packages->commitRebootstrap(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertSame('COMMITTED', $terminal['phase']);
        self::assertSame('RETAINED', $terminal['retained_backup_state']);
        self::assertNull($packages->rebootstrapStatus($fixture['nonce']));
        self::assertFileDoesNotExist($receiptStaging);
        self::assertFileExists(
            $this->paths->rebootstrapReceiptFile($fixture['nonce']),
        );
    }

    public function testRebootstrapRegularFileMoveCrashReplayMatrix(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $reconcile = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'reconcileRebootstrapFileMove',
        );
        $directory = $this->root . DIRECTORY_SEPARATOR . 'file-move-replay';
        self::assertTrue(\mkdir($directory, 0700, true));
        $contents = "signed-old-generation\n";
        $digest = \hash('sha256', $contents);
        $size = \strlen($contents);

        $source = $directory . DIRECTORY_SEPARATOR . 'source-only';
        $destination = $directory . DIRECTORY_SEPARATOR . 'destination-only';
        self::assertNotFalse(\file_put_contents($source, $contents));
        self::assertTrue(\chmod($source, 0600));
        $reconcile->invoke(
            $packages,
            $source,
            $destination,
            $digest,
            $size,
            0600,
            'test old-generation file',
        );
        self::assertFileDoesNotExist($source);
        self::assertSame($contents, \file_get_contents($destination));

        // Re-entry after the durable rename observes only the destination and
        // must validate it without attempting another move.
        $reconcile->invoke(
            $packages,
            $source,
            $destination,
            $digest,
            $size,
            0600,
            'test old-generation file',
        );
        self::assertFileDoesNotExist($source);
        self::assertSame($contents, \file_get_contents($destination));

        // Windows MoveFileEx can durably expose both names for one regular
        // identity. The replay contract accepts only that exact alias and
        // finishes by removing the source name.
        $aliasSource = $directory . DIRECTORY_SEPARATOR . 'alias-source';
        $aliasDestination = $directory . DIRECTORY_SEPARATOR
            . 'alias-destination';
        self::assertNotFalse(\file_put_contents($aliasSource, $contents));
        self::assertTrue(\chmod($aliasSource, 0600));
        self::assertTrue(\link($aliasSource, $aliasDestination));
        $reconcile->invoke(
            $packages,
            $aliasSource,
            $aliasDestination,
            $digest,
            $size,
            0600,
            'test aliased old-generation file',
        );
        self::assertFileDoesNotExist($aliasSource);
        self::assertSame($contents, \file_get_contents($aliasDestination));

        $differentSource = $directory . DIRECTORY_SEPARATOR
            . 'different-source';
        $differentDestination = $directory . DIRECTORY_SEPARATOR
            . 'different-destination';
        self::assertNotFalse(\file_put_contents($differentSource, $contents));
        self::assertNotFalse(\file_put_contents(
            $differentDestination,
            $contents,
        ));
        self::assertTrue(\chmod($differentSource, 0600));
        self::assertTrue(\chmod($differentDestination, 0600));
        try {
            $reconcile->invoke(
                $packages,
                $differentSource,
                $differentDestination,
                $digest,
                $size,
                0600,
                'test split old-generation file',
            );
            self::fail('Two distinct file identities must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'exposes two different move identities',
                $exception->getMessage(),
            );
        }

        try {
            $reconcile->invoke(
                $packages,
                $directory . DIRECTORY_SEPARATOR . 'missing-source',
                $directory . DIRECTORY_SEPARATOR . 'missing-destination',
                $digest,
                $size,
                0600,
                'test missing old-generation file',
            );
            self::fail('A file absent from both move locations must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'missing from both transaction locations',
                $exception->getMessage(),
            );
        }
    }

    public function testRebootstrapRuntimeDirectoryMoveCrashReplayMatrix(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $staged = $packages->stage(
            $this->createPackage('directory-move-replay'),
            'default',
        );
        $reconcile = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'reconcileRebootstrapDirectoryMove',
        );
        $directory = $this->root . DIRECTORY_SEPARATOR
            . 'directory-move-replay';
        self::assertTrue(\mkdir($directory, 0700, true));
        $source = (string)$staged['slot_dir'];
        $destination = $directory . DIRECTORY_SEPARATOR . 'stored-slot';
        $generation = (string)$staged['runtime_generation'];

        $reconcile->invoke(
            $packages,
            $source,
            $destination,
            $generation,
            'test old-generation directory',
        );
        self::assertDirectoryDoesNotExist($source);
        self::assertDirectoryExists($destination);

        // Destination-only is the normal post-rename replay state.
        $reconcile->invoke(
            $packages,
            $source,
            $destination,
            $generation,
            'test old-generation directory',
        );
        self::assertDirectoryDoesNotExist($source);
        self::assertDirectoryExists($destination);

        $differentSource = $directory . DIRECTORY_SEPARATOR
            . 'different-source';
        $differentDestination = $directory . DIRECTORY_SEPARATOR
            . 'different-destination';
        self::assertTrue(\mkdir($differentSource, 0700));
        self::assertTrue(\mkdir($differentDestination, 0700));
        try {
            $reconcile->invoke(
                $packages,
                $differentSource,
                $differentDestination,
                $generation,
                'test split old-generation directory',
            );
            self::fail('Two directory identities must fail closed.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'exists at two different transaction identities',
                $exception->getMessage(),
            );
        }

        try {
            $reconcile->invoke(
                $packages,
                $directory . DIRECTORY_SEPARATOR . 'missing-source',
                $directory . DIRECTORY_SEPARATOR . 'missing-destination',
                $generation,
                'test missing old-generation directory',
            );
            self::fail(
                'A directory absent from both move locations must fail closed.',
            );
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'missing from both transaction locations',
                $exception->getMessage(),
            );
        }
    }

    public function testRebootstrapCollectionSegmentsTheMaximumRuntimeTreeDepthAndEntryBudget(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $collect = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'collectRebootstrapBackupRemovalRecords',
        );
        $remove = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'removeCollectedTree',
        );
        $root = $this->root . DIRECTORY_SEPARATOR
            . 'maximum-rebootstrap-collection';
        $slot = $root . DIRECTORY_SEPARATOR . 'slots'
            . DIRECTORY_SEPARATOR . 'A';
        self::assertTrue(\mkdir($slot, 0700, true));

        // Runtime paths are independently allowed to reach depth 64. Walking
        // from the collection wrapper would incorrectly count slots/A as two
        // extra levels, so the removal preflight must segment at the slot.
        $cursor = $slot;
        for ($depth = 1; $depth <= HostGatewayPackageManager::MAX_PACKAGE_PATH_DEPTH; ++$depth) {
            $cursor .= DIRECTORY_SEPARATOR . 'd';
            if (!\mkdir($cursor, 0700)) {
                self::fail('Unable to create the maximum-depth runtime fixture.');
            }
        }
        $treeEntryBudget = HostGatewayPackageManager::MAX_PACKAGE_COMPONENTS
            + HostGatewayPackageManager::MAX_PACKAGE_DIRECTORIES + 4;
        $flatEntries = $treeEntryBudget
            - HostGatewayPackageManager::MAX_PACKAGE_PATH_DEPTH;
        for ($index = 0; $index < $flatEntries; ++$index) {
            $path = $slot . DIRECTORY_SEPARATOR . 'f'
                . \str_pad((string)$index, 5, '0', STR_PAD_LEFT);
            if (\file_put_contents($path, '') === false) {
                self::fail('Unable to create the maximum-entry runtime fixture.');
            }
        }

        /** @var list<array<string,mixed>> $records */
        $records = $collect->invoke(
            $packages,
            $root,
            [
                'phase' => 'COMMITTED',
                'trust_rotation' => false,
            ],
            'test maximum retained backup collection',
        );
        self::assertCount($treeEntryBudget + 3, $records);
        self::assertTrue(\in_array($cursor, \array_column($records, 'path'), true));
        self::assertTrue(\in_array($slot, \array_column($records, 'path'), true));
        self::assertTrue(\in_array(
            $root . DIRECTORY_SEPARATOR . 'slots',
            \array_column($records, 'path'),
            true,
        ));
        self::assertSame($root, $records[\array_key_last($records)]['path']);

        $remove->invoke($packages, $root, $records);
        self::assertDirectoryDoesNotExist($root);
    }

    public function testRebootstrapRetentionReanchorsAcrossBootBeforeCollection(): void
    {
        $wall = 1_900_000_000;
        $monotonic = 1_000;
        $boot = \str_repeat('a', 64);
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $packages = new HostGatewayPackageManager(
            paths: $this->paths,
            platform: $platform,
            wallClock: static function () use (&$wall): int {
                return $wall;
            },
            monotonicClockMilliseconds: static function () use (&$monotonic): int {
                return $monotonic;
            },
            bootIdentity: static function () use (&$boot): string {
                return $boot;
            },
        );
        $fixture = $this->createStoppedRebootstrapFixture(
            'retention',
            $packages,
            $platform,
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $platform->refreshDefinition('default');
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'NEW_GENERATION_PUBLISHED',
            'PLATFORM_REFRESHED',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'PLATFORM_REFRESHED',
            'START_AUTHORIZED',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'START_AUTHORIZED',
            'OBSERVING',
            ['new_gateway_epoch' => $fixture['new_gateway_epoch']],
        );
        $this->acknowledgeCommitGuardianTransition();
        $packages->commitRebootstrap(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $backup = $this->paths->rebootstrapBackupDir($fixture['nonce']);
        self::assertDirectoryExists($backup);
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($fixture['nonce']),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('RETAINED', $receipt['retained_backup_state']);

        $boot = \str_repeat('b', 64);
        $monotonic = 50;
        ++$wall;
        $packages->assertNoActiveRebootstrap('test cross-boot reanchor');
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($fixture['nonce']),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($boot, $receipt['retention_host_boot_id']);
        self::assertSame(50, $receipt['retained_monotonic_ms']);
        self::assertSame(86_400_050, $receipt['retention_deadline_monotonic_ms']);
        self::assertDirectoryExists($backup);

        $monotonic = 86_400_051;
        $beginCollection = new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'beginRebootstrapBackupCollectionLocked',
        );
        $receipt = $beginCollection->invoke($packages, $receipt);
        self::assertSame('RETAINED', $receipt['retained_backup_state']);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{32}\z/D',
            $receipt['backup_collection_nonce'],
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{1,32}\z/D',
            $receipt['backup_collection_device'],
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{1,32}\z/D',
            $receipt['backup_collection_inode'],
        );
        $collecting = $this->paths->rebootstrapCollectedBackupDir(
            $fixture['nonce'],
            $receipt['backup_collection_nonce'],
        );
        self::assertTrue(\rename($backup, $collecting));
        $workspaceInventory = new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'windowsRebootstrapWorkspaceInventory',
        );
        $workspace = $workspaceInventory->invoke(
            new GatewayPlatformServiceInstaller($this->paths),
        );
        self::assertArrayHasKey(
            $fixture['nonce'],
            $workspace['collecting_backups'],
        );
        self::assertSame(
            $receipt['backup_collection_nonce'],
            $workspace['collecting_backups'][$fixture['nonce']]['collection_nonce'],
        );
        self::assertSame(
            $receipt['backup_collection_device'],
            $workspace['collecting_backups'][$fixture['nonce']]['record']['device'],
        );
        self::assertSame(
            $receipt['backup_collection_inode'],
            $workspace['collecting_backups'][$fixture['nonce']]['record']['inode'],
        );
        self::assertArrayNotHasKey(
            $fixture['nonce'],
            $workspace['candidate_locks'],
        );
        $receiptFile = $this->paths->rebootstrapReceiptFile($fixture['nonce']);
        $receiptBytes = (string)\file_get_contents($receiptFile);
        $tamperedReceipt = \str_replace(
            '"retained_backup_state":"RETAINED"',
            '"retained_backup_state":"COLLECTED"',
            $receiptBytes,
        );
        self::assertNotSame($receiptBytes, $tamperedReceipt);
        self::assertNotFalse(\file_put_contents($receiptFile, $tamperedReceipt));
        $exception = $this->captureRuntimeException(
            fn (): array => $workspaceInventory->invoke(
                new GatewayPlatformServiceInstaller($this->paths),
            ),
        );
        self::assertStringContainsString('authentication failed', $exception->getMessage());
        self::assertNotFalse(\file_put_contents($receiptFile, $receiptBytes));

        $savedCollectionRoot = $this->root . DIRECTORY_SEPARATOR
            . 'saved-collection-root';
        self::assertTrue(\rename($collecting, $savedCollectionRoot));
        self::assertTrue(\mkdir($collecting, 0700));
        $exception = $this->captureRuntimeException(
            fn (): array => $workspaceInventory->invoke(
                new GatewayPlatformServiceInstaller($this->paths),
            ),
        );
        self::assertStringContainsString(
            'does not match its authenticated terminal receipt',
            $exception->getMessage(),
        );
        try {
            $packages->assertNoActiveRebootstrap(
                'test collection root identity replacement',
            );
            self::fail('Collection recovery must reject a replaced root inode.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'collection root identity changed',
                $exception->getMessage(),
            );
        }
        self::assertTrue(\rmdir($collecting));
        self::assertTrue(\rename($savedCollectionRoot, $collecting));

        $receipt['retained_backup_state'] = 'COLLECTED';
        $receipt = (new \ReflectionMethod(
            HostGatewayPackageManager::class,
            'writeRebootstrapReceiptLocked',
        ))->invoke($packages, $receipt);
        self::assertSame('COLLECTED', $receipt['retained_backup_state']);
        $partiallyCollected = $collecting . DIRECTORY_SEPARATOR . 'bin'
            . DIRECTORY_SEPARATOR . 'launcher';
        self::assertTrue(\chmod($partiallyCollected, 0600));
        self::assertTrue(\unlink($partiallyCollected));

        $packages->assertNoActiveRebootstrap(
            'test partially collected backup replay',
        );
        self::assertDirectoryDoesNotExist($backup);
        self::assertDirectoryDoesNotExist($collecting);
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($fixture['nonce']),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('COLLECTED', $receipt['retained_backup_state']);
        self::assertSame('', $receipt['backup_collection_nonce']);
        self::assertSame('', $receipt['backup_collection_device']);
        self::assertSame('', $receipt['backup_collection_inode']);
    }

    public function testTerminalRebootstrapReceiptGcRetainsNewestAndReplaysMovedAlias(): void
    {
        [$packages, $template] = $this->collectedRollbackReceiptTemplate(
            'receipt-gc-replay',
        );
        $records = $this->seedCollectedRebootstrapReceipts(
            $template,
            1025,
        );
        $oldest = $records[0];
        $newest = $records[1024];

        \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT=receipt-gc:after-move');
        try {
            $packages->assertNoActiveRebootstrap('test receipt GC move crash');
            self::fail('Receipt GC must expose its durable move crash boundary.');
        } catch (GatewayRebootstrapCrashSimulation $simulation) {
            self::assertStringContainsString(
                'receipt-gc:after-move',
                $simulation->getMessage(),
            );
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }

        $alias = $this->paths->rebootstrapReceiptGcFile(
            $oldest['nonce'],
            $oldest['sha256'],
        );
        self::assertFileDoesNotExist($oldest['path']);
        self::assertFileExists($alias);
        $workspace = (new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'windowsRebootstrapWorkspaceInventory',
        ))->invoke(new GatewayPlatformServiceInstaller($this->paths));
        self::assertArrayHasKey(\basename($alias), $workspace['receipts']);

        $packages->assertNoActiveRebootstrap('test receipt GC alias replay');
        self::assertFileDoesNotExist($alias);
        self::assertFileDoesNotExist($oldest['path']);
        self::assertFileExists($newest['path']);
        self::assertCount(
            1024,
            \glob(
                $this->paths->rebootstrapReceiptsDir()
                    . DIRECTORY_SEPARATOR . '*.json',
            ) ?: [],
        );
    }

    public function testTerminalReceiptGcUsesImmutableTerminalOrderAfterMaintenance(): void
    {
        [$packages, $template] = $this->collectedRollbackReceiptTemplate(
            'receipt-gc-terminal-order',
        );
        $records = $this->seedCollectedRebootstrapReceipts($template, 1025);
        $oldest = $records[0];
        $maintained = $this->signedCollectedRebootstrapReceipt(
            $template,
            $oldest['nonce'],
            $records[1024]['updated_at'] + 10_000,
            null,
            $oldest['terminal_at'],
        );
        self::assertSame($oldest['terminal_at'], $maintained['terminal_at']);
        self::assertGreaterThan(
            $records[1024]['updated_at'],
            $maintained['updated_at'],
        );
        self::assertNotFalse(\file_put_contents(
            $oldest['path'],
            $maintained['contents'],
        ));
        self::assertTrue(\chmod($oldest['path'], 0600));

        $packages->assertNoActiveRebootstrap(
            'test immutable terminal receipt order',
        );
        self::assertFileDoesNotExist($oldest['path']);
        self::assertFileExists($records[1]['path']);
        self::assertFileExists($records[1024]['path']);
    }

    public function testReceiptGcWallClockRollbackIsDeterministicAuditBestEffort(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $inventory = ['records' => [], 'receipts' => []];
        $rolledBackClockNonce = '';
        for ($index = 0; $index < 1025; ++$index) {
            $nonce = \hash('md5', 'receipt-wall-order-' . $index);
            $terminalAt = $index === 1024 ? 1 : 100 + $index;
            $record = [
                'nonce' => $nonce,
                'leaf' => $nonce . '.json',
                'receipt' => [
                    'terminal_at' => $terminalAt,
                    'retained_backup_state' => 'COLLECTED',
                    'backup_collection_nonce' => '',
                    'backup_collection_device' => '',
                    'backup_collection_inode' => '',
                    'capacity_evidence_state' => 'COLLECTED',
                ],
            ];
            $inventory['receipts'][$nonce] = [
                'canonical' => $record,
                'companions' => [],
                'alias' => null,
            ];
            if ($index === 1024) {
                $rolledBackClockNonce = $nonce;
            }
        }
        $plan = (new \ReflectionMethod(
            $packages,
            'rebootstrapReceiptGcPlan',
        ))->invoke(
            $packages,
            $inventory,
            [
                'journal_blocked' => false,
                'backups' => [],
                'collecting' => [],
                'candidates' => [],
                'candidate_locks' => [],
                'capacity_live' => [],
            ],
        );
        self::assertIsArray($plan);
        self::assertCount(1, $plan);
        self::assertArrayHasKey(
            $rolledBackClockNonce . '.json',
            $plan,
        );
    }

    public function testTerminalCapacityEvidenceGcReplaysReleasedDigestAlias(): void
    {
        [$packages, $fixture] = $this
            ->terminalRollbackWithRetainedCapacityEvidence(
                'capacity-evidence-gc-replay',
            );
        $nonce = $fixture['nonce'];
        $released = $this->paths->rebootstrapCapacityReleasedReceiptFile($nonce);
        $releasedBytes = (string)\file_get_contents($released);
        self::assertNotSame('', $releasedBytes);
        $alias = $this->paths->rebootstrapCapacityReleasedGcFile(
            $nonce,
            \hash('sha256', $releasedBytes),
        );

        \putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-evidence-gc:after-released-move',
        );
        try {
            $packages->assertNoActiveRebootstrap(
                'test capacity evidence GC move crash',
            );
            self::fail(
                'Capacity evidence GC must expose its durable move boundary.',
            );
        } catch (GatewayRebootstrapCrashSimulation $simulation) {
            self::assertStringContainsString(
                'capacity-evidence-gc:after-released-move',
                $simulation->getMessage(),
            );
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        self::assertFileDoesNotExist($released);
        self::assertFileExists($alias);
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($nonce),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('COLLECTING', $receipt['capacity_evidence_state']);

        $packages->assertNoActiveRebootstrap(
            'test capacity evidence GC alias replay',
        );
        self::assertFileDoesNotExist($alias);
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityHeldManifestFile($nonce),
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingReceiptFile($nonce),
        );
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($nonce),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('COLLECTED', $receipt['capacity_evidence_state']);
    }

    public function testLiveCapacityNamespaceBlocksTerminalReceiptGc(): void
    {
        [$packages, $fixture] = $this
            ->terminalRollbackWithRetainedCapacityEvidence(
                'capacity-evidence-live-block',
            );
        $nonce = $fixture['nonce'];
        $live = $this->paths->rebootstrapCapacityDir()
            . DIRECTORY_SEPARATOR . $nonce . '.allocating';
        self::assertTrue(\mkdir($live, 0700));

        $packages->assertNoActiveRebootstrap(
            'test live capacity namespace block',
        );
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($nonce),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('RETAINED', $receipt['capacity_evidence_state']);
        self::assertDirectoryExists($live);
        self::assertFileExists(
            $this->paths->rebootstrapCapacityReleasedReceiptFile($nonce),
        );

        self::assertTrue(\rmdir($live));
        $packages->assertNoActiveRebootstrap(
            'test capacity namespace resumes after live reserve clears',
        );
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($nonce),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('COLLECTED', $receipt['capacity_evidence_state']);
    }

    public function testAllocatingCancellationCapacityGcAcceptsReleasedOnlyEvidence(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-allocating-cancel',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        \putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation $simulation) {
            self::assertStringContainsString(
                'capacity-reserve:after-allocating-journal',
                $simulation->getMessage(),
            );
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $packages->cancelPreparedRebootstrap(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'test incomplete allocation cancellation',
        );
        $packages->completePreparedRebootstrapCancellation(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityHeldManifestFile(
                $fixture['nonce'],
            ),
            'A PHP-only ALLOCATING journal has no native HELD authority to bind or reallocate during cancellation.',
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingReceiptFile(
                $fixture['nonce'],
            ),
        );
        self::assertFileExists(
            $this->paths->rebootstrapCapacityReleasedReceiptFile(
                $fixture['nonce'],
            ),
        );
        $packages->assertNoActiveRebootstrap(
            'test released-only capacity evidence collection',
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityHeldManifestFile(
                $fixture['nonce'],
            ),
        );
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($fixture['nonce']),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('COLLECTED', $receipt['capacity_evidence_state']);
    }

    public function testAllocatingCancellationCleansAnExactPartialNativeAllocationWithoutBindingIt(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-partial-allocating-cancel',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation $simulation) {
            self::assertStringContainsString(
                'capacity-reserve:after-allocating-journal',
                $simulation->getMessage(),
            );
        } finally {
            putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $partial = $this->paths->rebootstrapCapacityDir()
            . DIRECTORY_SEPARATOR . $fixture['nonce'] . '.allocating';
        self::assertTrue(mkdir($partial, 0700));

        $released = $packages->releaseRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'cancel',
        );

        self::assertSame('RELEASED', $released['capacity_reserve_state']);
        self::assertDirectoryDoesNotExist($partial);
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityHeldManifestFile(
                $fixture['nonce'],
            ),
            'A partial exact native allocation is cleanup-only authority and must never acquire a HELD manifest.',
        );
    }

    /** @return iterable<string,array{string}> */
    public static function allocatingCancellationUnsafeNativeStateProvider(): iterable
    {
        yield 'releasing' => ['releasing'];
        yield 'conflict' => ['conflict'];
    }

    #[DataProvider('allocatingCancellationUnsafeNativeStateProvider')]
    public function testAllocatingCancellationFailsClosedForReleasingOrConflictingNativeState(
        string $shape,
    ): void {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-inspect-' . $shape,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation) {
            // The PHP journal is durably ALLOCATING while native state is
            // now shaped below to exercise the inspect contract.
        } finally {
            putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $capacity = $this->paths->rebootstrapCapacityDir();
        if ($shape === 'releasing') {
            self::assertTrue(mkdir(
                $capacity . DIRECTORY_SEPARATOR
                    . $fixture['nonce'] . '.releasing',
                0700,
            ));
        } else {
            self::assertTrue(mkdir(
                $capacity . DIRECTORY_SEPARATOR
                    . $fixture['nonce'] . '.allocating',
                0700,
            ));
            self::assertTrue(mkdir(
                $capacity . DIRECTORY_SEPARATOR
                    . $fixture['nonce'] . '.held',
                0700,
            ));
        }

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->releaseRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'cancel',
            ),
        );
        self::assertStringContainsString(
            'native capacity inspect',
            $exception->getMessage(),
            $shape,
        );
        self::assertSame(
            'ALLOCATING',
            $packages->rebootstrapStatus($fixture['nonce'])['capacity_reserve_state'],
            $shape,
        );
    }

    public function testAllocatingCancellationFailsClosedForForeignNativeState(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-inspect-foreign',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation) {
            // The PHP journal is durably ALLOCATING while a foreign leaf is
            // introduced below to exercise the inspect contract.
        } finally {
            putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $foreign = $this->paths->rebootstrapCapacityDir()
            . DIRECTORY_SEPARATOR . $fixture['nonce'] . '.allocating';
        self::assertSame(7, \file_put_contents($foreign, 'foreign'));

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->releaseRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'cancel',
            ),
        );
        self::assertStringContainsString(
            'native capacity inspect',
            $exception->getMessage(),
        );
        self::assertFileExists($foreign);
        self::assertSame(
            'ALLOCATING',
            $packages->rebootstrapStatus($fixture['nonce'])['capacity_reserve_state'],
        );
    }

    public function testAllocatingCancellationRejectsInspectSchemaDriftWithoutCleanup(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-inspect-schema-drift',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        \putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation) {
            // Leave a PHP-only ALLOCATING journal for the native inspect.
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $malformed = $this->paths->stateDir() . DIRECTORY_SEPARATOR
            . $fixture['nonce'] . '.inspect-malformed';
        self::assertSame(1, \file_put_contents($malformed, '1'));

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->releaseRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'cancel',
            ),
        );

        self::assertStringContainsString(
            'violates wls-capacity-inspect/1',
            $exception->getMessage(),
        );
        self::assertSame(
            'ALLOCATING',
            $packages->rebootstrapStatus($fixture['nonce'])['capacity_reserve_state'],
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityReleasedReceiptFile(
                $fixture['nonce'],
            ),
        );
    }

    public function testAllocatingCancellationReplaysNativeNoneAfterCleanupCrash(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-unbound-cleanup-crash',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        \putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation) {
            // PHP is ALLOCATING and native capacity is absent.
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $this->assertRebootstrapCrash(
            fn (): array => $packages->releaseRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'cancel',
            ),
            'capacity-reserve:after-native-unbound-cancel-before-released-journal',
        );
        $afterNativeCleanup = $packages->rebootstrapStatus($fixture['nonce']);
        self::assertIsArray($afterNativeCleanup);
        self::assertSame('ALLOCATING', $afterNativeCleanup['capacity_reserve_state']);
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityHeldManifestFile(
                $fixture['nonce'],
            ),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityHeldDir($fixture['nonce']),
        );
        self::assertDirectoryDoesNotExist(
            $this->paths->rebootstrapCapacityReleasingDir($fixture['nonce']),
        );

        $released = $packages->releaseRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'cancel',
        );
        self::assertSame('RELEASED', $released['capacity_reserve_state']);
        self::assertSame('', $released['capacity_reserve_manifest_sha256']);
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapCapacityHeldManifestFile(
                $fixture['nonce'],
            ),
        );
    }

    public function testAllocatingCancellationPromotesItsPublishedEmptyReceiptAfterCrash(): void
    {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-unbound-receipt-crash',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        \putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation) {
            // PHP is ALLOCATING and native capacity is absent.
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $this->assertRebootstrapCrash(
            fn (): array => $packages->releaseRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'cancel',
            ),
            'capacity-reserve:after-unbound-released-receipt-before-journal',
        );
        self::assertSame(
            'ALLOCATING',
            $packages->rebootstrapStatus($fixture['nonce'])['capacity_reserve_state'],
        );
        self::assertFileExists(
            $this->paths->rebootstrapCapacityReleasedReceiptFile(
                $fixture['nonce'],
            ),
        );

        $released = $packages->releaseRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'cancel',
        );
        self::assertSame('RELEASED', $released['capacity_reserve_state']);
        self::assertSame('', $released['capacity_reserve_manifest_sha256']);
        self::assertSame('cancel', $released['capacity_reserve_release_reason']);
    }

    /** @return iterable<string,array{string}> */
    public static function unboundAllocatingReceiptFailureProvider(): iterable
    {
        yield 'partial receipt' => ['partial'];
        yield 'signed foreign profile receipt' => ['foreign-profile'];
    }

    #[DataProvider('unboundAllocatingReceiptFailureProvider')]
    public function testAllocatingCancellationRejectsPartialOrForeignPublishedReceipt(
        string $shape,
    ): void {
        $fixture = $this->createPreparedRebootstrapFixture(
            'capacity-evidence-unbound-receipt-' . $shape,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        \putenv(
            'WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT='
                . 'capacity-reserve:after-allocating-journal',
        );
        try {
            $packages->ensureRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
            );
            self::fail('Capacity allocation must expose its journal boundary.');
        } catch (GatewayRebootstrapCrashSimulation) {
            // PHP is ALLOCATING and native capacity is absent.
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
        $receipt = $this->paths->rebootstrapCapacityReleasedReceiptFile(
            $fixture['nonce'],
        );
        if ($shape === 'partial') {
            self::assertSame(11, \file_put_contents(
                $receipt,
                '{"partial":',
            ));
            self::assertTrue(\chmod($receipt, 0600));
        } else {
            $journal = \json_decode(
                (string)\file_get_contents(
                    $this->paths->rebootstrapJournalFile(),
                ),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
            self::assertIsArray($journal);
            $document = (new \ReflectionMethod(
                HostGatewayPackageManager::class,
                'capacityEmptyReleasedDocument',
            ))->invoke($packages, $journal, 'cancel');
            self::assertIsArray($document);
            $document['profile'] = 'ipv4-only';
            (new \ReflectionMethod(
                HostGatewayPackageManager::class,
                'writeRebootstrapCapacityEvidence',
            ))->invoke($packages, $receipt, $document);
        }

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->releaseRebootstrapCapacityReserve(
                $fixture['nonce'],
                $fixture['package_digest'],
                'default',
                'cancel',
            ),
        );

        self::assertStringContainsString(
            'capacity evidence',
            $exception->getMessage(),
            $shape,
        );
        self::assertSame(
            'ALLOCATING',
            $packages->rebootstrapStatus($fixture['nonce'])['capacity_reserve_state'],
            $shape,
        );
        self::assertFileExists($receipt);
    }

    public function testTerminalRebootstrapReceiptGcPreflightFailsBeforeFirstMove(): void
    {
        [$packages, $template] = $this->collectedRollbackReceiptTemplate(
            'receipt-gc-preflight',
        );
        $records = $this->seedCollectedRebootstrapReceipts(
            $template,
            1025,
        );
        $oldest = $records[0];
        $sentinel = $records[1];
        $malformed = $this->paths->rebootstrapReceiptsDir()
            . DIRECTORY_SEPARATOR . $oldest['nonce'] . '.json.gc-NOTHEX';
        self::assertNotFalse(\file_put_contents(
            $malformed,
            $oldest['contents'],
        ));
        self::assertTrue(\chmod($malformed, 0600));
        $exception = $this->captureRuntimeException(
            fn (): null => $packages->assertNoActiveRebootstrap(
                'test malformed receipt GC alias',
            ),
        );
        self::assertStringContainsString(
            'malformed or noncanonical entry',
            $exception->getMessage(),
        );
        self::assertFileExists($oldest['path']);
        self::assertFileExists($sentinel['path']);
        self::assertTrue(\unlink($malformed));

        $tamperedReceipt = \json_decode(
            $oldest['contents'],
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($tamperedReceipt);
        $tamperedReceipt['signature'] = \str_repeat('0', 64);
        self::assertNotFalse(\file_put_contents(
            $oldest['path'],
            GatewayClient::canonicalJson($tamperedReceipt) . "\n",
        ));
        self::assertTrue(\chmod($oldest['path'], 0600));
        $exception = $this->captureRuntimeException(
            fn (): null => $packages->assertNoActiveRebootstrap(
                'test receipt GC forged HMAC',
            ),
        );
        self::assertStringContainsString(
            'authentication failed',
            $exception->getMessage(),
        );
        self::assertFileExists($oldest['path']);
        self::assertFileExists($sentinel['path']);
        self::assertNotFalse(\file_put_contents(
            $oldest['path'],
            $oldest['contents'],
        ));
        self::assertTrue(\chmod($oldest['path'], 0600));

        $caseAlias = $this->paths->rebootstrapReceiptsDir()
            . DIRECTORY_SEPARATOR . \strtoupper($oldest['nonce'])
            . '.JSON';
        $caseBridge = $oldest['path'] . '.case-bridge';
        self::assertTrue(\rename($oldest['path'], $caseBridge));
        self::assertTrue(\rename($caseBridge, $caseAlias));
        $exception = $this->captureRuntimeException(
            fn (): null => $packages->assertNoActiveRebootstrap(
                'test receipt case alias',
            ),
        );
        self::assertStringContainsString(
            'malformed or noncanonical entry',
            $exception->getMessage(),
        );
        self::assertFileExists($caseAlias);
        self::assertFileExists($sentinel['path']);
        self::assertTrue(\rename($caseAlias, $caseBridge));
        self::assertTrue(\rename($caseBridge, $oldest['path']));

        $wrongDigest = \str_starts_with($oldest['sha256'], \str_repeat('0', 32))
            ? \str_repeat('f', 64)
            : \str_repeat('0', 64);
        $digestAlias = $this->paths->rebootstrapReceiptGcFile(
            $oldest['nonce'],
            $wrongDigest,
        );
        self::assertTrue(\rename($oldest['path'], $digestAlias));
        $exception = $this->captureRuntimeException(
            fn (): null => $packages->assertNoActiveRebootstrap(
                'test receipt GC alias digest mismatch',
            ),
        );
        self::assertStringContainsString(
            'GC alias digest is invalid',
            $exception->getMessage(),
        );
        self::assertFileExists($digestAlias);
        self::assertFileExists($sentinel['path']);
        self::assertTrue(\rename($digestAlias, $oldest['path']));

        $firstAlias = $this->paths->rebootstrapReceiptGcFile(
            $oldest['nonce'],
            $oldest['sha256'],
        );
        self::assertTrue(\rename($oldest['path'], $firstAlias));
        $secondReceipt = $this->signedCollectedRebootstrapReceipt(
            $template,
            $oldest['nonce'],
            $oldest['updated_at'] + 1,
        );
        $secondAlias = $this->paths->rebootstrapReceiptGcFile(
            $oldest['nonce'],
            $secondReceipt['sha256'],
        );
        self::assertNotSame($firstAlias, $secondAlias);
        self::assertNotFalse(\file_put_contents(
            $secondAlias,
            $secondReceipt['contents'],
        ));
        self::assertTrue(\chmod($secondAlias, 0600));
        $workspaceInventory = new \ReflectionMethod(
            GatewayPlatformServiceInstaller::class,
            'windowsRebootstrapWorkspaceInventory',
        );
        $exception = $this->captureRuntimeException(
            fn (): array => $workspaceInventory->invoke(
                new GatewayPlatformServiceInstaller($this->paths),
            ),
        );
        self::assertStringContainsString(
            'GC alias namespace is ambiguous',
            $exception->getMessage(),
        );
        $exception = $this->captureRuntimeException(
            fn (): null => $packages->assertNoActiveRebootstrap(
                'test multiple receipt GC aliases',
            ),
        );
        self::assertStringContainsString(
            'multiple GC aliases',
            $exception->getMessage(),
        );
        self::assertFileExists($firstAlias);
        self::assertFileExists($secondAlias);
        self::assertFileExists($sentinel['path']);
    }

    public function testTerminalRebootstrapReceiptGcRunsBeforeLegacyProductQuota(): void
    {
        [$packages, $template] = $this->collectedRollbackReceiptTemplate(
            'receipt-gc-legacy-quota',
        );
        $records = $this->seedCollectedRebootstrapReceipts(
            $template,
            4097,
        );
        $oldest = $records[0];
        $newest = $records[4096];

        $packages->assertNoActiveRebootstrap(
            'test receipt GC before legacy product quota',
        );

        self::assertFileDoesNotExist($oldest['path']);
        self::assertFileExists($newest['path']);
        self::assertCount(
            1024,
            \glob(
                $this->paths->rebootstrapReceiptsDir()
                    . DIRECTORY_SEPARATOR . '*.json',
            ) ?: [],
        );
    }

    public function testRollbackObservationRetryRevokesMarkerBeforeItsJournalReset(): void
    {
        $fixture = $this->createStoppedRebootstrapFixture(
            'rollback-observation-retry',
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $nonce = $fixture['nonce'];
        $digest = $fixture['package_digest'];
        $advance = static fn (string $expected, string $next): array =>
            $packages->advanceRebootstrapPhase(
                $nonce,
                $digest,
                'default',
                $expected,
                $next,
            );

        $advance('STOP_COMMITTED', 'QUIESCED');
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $nonce,
            $digest,
        );
        $platform->refreshDefinition('default');
        $advance('NEW_GENERATION_PUBLISHED', 'PLATFORM_REFRESHED');
        $packages->beginRebootstrapRollback(
            $nonce,
            $digest,
            'default',
            'initial rollback',
        );
        $packages->rollbackRebootstrapGeneration(
            $nonce,
            $digest,
            'default',
            'initial rollback',
        );
        $platform->restoreRebootstrapDefinition($nonce, $fixture['snapshot']);
        $advance('ROLLING_BACK', 'ROLLBACK_START_AUTHORIZED');

        $exception = $this->captureRuntimeException(
            fn (): array => $packages->retryRebootstrapRollbackObservation(
                $nonce,
                $digest,
                'default',
                'must reject the start-authorized phase',
            ),
        );
        self::assertStringContainsString(
            'cannot be retried from phase ROLLBACK_START_AUTHORIZED',
            $exception->getMessage(),
        );
        self::assertFileExists($this->paths->rebootstrapStartAuthorizationFile());

        $advance('ROLLBACK_START_AUTHORIZED', 'ROLLBACK_OBSERVING');
        self::assertFileExists($this->paths->rebootstrapStartAuthorizationFile());
        $this->assertRebootstrapCrash(
            fn (): array => $packages->retryRebootstrapRollbackObservation(
                $nonce,
                $digest,
                'default',
                'rollback health\ncheck failed',
            ),
            'start-authorization:revoked-before-journal',
        );
        self::assertSame(
            'ROLLBACK_OBSERVING',
            $packages->rebootstrapStatus($nonce)['phase'],
        );
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapStartAuthorizationFile(),
        );

        $retried = $packages->retryRebootstrapRollbackObservation(
            $nonce,
            $digest,
            'default',
            "rollback health\ncheck failed",
        );
        self::assertSame('ROLLING_BACK', $retried['phase']);
        self::assertSame('rollback health check failed', $retried['failure_reason']);
        self::assertFileDoesNotExist(
            $this->paths->rebootstrapStartAuthorizationFile(),
        );
        self::assertSame('ROLLING_BACK', $packages->rebootstrapStatus($nonce)['phase']);

        $idempotent = $packages->retryRebootstrapRollbackObservation(
            $nonce,
            $digest,
            'default',
            'a later reason must not overwrite the first failure',
        );
        self::assertSame('ROLLING_BACK', $idempotent['phase']);
        self::assertSame(
            'rollback health check failed',
            $idempotent['failure_reason'],
        );
    }

    public function testPreparingRebootstrapRetainsItsCandidateInstallLockUntilRetryCompletes(): void
    {
        $packages = new HostGatewayPackageManager($this->paths);
        $initial = $packages->stage(
            $this->createPackage('candidate-lock-preparing-old'),
            'default',
        );
        $packages->activate($initial['slot']);
        $candidate = $this->createPackage(
            'candidate-lock-preparing-new',
            true,
        );
        $nonce = \bin2hex(\random_bytes(16));
        $this->assertRebootstrapCrash(
            static fn (): array => $packages->prepareRebootstrapCandidate(
                $candidate,
                'default',
                $nonce,
            ),
            'after:PREPARING',
        );
        $lock = $this->paths->rebootstrapCandidateDir($nonce)
            . '.install.lock';
        self::assertSame(0, \file_put_contents($lock, ''));
        self::assertSame(
            'PREPARING',
            $packages->rebootstrapStatus($nonce)['phase'],
        );
        self::assertFileExists($lock);

        $prepared = $packages->prepareRebootstrapCandidate(
            $candidate,
            'default',
            $nonce,
        );
        self::assertSame('PREPARED', $prepared['phase']);
        self::assertFileDoesNotExist($lock);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function rebootstrapCandidateLockCrashProvider(): iterable
    {
        yield 'before-retire' => [
            'candidate-install-lock:before-retire',
            true,
        ];
        yield 'after-retire' => [
            'candidate-install-lock:after-retire',
            false,
        ];
    }

    #[DataProvider('rebootstrapCandidateLockCrashProvider')]
    public function testPreparedCandidateInstallLockRetirementReplaysAfterCrash(
        string $point,
        bool $existsAfterCrash,
    ): void {
        $suffix = \str_replace([':', '-'], '', $point);
        $packages = new HostGatewayPackageManager($this->paths);
        $initial = $packages->stage(
            $this->createPackage('candidate-lock-' . $suffix . '-old'),
            'default',
        );
        $packages->activate($initial['slot']);
        $candidate = $this->createPackage(
            'candidate-lock-' . $suffix . '-new',
            true,
        );
        $nonce = \bin2hex(\random_bytes(16));
        $this->assertRebootstrapCrash(
            static fn (): array => $packages->prepareRebootstrapCandidate(
                $candidate,
                'default',
                $nonce,
            ),
            $point,
        );
        $journal = \json_decode(
            (string)\file_get_contents($this->paths->rebootstrapJournalFile()),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('PREPARED', $journal['phase']);
        $lock = $this->paths->rebootstrapCandidateDir($nonce)
            . '.install.lock';
        $existsAfterCrash
            ? self::assertFileExists($lock)
            : self::assertFileDoesNotExist($lock);

        $prepared = $packages->prepareRebootstrapCandidate(
            $candidate,
            'default',
            $nonce,
        );
        self::assertSame('PREPARED', $prepared['phase']);
        self::assertFileDoesNotExist($lock);
    }

    /** @return iterable<string,array{string}> */
    public static function unsafePreparedCandidateInstallLockProvider(): iterable
    {
        yield 'non-empty' => ['non-empty'];
        yield 'symlink' => ['symlink'];
        yield 'hard-link' => ['hard-link'];
    }

    #[DataProvider('unsafePreparedCandidateInstallLockProvider')]
    public function testPreparedCandidateInstallLockRetirementFailsClosed(
        string $shape,
    ): void {
        if (\PHP_OS_FAMILY === 'Windows'
            && \in_array($shape, ['symlink', 'hard-link'], true)
        ) {
            self::markTestSkipped(
                'Windows link creation requires privileges unavailable to this fixture.',
            );
        }
        $packages = new HostGatewayPackageManager($this->paths);
        $initial = $packages->stage(
            $this->createPackage('candidate-lock-unsafe-' . $shape . '-old'),
            'default',
        );
        $packages->activate($initial['slot']);
        $nonce = \bin2hex(\random_bytes(16));
        $prepared = $packages->prepareRebootstrapCandidate(
            $this->createPackage(
                'candidate-lock-unsafe-' . $shape . '-new',
                true,
            ),
            'default',
            $nonce,
        );
        self::assertSame('PREPARED', $prepared['phase']);
        $lock = $this->paths->rebootstrapCandidateDir($nonce)
            . '.install.lock';
        @\unlink($lock);
        if (\hash_equals('non-empty', $shape)) {
            self::assertSame(6, \file_put_contents($lock, 'poison'));
        } elseif (\hash_equals('symlink', $shape)) {
            $target = $this->root . DIRECTORY_SEPARATOR . 'candidate-lock-target';
            self::assertSame(0, \file_put_contents($target, ''));
            self::assertTrue(\symlink($target, $lock));
        } else {
            $target = $this->root . DIRECTORY_SEPARATOR . 'candidate-lock-hardlink';
            self::assertSame(0, \file_put_contents($target, ''));
            self::assertTrue(\link($target, $lock));
        }

        $exception = $this->captureRuntimeException(
            static fn (): ?array => $packages->rebootstrapStatus($nonce),
        );
        self::assertStringContainsString(
            'candidate install lock is not an empty regular single-link file',
            $exception->getMessage(),
        );
        self::assertTrue(\file_exists($lock) || \is_link($lock));
    }

    /** @return iterable<string,array{string}> */
    public static function rebootstrapCrashPhaseProvider(): iterable
    {
        foreach ([
            'after:PREPARING',
            'after:PREPARED',
            'after:STOP_COMMITTED',
            'after:QUIESCED',
            'after:OLD_GENERATION_STASHED',
            'after:NEW_GENERATION_PUBLISHED',
            'after:PLATFORM_REFRESHED',
            'after:START_AUTHORIZED',
            'after:OBSERVING',
            'after:COMMITTED',
            'after-receipt:COMMITTED',
            'after:ROLLING_BACK',
            'after:ROLLBACK_START_AUTHORIZED',
            'after:ROLLBACK_OBSERVING',
            'after:ROLLED_BACK',
            'after-receipt:ROLLED_BACK',
        ] as $point) {
            yield $point => [$point];
        }
    }

    /** @return iterable<string,array{string}> */
    public static function rebootstrapStartAuthorizationCrashProvider(): iterable
    {
        foreach ([
            'future-marker-before-journal',
            'bridge-before-journal',
            'journal-before-final-marker',
            'final-marker',
            'revoked-before-journal',
        ] as $stage) {
            yield $stage => [$stage];
        }
    }

    #[DataProvider('rebootstrapStartAuthorizationCrashProvider')]
    public function testRebootstrapStartAuthorizationCrashWindowsConverge(
        string $stage,
    ): void {
        $fixture = $this->createStoppedRebootstrapFixture(
            'start-authorization-' . \str_replace('-', '', $stage),
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $nonce = $fixture['nonce'];
        $digest = $fixture['package_digest'];
        $advance = static fn (
            string $expected,
            string $next,
            array $evidence = [],
        ): array => $packages->advanceRebootstrapPhase(
            $nonce,
            $digest,
            'default',
            $expected,
            $next,
            $evidence,
        );

        $advance('STOP_COMMITTED', 'QUIESCED');
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $nonce,
            $digest,
        );
        $platform->refreshDefinition('default');
        $advance('NEW_GENERATION_PUBLISHED', 'PLATFORM_REFRESHED');
        $observingEvidence = [
            'new_gateway_epoch' => $fixture['new_gateway_epoch'],
        ];

        if (!\hash_equals('future-marker-before-journal', $stage)) {
            $advance('PLATFORM_REFRESHED', 'START_AUTHORIZED');
        }
        $point = 'start-authorization:' . $stage;
        if (\hash_equals('future-marker-before-journal', $stage)) {
            $operation = static fn (): array => $advance(
                'PLATFORM_REFRESHED',
                'START_AUTHORIZED',
            );
            $expectedCrashPhase = 'PLATFORM_REFRESHED';
        } elseif (\hash_equals('revoked-before-journal', $stage)) {
            $operation = static fn (): array => $packages
                ->beginRebootstrapRollback(
                    $nonce,
                    $digest,
                    'default',
                    'test start-authorization revocation crash',
                );
            $expectedCrashPhase = 'START_AUTHORIZED';
        } else {
            $operation = static fn (): array => $advance(
                'START_AUTHORIZED',
                'OBSERVING',
                $observingEvidence,
            );
            $expectedCrashPhase = \in_array($stage, [
                'journal-before-final-marker',
                'final-marker',
            ], true) ? 'OBSERVING' : 'START_AUTHORIZED';
        }
        $this->assertRebootstrapCrash($operation, $point);

        $journalContents = (string)\file_get_contents(
            $this->paths->rebootstrapJournalFile(),
        );
        $journal = \json_decode(
            $journalContents,
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame($expectedCrashPhase, $journal['phase']);
        $journalDigest = \hash('sha256', $journalContents);
        $authorization = $this->readRebootstrapStartAuthorization();

        if (\hash_equals('revoked-before-journal', $stage)) {
            self::assertNull($authorization);
        } else {
            self::assertIsArray($authorization);
            self::assertSame($journal['host_id'], $authorization['host_id']);
            self::assertSame($journal['nonce'], $authorization['nonce']);
            self::assertSame('forward', $authorization['purpose']);
            self::assertSame('A', $authorization['active_slot']);
            self::assertSame(
                $journal['runtime_generation'],
                $authorization['runtime_generation'],
            );
            self::assertSame(
                $journal['candidate_launcher_sha256'],
                $authorization['stable_launcher_sha256'],
            );
            if (\hash_equals('future-marker-before-journal', $stage)) {
                self::assertNotSame(
                    $journalDigest,
                    $authorization['journal_sha256_primary'],
                );
                self::assertSame(
                    \str_repeat('0', 64),
                    $authorization['journal_sha256_secondary'],
                );
            } elseif (\hash_equals('bridge-before-journal', $stage)) {
                self::assertSame(
                    $journalDigest,
                    $authorization['journal_sha256_primary'],
                );
                self::assertNotSame(
                    \str_repeat('0', 64),
                    $authorization['journal_sha256_secondary'],
                );
                self::assertNotSame(
                    $journalDigest,
                    $authorization['journal_sha256_secondary'],
                );
            } elseif (\hash_equals(
                'journal-before-final-marker',
                $stage,
            )) {
                self::assertNotSame(
                    $journalDigest,
                    $authorization['journal_sha256_primary'],
                );
                self::assertSame(
                    $journalDigest,
                    $authorization['journal_sha256_secondary'],
                );
            } else {
                self::assertSame(
                    $journalDigest,
                    $authorization['journal_sha256_primary'],
                );
                self::assertSame(
                    \str_repeat('0', 64),
                    $authorization['journal_sha256_secondary'],
                );
            }
        }

        if (\hash_equals('future-marker-before-journal', $stage)) {
            $retried = $advance(
                'PLATFORM_REFRESHED',
                'START_AUTHORIZED',
            );
            self::assertSame('START_AUTHORIZED', $retried['phase']);
        } elseif (\hash_equals('revoked-before-journal', $stage)) {
            $retried = $packages->beginRebootstrapRollback(
                $nonce,
                $digest,
                'default',
                'test start-authorization revocation crash',
            );
            self::assertSame('ROLLING_BACK', $retried['phase']);
            self::assertNull($this->readRebootstrapStartAuthorization());
            return;
        } else {
            $retried = $advance(
                'START_AUTHORIZED',
                'OBSERVING',
                $observingEvidence,
            );
            self::assertSame('OBSERVING', $retried['phase']);
        }

        $finalJournalContents = (string)\file_get_contents(
            $this->paths->rebootstrapJournalFile(),
        );
        $finalAuthorization = $this->readRebootstrapStartAuthorization();
        self::assertIsArray($finalAuthorization);
        self::assertSame(
            \hash('sha256', $finalJournalContents),
            $finalAuthorization['journal_sha256_primary'],
        );
        self::assertSame(
            \str_repeat('0', 64),
            $finalAuthorization['journal_sha256_secondary'],
        );
    }

    #[DataProvider('rebootstrapCrashPhaseProvider')]
    public function testEveryRebootstrapCrashPhaseReplaysFromSignedState(
        string $point,
    ): void {
        $suffix = \strtolower(\str_replace([':', '_', '-'], '', $point));
        $platform = new GatewayPlatformServiceInstaller($this->paths);
        $packages = new HostGatewayPackageManager(
            paths: $this->paths,
            platform: $platform,
        );
        if (\in_array($point, ['after:PREPARING', 'after:PREPARED'], true)) {
            $initial = $packages->stage(
                $this->createPackage('phase-' . $suffix . '-old'),
                'default',
            );
            $packages->activate($initial['slot']);
            $candidate = $this->createPackage(
                'phase-' . $suffix . '-new',
                true,
            );
            $nonce = \bin2hex(\random_bytes(16));
            $this->assertRebootstrapCrash(function () use (
                $packages,
                $candidate,
                $nonce,
            ): void {
                $packages->prepareRebootstrapCandidate(
                    $candidate,
                    'default',
                    $nonce,
                );
            }, $point);
            $prepared = $packages->prepareRebootstrapCandidate(
                $candidate,
                'default',
                $nonce,
            );
            self::assertSame('PREPARED', $prepared['phase']);
            return;
        }

        $fixture = $this->createPreparedRebootstrapFixture(
            'phase-' . $suffix,
            $packages,
            $platform,
        );
        $nonce = $fixture['nonce'];
        $digest = $fixture['package_digest'];
        $advance = function (
            string $expected,
            string $next,
            array $evidence = [],
        ) use ($packages, $nonce, $digest): array {
            return $packages->advanceRebootstrapPhase(
                $nonce,
                $digest,
                'default',
                $expected,
                $next,
                $evidence,
            );
        };
        $stopEvidence = [
            'admin_stopped_contents' => $fixture['admin_stopped_intent'],
            'gateway_epoch' => $fixture['gateway_epoch'],
        ];
        $packages->ensureRebootstrapCapacityReserve(
            $nonce,
            $digest,
            'default',
        );
        if (\hash_equals('after:STOP_COMMITTED', $point)) {
            $this->assertRebootstrapCrash(
                static fn (): array => $advance(
                    'PREPARED',
                    'STOP_COMMITTED',
                    $stopEvidence,
                ),
                $point,
            );
        }
        $advance('PREPARED', 'STOP_COMMITTED', $stopEvidence);
        $platform->stop((string)$fixture['snapshot']['kind']);
        if (\hash_equals('after:QUIESCED', $point)) {
            $this->assertRebootstrapCrash(
                static fn (): array => $advance('STOP_COMMITTED', 'QUIESCED'),
                $point,
            );
        }
        $advance('STOP_COMMITTED', 'QUIESCED');

        $rollback = \in_array($point, [
            'after:ROLLING_BACK',
            'after:ROLLBACK_START_AUTHORIZED',
            'after:ROLLBACK_OBSERVING',
            'after:ROLLED_BACK',
            'after-receipt:ROLLED_BACK',
        ], true);
        if (\in_array($point, [
            'after:OLD_GENERATION_STASHED',
            'after:NEW_GENERATION_PUBLISHED',
        ], true)) {
            $this->assertRebootstrapCrash(function () use (
                $packages,
                $nonce,
                $digest,
            ): void {
                self::publishRebootstrapGenerationAfterCapacityRelease(
                    $packages,
                    $nonce,
                    $digest,
                    'default',
                );
            }, $point);
        }
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $nonce,
            $digest,
        );
        if ($rollback) {
            if (\hash_equals('after:ROLLING_BACK', $point)) {
                $this->assertRebootstrapCrash(function () use (
                    $packages,
                    $nonce,
                    $digest,
                ): void {
                    $packages->beginRebootstrapRollback(
                        $nonce,
                        $digest,
                        'default',
                        'phase replay test',
                    );
                }, $point);
            }
            $packages->beginRebootstrapRollback(
                $nonce,
                $digest,
                'default',
                'phase replay test',
            );
            $packages->rollbackRebootstrapGeneration(
                $nonce,
                $digest,
                'default',
                'phase replay test',
            );
            $platform->restoreRebootstrapDefinition(
                $nonce,
                $fixture['snapshot'],
            );
            foreach ([
                ['ROLLING_BACK', 'ROLLBACK_START_AUTHORIZED'],
                ['ROLLBACK_START_AUTHORIZED', 'ROLLBACK_OBSERVING'],
            ] as [$expected, $next]) {
                if (\hash_equals('after:' . $next, $point)) {
                    $this->assertRebootstrapCrash(
                        static fn (): array => $advance(
                            $expected,
                            $next,
                        ),
                        $point,
                    );
                }
                $advance($expected, $next);
                if (\hash_equals('ROLLBACK_START_AUTHORIZED', $next)) {
                    $packages->assertRebootstrapRollbackPreStartClosure(
                        $nonce,
                        $digest,
                        'default',
                    );
                }
            }
            $this->acknowledgeRollbackGuardianTransition();
            if (\in_array($point, [
                'after:ROLLED_BACK',
                'after-receipt:ROLLED_BACK',
            ], true)) {
                $this->assertRebootstrapCrash(
                    static fn (): array => $packages
                        ->completeRebootstrapRollback(
                            $nonce,
                            $digest,
                            'default',
                        ),
                    $point,
                );
            }
            $terminal = $packages->completeRebootstrapRollback(
                $nonce,
                $digest,
                'default',
            );
            self::assertSame('ROLLED_BACK', $terminal['phase']);
        } else {
            $platform->refreshDefinition('default');
            foreach ([
                ['NEW_GENERATION_PUBLISHED', 'PLATFORM_REFRESHED'],
                ['PLATFORM_REFRESHED', 'START_AUTHORIZED'],
                ['START_AUTHORIZED', 'OBSERVING'],
            ] as [$expected, $next]) {
                $phaseEvidence = \hash_equals('OBSERVING', $next)
                    ? ['new_gateway_epoch' => $fixture['new_gateway_epoch']]
                    : [];
                if (\hash_equals('after:' . $next, $point)) {
                    $this->assertRebootstrapCrash(
                        static fn (): array => $advance(
                            $expected,
                            $next,
                            $phaseEvidence,
                        ),
                        $point,
                    );
                }
                $advance($expected, $next, $phaseEvidence);
            }
            $this->acknowledgeCommitGuardianTransition();
            if (\in_array($point, [
                'after:COMMITTED',
                'after-receipt:COMMITTED',
            ], true)) {
                $this->assertRebootstrapCrash(
                    static fn (): array => $packages->commitRebootstrap(
                        $nonce,
                        $digest,
                        'default',
                    ),
                    $point,
                );
            }
            $terminal = $packages->commitRebootstrap(
                $nonce,
                $digest,
                'default',
            );
            self::assertSame('COMMITTED', $terminal['phase']);
        }
        self::assertSame('RETAINED', $terminal['retained_backup_state']);
        self::assertNull($packages->rebootstrapStatus($nonce));
        self::assertFileExists($this->paths->rebootstrapReceiptFile($nonce));
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($nonce),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('RETAINED', $receipt['retained_backup_state']);
        $resultMethod = new \ReflectionMethod(
            GatewayHostManager::class,
            'rebootstrapTerminalResult',
        );
        $manager = new GatewayHostManager(
            $this->paths,
            new GatewayClient($this->paths),
            $packages,
            $platform,
        );
        $public = $resultMethod->invoke($manager, $terminal);
        self::assertTrue($public['gateway_epoch_preserved']);
    }

    private function assertRebootstrapCrash(
        callable $operation,
        string $point,
    ): void {
        \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT=' . $point);
        try {
            $operation();
            self::fail(
                'Gateway rebootstrap boundary did not simulate a crash: '
                    . $point,
            );
        } catch (GatewayRebootstrapCrashSimulation $simulation) {
            self::assertStringContainsString($point, $simulation->getMessage());
        } finally {
            \putenv('WLS_GATEWAY_TEST_REBOOTSTRAP_FAULT');
        }
    }

    /**
     * @return array{
     *   host_id:string,
     *   nonce:string,
     *   purpose:string,
     *   journal_sha256_primary:string,
     *   journal_sha256_secondary:string,
     *   active_slot:string,
     *   runtime_generation:string,
     *   stable_launcher_sha256:string,
     *   signature:string
     * }|null
     */
    private function readRebootstrapStartAuthorization(): ?array
    {
        $file = $this->paths->rebootstrapStartAuthorizationFile();
        if (!\file_exists($file) && !\is_link($file)) {
            return null;
        }
        $contents = (string)\file_get_contents($file);
        self::assertSame(1, \preg_match(
            '/\AWLS-REBOOTSTRAP-START\/1\n'
                . 'host_id=(?<host_id>[a-f0-9]{32})\n'
                . 'nonce=(?<nonce>[a-f0-9]{32})\n'
                . 'purpose=(?<purpose>forward|rollback)\n'
                . 'journal_sha256_primary='
                    . '(?<journal_sha256_primary>[a-f0-9]{64})\n'
                . 'journal_sha256_secondary='
                    . '(?<journal_sha256_secondary>[a-f0-9]{64})\n'
                . 'active_slot=(?<active_slot>A|B)\n'
                . 'runtime_generation='
                    . '(?<runtime_generation>[a-f0-9]{64})\n'
                . 'stable_launcher_sha256='
                    . '(?<stable_launcher_sha256>[a-f0-9]{64})\n'
                . 'signature=(?<signature>[a-f0-9]{64})\n\z/D',
            $contents,
            $matches,
        ));
        $signatureOffset = \strrpos($contents, 'signature=');
        self::assertIsInt($signatureOffset);
        $secret = \trim((string)\file_get_contents(
            $this->paths->adminTokenFile(),
        ));
        $key = \hex2bin($secret);
        self::assertIsString($key);
        try {
            self::assertSame(
                $matches['signature'],
                \hash_hmac(
                    'sha256',
                    \substr($contents, 0, $signatureOffset),
                    $key,
                ),
            );
        } finally {
            \sodium_memzero($key);
        }
        return [
            'host_id' => $matches['host_id'],
            'nonce' => $matches['nonce'],
            'purpose' => $matches['purpose'],
            'journal_sha256_primary' => $matches['journal_sha256_primary'],
            'journal_sha256_secondary' => $matches['journal_sha256_secondary'],
            'active_slot' => $matches['active_slot'],
            'runtime_generation' => $matches['runtime_generation'],
            'stable_launcher_sha256' => $matches['stable_launcher_sha256'],
            'signature' => $matches['signature'],
        ];
    }

    /**
     * @return array{
     *   packages:HostGatewayPackageManager,
     *   platform:GatewayPlatformServiceInstaller,
     *   nonce:string,
     *   package_digest:string,
     *   snapshot:array<string,mixed>,
     *   admin_stopped_intent:string,
     *   gateway_epoch:string,
     *   new_gateway_epoch:string,
     *   trust_rotation:bool,
     *   old_active_slot:string,
     *   old_launcher_digest:string
     * }
     */
    private function createStoppedRebootstrapFixture(
        string $suffix,
        ?HostGatewayPackageManager $packages = null,
        ?GatewayPlatformServiceInstaller $platform = null,
        bool $rotateTrust = false,
    ): array {
        $fixture = $this->createPreparedRebootstrapFixture(
            $suffix,
            $packages,
            $platform,
            $rotateTrust,
        );
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $packages->ensureRebootstrapCapacityReserve(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
        );
        $packages->advanceRebootstrapPhase(
            $fixture['nonce'],
            $fixture['package_digest'],
            'default',
            'PREPARED',
            'STOP_COMMITTED',
            [
                'admin_stopped_contents' => $fixture['admin_stopped_intent'],
                'gateway_epoch' => $fixture['gateway_epoch'],
            ],
        );
        $platform->stop((string)$fixture['snapshot']['kind']);
        return $fixture;
    }

    /** @return array<string,mixed> */
    private static function publishRebootstrapGenerationAfterCapacityRelease(
        HostGatewayPackageManager $packages,
        string $nonce,
        string $packageDigest,
        string $profile = 'default',
    ): array {
        $status = $packages->rebootstrapStatus($nonce);
        if (\is_array($status)
            && \hash_equals('QUIESCED', (string)$status['phase'])
        ) {
            $packages->releaseRebootstrapCapacityReserve(
                $nonce,
                $packageDigest,
                $profile,
                'forward',
            );
        }
        return $packages->publishRebootstrapGeneration(
            $nonce,
            $packageDigest,
            $profile,
        );
    }

    /**
     * @return array{
     *   packages:HostGatewayPackageManager,
     *   platform:GatewayPlatformServiceInstaller,
     *   nonce:string,
     *   package_digest:string,
     *   snapshot:array<string,mixed>,
     *   admin_stopped_intent:string,
     *   gateway_epoch:string,
     *   new_gateway_epoch:string,
     *   trust_rotation:bool,
     *   old_active_slot:string,
     *   old_launcher_digest:string
     * }
     */
    private function createPreparedRebootstrapFixture(
        string $suffix,
        ?HostGatewayPackageManager $packages = null,
        ?GatewayPlatformServiceInstaller $platform = null,
        bool $rotateTrust = false,
    ): array {
        $platform ??= new GatewayPlatformServiceInstaller($this->paths);
        $packages ??= new HostGatewayPackageManager(
            paths: $this->paths,
            platform: $platform,
        );
        $initial = $packages->stage(
            $this->createPackage('rebootstrap-' . $suffix . '-old'),
            'default',
        );
        $packages->activate($initial['slot']);
        $platform->installDefinition('default');
        $oldLauncherDigest = \hash_file('sha256', $this->paths->launcherFile());
        self::assertIsString($oldLauncherDigest);
        $nonce = \bin2hex(\random_bytes(16));
        $prepared = $packages->prepareRebootstrapCandidate(
            $this->createPackage(
                'rebootstrap-' . $suffix . '-new',
                true,
                $rotateTrust ? 'rotated' : 'primary',
            ),
            'default',
            $nonce,
        );
        self::assertSame($rotateTrust, $prepared['trust_rotation']);
        $snapshot = $platform->snapshotRebootstrapDefinition($nonce);
        $packages->recordRebootstrapEvidence(
            $nonce,
            (string)$prepared['package_digest'],
            'default',
            'PREPARED',
            ['platform_snapshot' => $snapshot],
        );
        $epoch = \substr(\hash('sha256', 'epoch-' . $suffix), 0, 32);
        $newEpoch = $rotateTrust
            ? \substr(\hash('sha256', 'new-epoch-' . $suffix), 0, 32)
            : $epoch;
        if ($rotateTrust && \hash_equals($epoch, $newEpoch)) {
            $newEpoch = ($newEpoch[0] === '0' ? '1' : '0')
                . \substr($newEpoch, 1);
        }
        $intent = $this->writeAdminStoppedIntent($epoch);
        return [
            'packages' => $packages,
            'platform' => $platform,
            'nonce' => $nonce,
            'package_digest' => (string)$prepared['package_digest'],
            'snapshot' => $snapshot,
            'admin_stopped_intent' => $intent,
            'gateway_epoch' => $epoch,
            'new_gateway_epoch' => $newEpoch,
            'trust_rotation' => $rotateTrust,
            'old_active_slot' => (string)$initial['slot'],
            'old_launcher_digest' => $oldLauncherDigest,
        ];
    }

    /**
     * @return array<string,array{
     *   leaf:string,
     *   top_level_path:string,
     *   payload_path:string,
     *   payload_relative:string,
     *   contents:string
     * }>
     */
    private function seedRebootstrapDerivedGeneration(string $suffix): array
    {
        $specifications = [
            'state' => [$this->paths->stateDir(), false],
            'trust' => [$this->paths->trustDir(), false],
            'snapshots' => [$this->paths->legacySnapshotsDir(), false],
            'snapshots-v2' => [$this->paths->sealedSnapshotsDir(), true],
            'snapshot-candidates-v2' => [
                $this->paths->snapshotCandidatesDir(),
                false,
            ],
            'runtime-conf' => [
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'conf',
                false,
            ],
            'runtime-temp' => [
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'temp',
                false,
            ],
            'runtime-shadow' => [
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'shadow',
                false,
            ],
            'runtime-run' => [
                $this->paths->runtimeDir() . DIRECTORY_SEPARATOR . 'run',
                false,
            ],
        ];
        $seeded = [];
        foreach ($specifications as $category => [$root, $directoryClosure]) {
            if (!\is_dir($root)) {
                self::assertTrue(\mkdir($root, 0700, true), $category);
            }
            $leaf = 'old-' . \str_replace(['/', '_'], '-', $category)
                . '-' . $suffix . ($directoryClosure ? '' : '.dat');
            $topLevel = $root . DIRECTORY_SEPARATOR . $leaf;
            $payload = $topLevel;
            $payloadRelative = $leaf;
            if ($directoryClosure) {
                self::assertTrue(\mkdir($topLevel, 0700), $category);
                $payload = $topLevel . DIRECTORY_SEPARATOR . 'payload.dat';
                $payloadRelative .= DIRECTORY_SEPARATOR . 'payload.dat';
            }
            $contents = 'old-derived-' . $category . '-' . $suffix . "\n";
            self::assertNotFalse(\file_put_contents($payload, $contents));
            self::assertTrue(\chmod($payload, 0600));
            $seeded[$category] = [
                'leaf' => $leaf,
                'top_level_path' => $topLevel,
                'payload_path' => $payload,
                'payload_relative' => $payloadRelative,
                'contents' => $contents,
            ];
        }
        return $seeded;
    }

    /**
     * Recreate only crash shapes that the same-CA derived-state copier can
     * legitimately leave below backup/working-generation.
     *
     * @param array<string,array{
     *   leaf:string,
     *   top_level_path:string,
     *   payload_path:string,
     *   payload_relative:string,
     *   contents:string
     * }> $seeded
     */
    private function seedPartialRebootstrapWorkingGeneration(
        string $nonce,
        array $seeded,
        string $shape,
    ): string {
        $manifest = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapDerivedManifestFile($nonce),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        $workingRoot = $this->paths->rebootstrapBackupDir($nonce)
            . DIRECTORY_SEPARATOR . 'working-generation';
        self::assertTrue(\mkdir($workingRoot, 0700));
        if (\hash_equals('root', $shape)) {
            return $workingRoot;
        }

        $stateRoot = $workingRoot . DIRECTORY_SEPARATOR . 'state';
        self::assertTrue(\mkdir($stateRoot, 0700));
        if (\hash_equals('category', $shape)) {
            return $workingRoot;
        }

        if (\in_array($shape, [
            'file-temp',
            'partial-deletion',
            'enospc',
        ], true)) {
            $stateLeaf = $seeded['state']['leaf'];
            $stateClosure = $manifest['categories']['state']['entries'][
                $stateLeaf
            ];
            self::assertIsArray($stateClosure);
            $stateRecord = $stateClosure['records'][0] ?? null;
            self::assertIsArray($stateRecord);
            $temporary = $stateRoot . DIRECTORY_SEPARATOR . $stateLeaf
                . '.wls-rebootstrap-copy-'
                . \substr((string)$stateRecord['sha256'], 0, 16);
            self::assertNotFalse(\file_put_contents(
                $temporary,
                \substr($seeded['state']['contents'], 0, 3),
            ));
            self::assertTrue(\chmod($temporary, 0600));
            if (\hash_equals('file-temp', $shape)) {
                return $workingRoot;
            }
        }

        $category = 'snapshots-v2';
        $entry = $seeded[$category];
        $categoryRoot = $workingRoot . DIRECTORY_SEPARATOR . $category;
        self::assertTrue(\mkdir($categoryRoot, 0700));
        $leafRoot = $categoryRoot . DIRECTORY_SEPARATOR . $entry['leaf'];
        self::assertTrue(\mkdir($leafRoot, 0700));
        $payload = $leafRoot . DIRECTORY_SEPARATOR . 'payload.dat';
        self::assertNotFalse(\file_put_contents($payload, $entry['contents']));
        self::assertTrue(\chmod($payload, 0600));
        return $workingRoot;
    }

    /**
     * @param array<string,array{
     *   leaf:string,
     *   top_level_path:string,
     *   payload_path:string,
     *   payload_relative:string,
     *   contents:string
     * }> $seeded
     */
    private function assertSeededDerivedGenerationStored(
        array $seeded,
        string $nonce,
    ): void {
        $root = $this->paths->rebootstrapDerivedBackupDir($nonce);
        foreach ($seeded as $category => $entry) {
            self::assertFalse(
                \file_exists($entry['top_level_path'])
                    || \is_link($entry['top_level_path']),
                $category . ' remained in the live trust generation.',
            );
            self::assertSame(
                $entry['contents'],
                \file_get_contents(
                    $root . DIRECTORY_SEPARATOR . $category
                        . DIRECTORY_SEPARATOR . $entry['payload_relative'],
                ),
                $category,
            );
        }
    }

    /**
     * @param array<string,array{
     *   leaf:string,
     *   top_level_path:string,
     *   payload_path:string,
     *   payload_relative:string,
     *   contents:string
     * }> $seeded
     */
    private function assertSeededDerivedGenerationRestored(
        array $seeded,
        string $nonce,
    ): void {
        $ephemeral = [
            'runtime-temp' => true,
            'runtime-shadow' => true,
            'runtime-run' => true,
        ];
        foreach ($seeded as $category => $entry) {
            if (isset($ephemeral[$category])) {
                self::assertFalse(
                    \file_exists($entry['top_level_path'])
                        || \is_link($entry['top_level_path']),
                    $category . ' was incorrectly restored into the live runtime.',
                );
                self::assertSame(
                    $entry['contents'],
                    \file_get_contents(
                        $this->paths->rebootstrapDerivedBackupDir($nonce)
                            . DIRECTORY_SEPARATOR . $category
                            . DIRECTORY_SEPARATOR . $entry['payload_relative'],
                    ),
                    $category,
                );
                continue;
            }
            self::assertSame(
                $entry['contents'],
                \file_get_contents($entry['payload_path']),
                $category,
            );
        }
    }

    /**
     * Emulate the immutable Recovery Guardian after PHP has durably entered
     * ROLLBACK_OBSERVING. Package-manager unit tests do not launch the
     * platform guardian, so a successful terminal rollback must supply the
     * same signed generation-head, acknowledgement and recovery transaction
     * that production requires.
     */
    private function acknowledgeRollbackGuardianTransition(): void
    {
        $protocol = new GatewayGuardianTransitionProtocol($this->paths);
        $requestRaw = GatewayProjectStateFilesystem::read(
            $this->paths->guardianTransitionRequestFile(),
            4096,
            'test Recovery Guardian transition request',
        );
        $request = (new \ReflectionMethod(
            GatewayGuardianTransitionProtocol::class,
            'decodeRequest',
        ))->invoke($protocol, $requestRaw);
        self::assertIsArray($request);

        $headStore = new GatewayGuardianGenerationHead($this->paths);
        $pending = $headStore->read();
        self::assertIsArray($pending);
        self::assertSame('ROLLBACK_PENDING', $pending['phase']);
        $recovery = [
            'generation_id' => (string)$request['recovery_generation_id'],
            'launcher_sha256' => (string)$request['recovery_launcher_sha256'],
            'ca_sha256' => (string)$request['recovery_ca_sha256'],
            'runtime_generation' => (string)$request['recovery_runtime_generation'],
        ];
        $observing = $headStore->transition(
            (int)$pending['sequence'],
            [
                'host_id' => (string)$request['host_id'],
                'phase' => 'ROLLBACK_OBSERVING',
                'active_generation_id' => $recovery['generation_id'],
                'active_launcher_sha256' => $recovery['launcher_sha256'],
                'active_ca_sha256' => $recovery['ca_sha256'],
                'active_runtime_generation' => $recovery['runtime_generation'],
                'recovery_generation_id' => $recovery['generation_id'],
                'recovery_nonce' => (string)$request['nonce'],
                'recovery_authorization_sha256'
                    => (string)$request['recovery_authorization_sha256'],
                'host_boot_id' => (string)$pending['host_boot_id'],
                'probation_started_monotonic_ms' => 1000,
                'probation_deadline_monotonic_ms' => 2000,
            ],
        );
        $stable = $headStore->transition(
            (int)$observing['sequence'],
            [
                'host_id' => (string)$request['host_id'],
                'phase' => 'STABLE',
                'active_generation_id' => $recovery['generation_id'],
                'active_launcher_sha256' => $recovery['launcher_sha256'],
                'active_ca_sha256' => $recovery['ca_sha256'],
                'active_runtime_generation' => $recovery['runtime_generation'],
                'recovery_generation_id' => \str_repeat('0', 64),
                'recovery_nonce' => \str_repeat('0', 32),
                'recovery_authorization_sha256' => \str_repeat('0', 64),
                'host_boot_id' => (string)$pending['host_boot_id'],
                'probation_started_monotonic_ms' => 0,
                'probation_deadline_monotonic_ms' => 0,
            ],
        );

        $acknowledgement = [
            'host_id' => (string)$request['host_id'],
            'nonce' => (string)$request['nonce'],
            'request_sha256' => \hash('sha256', $requestRaw),
            'committed_head_sequence' => (int)$stable['sequence'],
            'committed_head_sha256' => (string)$stable['record_sha256'],
            'purpose' => 'rollback',
            'phase' => 'STABLE',
            'active_generation_id' => $recovery['generation_id'],
        ];
        $encodeAcknowledgement = new \ReflectionMethod(
            GatewayGuardianTransitionProtocol::class,
            'encodeAcknowledgementUnsigned',
        );
        $sign = new \ReflectionMethod(
            GatewayGuardianTransitionProtocol::class,
            'signature',
        );
        $acknowledgementUnsigned = (string)$encodeAcknowledgement->invoke(
            $protocol,
            $acknowledgement,
        );
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianTransitionAcknowledgementFile(),
            $acknowledgementUnsigned . 'signature=' . $sign->invoke(
                $protocol,
                $acknowledgementUnsigned,
            ) . "\n",
            0600,
        );

        $recoveryTransaction = new \ReflectionMethod(
            GatewayGuardianTransitionProtocol::class,
            'recoveryTransactionRawAtSequence',
        );
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianRecoveryTransactionFile(),
            (string)$recoveryTransaction->invoke(
                $protocol,
                [
                    'host_id' => (string)$request['host_id'],
                    'nonce' => (string)$request['nonce'],
                    'request_sha256' => \hash('sha256', $requestRaw),
                    'authorization_sha256'
                        => (string)$request['recovery_authorization_sha256'],
                    'inventory_sha256'
                        => (string)$request['recovery_inventory_sha256'],
                ],
                26,
            ),
            0600,
        );
    }

    /**
     * Emulate the immutable Recovery Guardian commit acknowledgement. The
     * unit fixture supplies the same probationary and stable generation-head
     * after-images that the platform guardian must persist in production.
     */
    private function acknowledgeCommitGuardianTransition(): void
    {
        $protocol = new GatewayGuardianTransitionProtocol($this->paths);
        $requestRaw = GatewayProjectStateFilesystem::read(
            $this->paths->guardianTransitionRequestFile(),
            4096,
            'test Recovery Guardian transition request',
        );
        $request = (new \ReflectionMethod(
            GatewayGuardianTransitionProtocol::class,
            'decodeRequest',
        ))->invoke($protocol, $requestRaw);
        self::assertIsArray($request);

        $headStore = new GatewayGuardianGenerationHead($this->paths);
        $stableRecovery = $headStore->read();
        self::assertIsArray($stableRecovery);
        self::assertSame('STABLE', $stableRecovery['phase']);
        self::assertSame(
            $request['recovery_generation_id'],
            $stableRecovery['active_generation_id'],
        );
        $candidate = [
            'generation_id' => (string)$request['candidate_generation_id'],
            'launcher_sha256' => (string)$request['candidate_launcher_sha256'],
            'ca_sha256' => (string)$request['candidate_ca_sha256'],
            'runtime_generation' => (string)$request['candidate_runtime_generation'],
        ];
        $probationary = $headStore->transition(
            (int)$stableRecovery['sequence'],
            [
                'host_id' => (string)$request['host_id'],
                'phase' => 'PROBATIONARY_COMMITTED',
                'active_generation_id' => $candidate['generation_id'],
                'active_launcher_sha256' => $candidate['launcher_sha256'],
                'active_ca_sha256' => $candidate['ca_sha256'],
                'active_runtime_generation' => $candidate['runtime_generation'],
                'recovery_generation_id'
                    => (string)$request['recovery_generation_id'],
                'recovery_nonce' => (string)$request['nonce'],
                'recovery_authorization_sha256'
                    => (string)$request['recovery_authorization_sha256'],
                'host_boot_id' => (string)$stableRecovery['host_boot_id'],
                'probation_started_monotonic_ms' => 1000,
                'probation_deadline_monotonic_ms' => 2000,
            ],
        );
        $stableCandidate = $headStore->transition(
            (int)$probationary['sequence'],
            [
                'host_id' => (string)$request['host_id'],
                'phase' => 'STABLE',
                'active_generation_id' => $candidate['generation_id'],
                'active_launcher_sha256' => $candidate['launcher_sha256'],
                'active_ca_sha256' => $candidate['ca_sha256'],
                'active_runtime_generation' => $candidate['runtime_generation'],
                'recovery_generation_id' => \str_repeat('0', 64),
                'recovery_nonce' => \str_repeat('0', 32),
                'recovery_authorization_sha256' => \str_repeat('0', 64),
                'host_boot_id' => (string)$stableRecovery['host_boot_id'],
                'probation_started_monotonic_ms' => 0,
                'probation_deadline_monotonic_ms' => 0,
            ],
        );
        $acknowledgement = [
            'host_id' => (string)$request['host_id'],
            'nonce' => (string)$request['nonce'],
            'request_sha256' => \hash('sha256', $requestRaw),
            'committed_head_sequence' => (int)$stableCandidate['sequence'],
            'committed_head_sha256'
                => (string)$stableCandidate['record_sha256'],
            'purpose' => 'commit',
            'phase' => 'STABLE',
            'active_generation_id' => $candidate['generation_id'],
        ];
        $encodeAcknowledgement = new \ReflectionMethod(
            GatewayGuardianTransitionProtocol::class,
            'encodeAcknowledgementUnsigned',
        );
        $sign = new \ReflectionMethod(
            GatewayGuardianTransitionProtocol::class,
            'signature',
        );
        $acknowledgementUnsigned = (string)$encodeAcknowledgement->invoke(
            $protocol,
            $acknowledgement,
        );
        GatewayProjectStateFilesystem::atomicWrite(
            $this->paths->guardianTransitionAcknowledgementFile(),
            $acknowledgementUnsigned . 'signature=' . $sign->invoke(
                $protocol,
                $acknowledgementUnsigned,
            ) . "\n",
            0600,
        );
        self::assertFileDoesNotExist(
            $this->paths->guardianRecoveryTransactionFile(),
        );
    }

    /** @return array{HostGatewayPackageManager,array<string,mixed>} */
    private function collectedRollbackReceiptTemplate(string $suffix): array
    {
        [$packages, $fixture] = $this
            ->terminalRollbackWithRetainedCapacityEvidence($suffix);
        $nonce = $fixture['nonce'];
        $packages->assertNoActiveRebootstrap(
            'prepare collected rollback receipt template',
        );
        $file = $this->paths->rebootstrapReceiptFile($nonce);
        $receipt = \json_decode(
            (string)\file_get_contents($file),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($receipt);
        self::assertSame('ROLLED_BACK', $receipt['phase']);
        self::assertSame('COLLECTED', $receipt['retained_backup_state']);
        self::assertSame('', $receipt['backup_collection_nonce']);
        self::assertSame('', $receipt['backup_collection_device']);
        self::assertSame('', $receipt['backup_collection_inode']);
        self::assertSame('COLLECTED', $receipt['capacity_evidence_state']);
        self::assertTrue(\unlink($file));
        return [$packages, $receipt];
    }

    /**
     * @return array{HostGatewayPackageManager,array<string,mixed>}
     */
    private function terminalRollbackWithRetainedCapacityEvidence(
        string $suffix,
    ): array {
        $fixture = $this->createStoppedRebootstrapFixture($suffix);
        /** @var HostGatewayPackageManager $packages */
        $packages = $fixture['packages'];
        /** @var GatewayPlatformServiceInstaller $platform */
        $platform = $fixture['platform'];
        $nonce = $fixture['nonce'];
        $digest = $fixture['package_digest'];
        $packages->advanceRebootstrapPhase(
            $nonce,
            $digest,
            'default',
            'STOP_COMMITTED',
            'QUIESCED',
        );
        self::publishRebootstrapGenerationAfterCapacityRelease(
            $packages,
            $nonce,
            $digest,
        );
        $packages->beginRebootstrapRollback(
            $nonce,
            $digest,
            'default',
            'receipt GC fixture rollback',
        );
        $packages->rollbackRebootstrapGeneration(
            $nonce,
            $digest,
            'default',
            'receipt GC fixture rollback',
        );
        $platform->restoreRebootstrapDefinition(
            $nonce,
            $fixture['snapshot'],
        );
        $packages->advanceRebootstrapPhase(
            $nonce,
            $digest,
            'default',
            'ROLLING_BACK',
            'ROLLBACK_START_AUTHORIZED',
        );
        $packages->assertRebootstrapRollbackPreStartClosure(
            $nonce,
            $digest,
            'default',
        );
        $packages->advanceRebootstrapPhase(
            $nonce,
            $digest,
            'default',
            'ROLLBACK_START_AUTHORIZED',
            'ROLLBACK_OBSERVING',
        );
        $this->acknowledgeRollbackGuardianTransition();
        $packages->completeRebootstrapRollback($nonce, $digest, 'default');
        $receipt = \json_decode(
            (string)\file_get_contents(
                $this->paths->rebootstrapReceiptFile($nonce),
            ),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertSame('RETAINED', $receipt['capacity_evidence_state']);
        return [$packages, $fixture];
    }

    /**
     * @param array<string,mixed> $template
     * @return list<array{nonce:string,path:string,contents:string,sha256:string,updated_at:int,terminal_at:int}>
     */
    private function seedCollectedRebootstrapReceipts(
        array $template,
        int $count,
    ): array {
        self::assertGreaterThan(0, $count);
        $secret = \trim((string)\file_get_contents(
            $this->paths->adminTokenFile(),
        ));
        $key = \hex2bin($secret);
        self::assertIsString($key);
        $baseUpdatedAt = \max(
            (int)$template['created_at'],
            (int)$template['updated_at'],
        ) + 10_000;
        $records = [];
        try {
            for ($index = 0; $index < $count; ++$index) {
                $nonce = \hash(
                    'md5',
                    'wls-terminal-receipt-gc-' . $index,
                );
                $record = $this->signedCollectedRebootstrapReceipt(
                    $template,
                    $nonce,
                    $baseUpdatedAt + $index,
                    $key,
                );
                self::assertNotFalse(\file_put_contents(
                    $record['path'],
                    $record['contents'],
                ));
                self::assertTrue(\chmod($record['path'], 0600));
                $records[] = $record;
            }
        } finally {
            \sodium_memzero($key);
        }
        return $records;
    }

    /**
     * @param array<string,mixed> $template
     * @return array{nonce:string,path:string,contents:string,sha256:string,updated_at:int,terminal_at:int}
     */
    private function signedCollectedRebootstrapReceipt(
        array $template,
        string $nonce,
        int $updatedAt,
        ?string $hmacKey = null,
        ?int $terminalAt = null,
    ): array {
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $nonce);
        $ownedKey = $hmacKey === null;
        if ($hmacKey === null) {
            $secret = \trim((string)\file_get_contents(
                $this->paths->adminTokenFile(),
            ));
            $hmacKey = \hex2bin($secret);
        }
        self::assertIsString($hmacKey);
        try {
            $receipt = $template;
            $receipt['nonce'] = $nonce;
            $receipt['updated_at'] = \max(
                (int)$receipt['created_at'],
                $updatedAt,
            );
            $receipt['terminal_at'] = \max(
                (int)$receipt['created_at'],
                $terminalAt ?? $updatedAt,
            );
            $receipt['signature'] = '';
            $signed = $receipt;
            unset($signed['signature']);
            $receipt['signature'] = \hash_hmac(
                'sha256',
                GatewayClient::canonicalJson($signed),
                $hmacKey,
            );
            $contents = GatewayClient::canonicalJson($receipt) . "\n";
            return [
                'nonce' => $nonce,
                'path' => $this->paths->rebootstrapReceiptFile($nonce),
                'contents' => $contents,
                'sha256' => \hash('sha256', $contents),
                'updated_at' => (int)$receipt['updated_at'],
                'terminal_at' => (int)$receipt['terminal_at'],
            ];
        } finally {
            if ($ownedKey) {
                \sodium_memzero($hmacKey);
            }
        }
    }

    private function writeAdminStoppedIntent(string $epoch): string
    {
        $hostId = \trim((string)\file_get_contents($this->paths->hostIdFile()));
        $secret = \trim((string)\file_get_contents($this->paths->adminTokenFile()));
        $key = \hex2bin($secret);
        self::assertIsString($key);
        $body = "WLS-ADMIN-STOPPED/1\n"
            . 'host_id=' . $hostId . "\n"
            . 'epoch=' . $epoch . "\n"
            . 'at=' . \time() . "\n"
            . 'nonce=' . \bin2hex(\random_bytes(16)) . "\n";
        $intent = $body . 'signature=' . \hash_hmac('sha256', $body, $key)
            . "\n";
        \sodium_memzero($key);
        self::assertNotFalse(\file_put_contents(
            $this->paths->adminStoppedIntentFile(),
            $intent,
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($this->paths->adminStoppedIntentFile(), 0600));
        }
        return $intent;
    }

    private function certificateAuthority(string $identity = 'primary'): string
    {
        if (isset($this->certificateTrustBundles[$identity])) {
            return $this->certificateTrustBundles[$identity];
        }
        self::assertMatchesRegularExpression(
            '/\A[a-z0-9-]{1,32}\z/D',
            $identity,
        );
        $config = $this->root . DIRECTORY_SEPARATOR . 'openssl-ca-'
            . $identity . '.cnf';
        self::assertNotFalse(\file_put_contents(
            $config,
            <<<'CONFIG'
[ req ]
distinguished_name = req_distinguished_name
prompt = no
x509_extensions = v3_ca

[ req_distinguished_name ]
CN = WLS Host Gateway Test Root
O = Weline Test

[ v3_ca ]
basicConstraints = critical,CA:TRUE
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always,issuer
CONFIG
                . PHP_EOL,
        ));
        $options = [
            'config' => $config,
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'x509_extensions' => 'v3_ca',
        ];
        $key = \openssl_pkey_new($options);
        self::assertNotFalse($key);
        $csr = \openssl_csr_new([], $key, $options);
        self::assertNotFalse($csr);
        $certificate = \openssl_csr_sign(
            $csr,
            null,
            $key,
            3650,
            $options,
            1,
        );
        self::assertNotFalse($certificate);
        $pem = '';
        self::assertTrue(\openssl_x509_export($certificate, $pem, true));
        return $this->certificateTrustBundles[$identity] = \rtrim($pem) . "\n";
    }

    private function createPackage(
        string $suffix = 'valid',
        bool $incompatibleLauncher = false,
        string $certificateAuthorityIdentity = 'primary',
    ): string
    {
        $package = $this->root . DIRECTORY_SEPARATOR . 'package-' . $suffix;
        self::assertTrue(\mkdir($package . DIRECTORY_SEPARATOR . 'app', 0700, true));
        self::assertTrue(\mkdir($package . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        self::assertTrue(\mkdir($package . DIRECTORY_SEPARATOR . 'share', 0700, true));
        $files = [
            'app/controller.php' => [
                "<?php echo \"controller-{$suffix}\\n\";\n",
                0644,
            ],
            'bin/' . $this->binaryName('php') => ["#!/bin/sh\nexit 0\n", 0755],
            'bin/' . $this->binaryName('nginx') => ["#!/bin/sh\nexit 0\n", 0755],
            'bin/' . $this->binaryName('wls-gateway-broker') => ["#!/bin/sh\nexit 0\n", 0755],
            'bin/' . $this->binaryName('wls-gateway-launcher') => [
                $this->gatewayLauncherFixtureScript($incompatibleLauncher),
                0755,
            ],
            'LICENSES.txt' => ["WLS test package license inventory\n", 0644],
            'share/ca-bundle.pem' => [
                $this->certificateAuthority($certificateAuthorityIdentity),
                0644,
            ],
            'provenance.json' => [
                \json_encode([
                    'schema_version' => 1,
                    'target' => [
                        'platform' => \PHP_OS_FAMILY,
                        'arch' => $this->normalizedArch(),
                    ],
                    'components' => [],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                0644,
            ],
            'sbom.cdx.json' => [
                \json_encode([
                    'bomFormat' => 'CycloneDX',
                    'specVersion' => '1.5',
                    'version' => 1,
                    'components' => [['type' => 'application', 'name' => 'wls-gateway-test']],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                0644,
            ],
        ];
        if (\PHP_OS_FAMILY === 'Windows') {
            $files['bin/wls-bounded-command.exe'] = ["fixture\r\n", 0755];
        }
        $components = [];
        foreach ($files as $relative => [$contents, $mode]) {
            $file = $package . DIRECTORY_SEPARATOR
                . \str_replace('/', DIRECTORY_SEPARATOR, $relative);
            self::assertNotFalse(\file_put_contents($file, $contents));
            self::assertTrue(\chmod($file, $mode));
            $components[$relative] = [
                'sha256' => \hash_file('sha256', $file),
                'size' => \filesize($file),
                'mode' => $mode,
            ];
        }
        $capabilities = [];
        foreach ([
            'broker_sideband_actions',
            'certificate_public_trust_bundle',
            'certificate_snapshot_seal',
            'dual_control_channels',
            'native_peer_identity',
            'neutral_default_certificate',
            'no_follow_snapshot',
            'physical_rebootstrap_capacity_reserve',
            'privilege_separation',
            'self_contained_nginx',
            'self_contained_php',
            'singleton_fencing',
            'stable_launcher_rollback_target_proof',
        ] as $capability) {
            $capabilities[$capability] = true;
        }
        $manifest = [
            'schema_version' => 2,
            'version' => '2.0.0-test',
            'platform' => \PHP_OS_FAMILY,
            'arch' => $this->normalizedArch(),
            'protocol_min' => 2,
            'protocol_max' => 2,
            'security_profile' => 'native-broker-v1',
            'implementation_level' => 'wls-2.0',
            'durable_state_contract'
                => HostGatewayPackageManager::DURABLE_STATE_CONTRACT,
            'package_profile' => 'test',
            'release_ready' => false,
            'signing_key_id' => '',
            'listen_profiles' => ['default', 'ipv4-only'],
            'capabilities' => $capabilities,
            'components' => $components,
        ];
        self::assertNotFalse(\file_put_contents(
            $package . DIRECTORY_SEPARATOR . 'manifest.json',
            \json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ));
        return $package;
    }

    private function gatewayLauncherFixtureScript(
        bool $incompatibleLauncher,
    ): string {
        $marker = $incompatibleLauncher
            ? 'incompatible-stable-launcher'
            : 'stable-host-launcher-v2';
        return \str_replace('__MARKER__', $marker, <<<'SH'
#!/bin/sh
# __MARKER__
operation=''
home=''
nonce=''
bytes=''
inodes=''
for argument in "$@"; do
    case "$argument" in
        --capacity-reserve-contract-self-test) exit 0 ;;
        --capacity-reserve=*) operation=${argument#*=} ;;
        --home=*) home=${argument#*=} ;;
        --nonce=*) nonce=${argument#*=} ;;
        --bytes=*) bytes=${argument#*=} ;;
        --inodes=*) inodes=${argument#*=} ;;
    esac
done
if [ -z "$operation" ]; then
    exit 0
fi
if [ "$bytes" != '8388608' ] || [ "$inodes" != '128' ]; then
    exit 64
fi
capacity="$home/rebootstrap/capacity"
held="$capacity/$nonce.held"
releasing="$capacity/$nonce.releasing"
allocating="$capacity/$nonce.allocating"
proof='{"anchor_set_sha256":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","entry_set_sha256":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","inode_count":128,"physical_bytes":8388608,"state":"%s","volume_id":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"}\n'
case "$operation" in
    inspect)
        if [ -f "$home/state/$nonce.inspect-malformed" ]; then
            printf '%s\n' '{"schema":"wls-capacity-inspect/1","state":"NONE","extra":true}'
            exit 0
        fi
        state='NONE'
        count=0
        for state_pair in allocating held releasing; do
            case "$state_pair" in
                allocating) state_name='ALLOCATING'; target="$allocating" ;;
                held) state_name='HELD'; target="$held" ;;
                releasing) state_name='RELEASING'; target="$releasing" ;;
            esac
            if [ -e "$target" ] || [ -L "$target" ]; then
                [ -d "$target" ] || exit 77
                count=$((count + 1))
                state="$state_name"
            fi
        done
        [ "$count" -le 1 ] || exit 78
        printf '{"schema":"wls-capacity-inspect/1","state":"%s"}\n' "$state"
        ;;
    create)
        mkdir -p "$capacity" || exit 1
        if [ -d "$held" ]; then
            [ ! -e "$allocating" ] && [ ! -L "$allocating" ] || exit 78
            [ ! -e "$releasing" ] && [ ! -L "$releasing" ] || exit 78
        else
            [ ! -e "$releasing" ] && [ ! -L "$releasing" ] || exit 1
            if [ -d "$allocating" ]; then
                rm -rf -- "$allocating" || exit 1
            elif [ -e "$allocating" ] || [ -L "$allocating" ]; then
                exit 77
            fi
            mkdir "$allocating" || exit 1
            mv "$allocating" "$held" || exit 1
        fi
        printf "$proof" 'HELD'
        ;;
    verify)
        [ ! -f "$home/state/$nonce.force-release-transition" ] || exit 75
        [ -d "$held" ] || exit 1
        printf "$proof" 'HELD'
        ;;
    begin-release)
        if [ -d "$held" ]; then
            mv "$held" "$releasing" || exit 1
        fi
        [ -d "$releasing" ] || exit 1
        printf "$proof" 'RELEASING'
        ;;
    complete-release)
        # A HELD tree is not cancellation authority. The real POSIX and
        # Windows helpers require the authenticated begin-release transition
        # before they will delete it.
        [ ! -d "$held" ] || exit 1
        for target in "$allocating" "$held" "$releasing"; do
            if [ -d "$target" ]; then
                rm -rf -- "$target" || exit 1
            elif [ -e "$target" ] || [ -L "$target" ]; then
                exit 1
            fi
        done
        printf '%s\n' '{"state":"RELEASED"}'
        ;;
    *) exit 64 ;;
esac
SH
        );
    }

    /** @param \Closure(array<string,mixed>):array<string,mixed> $rewrite */
    private function rewriteInstalledManifest(string $slot, \Closure $rewrite): void
    {
        $file = $this->paths->slotDir($slot) . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifest = \json_decode(
            (string)\file_get_contents($file),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        $manifest = $rewrite($manifest);
        unset($manifest['runtime_generation']);
        $manifest['runtime_generation'] = \hash(
            'sha256',
            \json_encode(
                $this->canonicalizeArtifactManifest($manifest),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
        self::assertNotFalse(\file_put_contents(
            $file,
            \json_encode(
                $manifest,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL,
        ));
        if (\PHP_OS_FAMILY !== 'Windows') {
            self::assertTrue(\chmod($file, 0600));
        }
    }

    /** @param array<string|int,mixed> $value @return array<string|int,mixed> */
    private function canonicalizeArtifactManifest(array $value): array
    {
        foreach ($value as $key => $item) {
            if (\is_array($item)) {
                $value[$key] = $this->canonicalizeArtifactManifest($item);
            }
        }
        if (!\array_is_list($value)) {
            \ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function binaryName(string $name): string
    {
        return $name . (\PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    /**
     * @param list<string> $command
     * @return array{code:int,output:string}
     */
    private function runCommand(array $command, float $timeoutSeconds = 10.0): array
    {
        $process = \proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($process)) {
            return ['code' => 127, 'output' => 'Unable to start command.'];
        }
        foreach ($pipes as $pipe) {
            \stream_set_blocking($pipe, false);
        }
        $deadline = \hrtime(true) + (int)\round($timeoutSeconds * 1_000_000_000);
        $output = '';
        $exitCode = -1;
        for (;;) {
            $status = \proc_get_status($process);
            foreach ($pipes as $pipe) {
                $chunk = \stream_get_contents($pipe);
                if (\is_string($chunk)) {
                    $output .= $chunk;
                }
            }
            if (!(bool)($status['running'] ?? false)) {
                $exitCode = (int)($status['exitcode'] ?? -1);
                break;
            }
            if (\hrtime(true) >= $deadline) {
                @\proc_terminate($process);
                $terminateDeadline = \hrtime(true) + 1_000_000_000;
                do {
                    $status = \proc_get_status($process);
                    if (!(bool)($status['running'] ?? false)) {
                        break;
                    }
                    \usleep(25_000);
                } while (\hrtime(true) < $terminateDeadline);
                if ((bool)($status['running'] ?? false)) {
                    @\proc_terminate($process, 9);
                    $killDeadline = \hrtime(true) + 1_000_000_000;
                    do {
                        $status = \proc_get_status($process);
                        if (!(bool)($status['running'] ?? false)) {
                            break;
                        }
                        \usleep(25_000);
                    } while (\hrtime(true) < $killDeadline);
                }
                foreach ($pipes as $pipe) {
                    @\fclose($pipe);
                }
                if (!(bool)($status['running'] ?? false)) {
                    @\proc_close($process);
                }
                return ['code' => 124, 'output' => \trim($output . "\nCommand timed out.")];
            }
            \usleep(25_000);
        }
        foreach ($pipes as $pipe) {
            $chunk = \stream_get_contents($pipe);
            if (\is_string($chunk)) {
                $output .= $chunk;
            }
            @\fclose($pipe);
        }
        $closed = \proc_close($process);
        return [
            'code' => $exitCode >= 0 ? $exitCode : $closed,
            'output' => \trim($output),
        ];
    }

    private function normalizedArch(): string
    {
        return match (\strtolower((string)\php_uname('m'))) {
            'amd64', 'x86_64' => 'x86_64',
            'aarch64', 'arm64' => 'arm64',
            default => \strtolower((string)\php_uname('m')),
        };
    }

    private function captureRuntimeException(\Closure $operation): \RuntimeException
    {
        $exception = null;
        try {
            $operation();
        } catch (\RuntimeException $caught) {
            $exception = $caught;
        }
        self::assertInstanceOf(\RuntimeException::class, $exception);
        return $exception;
    }

    private function removeTree(string $root): void
    {
        if (!\is_dir($root) || \is_link($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $item->isDir() && !$item->isLink() ? @\rmdir($path) : @\unlink($path);
        }
        @\rmdir($root);
    }
}
