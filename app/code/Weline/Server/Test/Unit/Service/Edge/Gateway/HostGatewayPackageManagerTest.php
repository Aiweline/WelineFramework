<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Gateway;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayClient;
use Weline\Server\Service\Edge\Gateway\GatewayHostManager;
use Weline\Server\Service\Edge\Gateway\GatewayPaths;
use Weline\Server\Service\Edge\Gateway\GatewayPlatformServiceInstaller;
use Weline\Server\Service\Edge\Gateway\HostGatewayPackageManager;

final class HostGatewayPackageManagerTest extends TestCase
{
    /** @var array<string,string|false> */
    private array $environment = [];
    private string $root = '';
    private GatewayPaths $paths;

    protected function setUp(): void
    {
        foreach ([
            'WLS_GATEWAY_TEST_MODE',
            'WLS_GATEWAY_HOME',
            'WLS_GATEWAY_LISTEN_HTTP',
            'WLS_GATEWAY_LISTEN_HTTPS',
        ] as $name) {
            $this->environment[$name] = \getenv($name);
        }
        $this->root = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-gateway-package-'
            . \bin2hex(\random_bytes(8));
        \putenv('WLS_GATEWAY_TEST_MODE=1');
        \putenv('WLS_GATEWAY_HOME=' . $this->root . DIRECTORY_SEPARATOR . 'host');
        \putenv('WLS_GATEWAY_LISTEN_HTTP=22080');
        \putenv('WLS_GATEWAY_LISTEN_HTTPS=22443');
        $this->paths = new GatewayPaths();
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? \putenv($name) : \putenv($name . '=' . $value);
        }
        $this->removeTree($this->root);
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
            self::assertStringContainsString('relative and contained', $exception->getMessage());
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

