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
        self::assertFileExists($this->paths->launcherFile());

        $manager->activate($staged['slot']);
        self::assertSame($staged['slot'], $this->paths->activeSlot());
        if (\PHP_OS_FAMILY !== 'Windows') {
            $parent = \stat($this->paths->trustDir());
            self::assertIsArray($parent);
            foreach ([
                $this->paths->activeSlotFile(),
                $this->paths->previousSlotFile(),
            ] as $stateFile) {
                $state = \stat($stateFile);
                self::assertIsArray($state);
                self::assertSame($parent['uid'], $state['uid']);
                self::assertSame($parent['gid'], $state['gid']);
            }
        }
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

    private function createPackage(string $suffix = 'valid'): string
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
                "#!/bin/sh\n# {$suffix}\nexit 0\n",
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