    public function testUpgradeRollbackRejectsAForgedSignedIntent(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('intent-initial'), 'default');
        $manager->activate($initial['slot']);
        $candidate = $manager->stage($this->createPackage('intent-candidate'), 'default');
        $manager->beginUpgradeActivation($candidate);
        $intentFile = $this->paths->upgradeIntentFile();
        $intent = (string)\file_get_contents($intentFile);
        self::assertMatchesRegularExpression('/signature=[a-f0-9]{64}\n\z/D', $intent);
        $lastSignatureOffset = \strlen($intent) - 2;
        $intent[$lastSignatureOffset] = $intent[$lastSignatureOffset] === 'f' ? 'e' : 'f';
        self::assertNotFalse(\file_put_contents($intentFile, $intent));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');
        $manager->rollbackUpgradeActivation(
            $candidate['slot'],
            $initial['slot'],
        );
    }

    public function testPlatformServiceDefinitionUsesStableLauncherAndSystemScopeContract(): void
    {
        $installer = new GatewayPlatformServiceInstaller($this->paths);
        $installed = $installer->installDefinition('ipv4-only');

        self::assertSame('test-session', $installed['kind']);
        self::assertTrue($installed['test_mode']);
        $definition = (string)\file_get_contents($installed['path']);
        self::assertStringContainsString($this->paths->launcherFile(), $definition);
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
        self::assertDirectoryExists(
            $project . DIRECTORY_SEPARATOR . 'var'
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
        self::assertStringContainsString($this->paths->launcherFile(), $definition);
        self::assertSame(
            0600,
            \fileperms($this->paths->serviceDefinitionFile()) & 0777,
        );
        $intent = (string)\file_get_contents($this->paths->upgradeIntentFile());
        self::assertSame(1, \preg_match(
            '/\A(WLS-UPGRADE\\/1\\n'
                . 'host_id=[a-f0-9]{32}\\n'
                . 'from=[AB]\\n'
                . 'to=[AB]\\n'
                . 'prepared_at=[0-9]+\\n'
                . 'deadline=[0-9]+\\n'
                . 'runtime_generation=[a-f0-9]{64}\\n'
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

    public function testIncompatibleStableLauncherIsRejectedBeforeSlotActivation(): void
    {
        $manager = new HostGatewayPackageManager($this->paths);
        $initial = $manager->stage($this->createPackage('stable-initial'), 'default');
        $manager->activate($initial['slot']);
        try {
            $manager->stage(
                $this->createPackage('stable-incompatible', true),
                'default',
            );
            self::fail('An incompatible stable launcher must be rejected before activation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'requires a different stable launcher',
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
        self::assertStringContainsString(
            '->secureInstalledRuntimeSlot($slotDirectory);',
            $packages,
        );
        self::assertStringContainsString('package-install.lock', $packages);
        self::assertStringNotContainsString(
            "stateDir() . DIRECTORY_SEPARATOR . 'install.lock'",
            $packages,
        );
        self::assertStringContainsString(
            'public function secureInstalledRuntimeSlot(string $slotDirectory): void',
            $platform,
        );
        self::assertStringContainsString(
            'initial installation cannot deadlock on its own ordering',
            $platform,
        );
        self::assertStringContainsString(
            '$item->isDir() ? 0750 : ($item->isExecutable() ? 0550 : 0440)',
            $platform,
        );
        self::assertStringContainsString(
            'An installed stable launcher can predate the current project',
            $platform,
        );
        self::assertStringContainsString('$this->restart($kind);', $platform);
        self::assertStringNotContainsString("'--kill-whom=main'", $platform);
        self::assertStringNotContainsString("'control', self::SERVICE_NAME, '6'", $platform);
        self::assertStringContainsString('$this->waitForWindowsServiceState(1);', $platform);
        self::assertStringContainsString('$this->waitForWindowsServiceState(4);', $platform);
        self::assertStringContainsString("'unrestricted'", $platform);
        self::assertStringContainsString("'qsidtype'", $platform);
        self::assertStringContainsString('Set-WlsExactAcl', $platform);
        self::assertStringContainsString('AreAccessRulesProtected', $platform);
        self::assertStringContainsString("'/setowner'", $platform);
        self::assertStringContainsString("['/bin/chmod', '-RN'", $platform);
        self::assertStringContainsString('WLS_CONTROL_TREE_RELOAD', $posix);
        self::assertStringContainsString('signal_number == SIGHUP', $posix);
        self::assertStringContainsString(
            'wls_reap_controller(controller_pid, 0, NULL) == 0',
            $posixBroker,
        );
        self::assertStringContainsString('wls_release_controller_socket(controller_socket)', $posixBroker);
        self::assertStringContainsString('broker controller restart attempt', $posixBroker);
        self::assertStringContainsString('broker controller restart ready', $posixBroker);
        self::assertStringContainsString('broker controller restart exhausted', $posixBroker);
        self::assertStringContainsString('pthread_join(thread, NULL)', $posixBroker);
        self::assertStringContainsString('PR_SET_NO_NEW_PRIVS', $posixBroker);
        self::assertStringContainsString('CAP_NET_BIND_SERVICE', $posixBroker);
        self::assertStringContainsString('broker-fencing-token', $posixBroker);
        self::assertStringContainsString('\"action_protocol\":2', $posixBroker);
        self::assertStringContainsString('SECURITY_RESERVE', $posixBroker);
        self::assertStringContainsString('AUTH_PREPARE', $posixBroker);
        self::assertStringContainsString('ATOMIC_REPLACE', $posixBroker);
        self::assertStringContainsString('PROCESS_ATTEST', $posixBroker);
        self::assertStringContainsString('WLS-UPGRADE-STATE/2', $posix);
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
                < \strpos($windowsControl, 'wls_report_service(SERVICE_STOP_PENDING'),
            'Windows stop ownership must be visible before STOP_PENDING can race Broker exit.',
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
        self::assertStringContainsString('PROCESS_ATTEST', $broker);
        self::assertStringContainsString('WLS-UPGRADE-STATE/2', $windows);
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
        self::assertStringContainsString(
            "const WLS_WINDOWS_TEST_SERVICE = 'weline-wls-gateway-v2';",
            $fixture,
        );
        self::assertStringContainsString('Refusing to replace an existing', $fixture);
        self::assertStringContainsString('wlsMkdirExclusive(', $fixture);
        self::assertStringContainsString('wlsWriteExclusive(', $fixture);
        self::assertStringContainsString("'sidtype'", $fixture);
        self::assertStringContainsString("'failureflag'", $fixture);
        self::assertStringContainsString('wlsWaitStarts(', $fixture);
        self::assertStringContainsString(
            'wlsWrite($fixture[\'hold\'], "hold\\n");',
            $fixture,
        );
        self::assertStringContainsString('wlsWaitServiceDeleted(', $fixture);
        self::assertStringContainsString('Explicit SCM stop incorrectly triggered recovery', $fixture);
        self::assertStringContainsString('Broad-readable private key was accepted', $fixture);
        self::assertStringContainsString(
            'Unrelated readable SID on a private key was accepted',
            $fixture,
        );
        self::assertStringContainsString('Source reparse point was followed', $fixture);
        self::assertStringContainsString('Destination reparse point was followed', $fixture);
        self::assertStringContainsString('WLS_BUILD_TEST_HELPERS=ON', $workflow);
        self::assertStringContainsString('NativeGatewayBrokerTest.php', $workflow);
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
        self::assertStringContainsString('Windows SCM recovery integration', $workflow);
        self::assertStringContainsString(
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

    private function createPackage(
        string $suffix = 'valid',
        bool $incompatibleLauncher = false,
    ): string
    {
        $package = $this->root . DIRECTORY_SEPARATOR . 'package-' . $suffix;
        self::assertTrue(\mkdir($package . DIRECTORY_SEPARATOR . 'app', 0700, true));
        self::assertTrue(\mkdir($package . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        $files = [
            'app/controller.php' => [
                "<?php echo \"controller-{$suffix}\\n\";\n",
                0600,
            ],
            'bin/' . $this->binaryName('php') => ["#!/bin/sh\nexit 0\n", 0755],
            'bin/' . $this->binaryName('nginx') => ["#!/bin/sh\nexit 0\n", 0755],
            'bin/' . $this->binaryName('wls-gateway-broker') => ["#!/bin/sh\nexit 0\n", 0755],
            'bin/' . $this->binaryName('wls-gateway-launcher') => [
                $incompatibleLauncher
                    ? "#!/bin/sh\n# incompatible-stable-launcher\nexit 0\n"
                    : "#!/bin/sh\n# stable-host-launcher-v2\nexit 0\n",
                0755,
            ],
            'LICENSES.txt' => ["WLS test package license inventory\n", 0644],
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
            'dual_control_channels',
            'native_peer_identity',
            'neutral_default_certificate',
            'no_follow_snapshot',
            'privilege_separation',
            'self_contained_nginx',
            'self_contained_php',
            'singleton_fencing',
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
