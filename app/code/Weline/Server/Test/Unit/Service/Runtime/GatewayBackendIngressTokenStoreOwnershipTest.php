<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Gateway\GatewayBackendIngressTokenStore;

final class GatewayBackendIngressTokenStoreOwnershipTest extends TestCase
{
    public function testEventConfiguratorIsNoOpWhenRuntimeIsAlreadyUsable(): void
    {
        $probeCalled = false;
        $bootstrapper = new \Weline\Server\Service\Runtime\RuntimeDependencyBootstrapper();
        $result = $bootstrapper->configureEventExtensionForRuntime(
            [
                'os_family' => 'Linux',
                'php_binary' => PHP_BINARY,
                'event_loaded' => true,
                'event_base_available' => true,
                'event_buffer_available' => true,
                'loaded_ini' => '/read-only/php.ini',
                'scan_dirs' => [],
                'extension_dir' => '/read-only/extensions',
                'extension_binary' => '/read-only/extensions/event.so',
            ],
            static function () use (&$probeCalled): array {
                $probeCalled = true;
                return [];
            },
        );

        self::assertSame('ready', $result['status']);
        self::assertFalse($result['changed']);
        self::assertFalse($probeCalled);
    }

    public function testEventConfiguratorPublishesIndependentIniAndVerifiesFreshChild(): void
    {
        [$root, $extensionDirectory, $scanDirectory, $extensionBinary] = $this->createEventConfiguratorFixture();
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        try {
            $bootstrapper = new \Weline\Server\Service\Runtime\RuntimeDependencyBootstrapper();
            $result = $bootstrapper->configureEventExtensionForRuntime(
                $this->eventConfiguratorRuntime($extensionDirectory, $scanDirectory, $extensionBinary),
                static function (string $phpBinary, string $publishedTarget) use ($target): array {
                    self::assertSame(PHP_BINARY, $phpBinary);
                    self::assertSame($target, $publishedTarget);
                    return [
                        'exit_code' => 0,
                        'loaded' => true,
                        'classes' => true,
                        'scanned_files' => [$publishedTarget],
                        'output' => '',
                        'stderr' => '',
                    ];
                },
            );

            self::assertSame('ready', $result['status']);
            self::assertTrue($result['changed']);
            self::assertSame("; Managed by WLS 2.0 explicit --install-deps\nextension=event\n", \file_get_contents($target));
            self::assertSame(0600, \fileperms($target) & 0777);
        } finally {
            $this->removeEventConfiguratorFixture($root, $extensionDirectory, $scanDirectory);
        }
    }

    public function testEventConfiguratorFailsClosedWithoutSafeScanDirectory(): void
    {
        [$root, $extensionDirectory, $scanDirectory, $extensionBinary] = $this->createEventConfiguratorFixture();
        try {
            $runtime = $this->eventConfiguratorRuntime($extensionDirectory, $scanDirectory, $extensionBinary);
            $runtime['scan_dirs'] = [];
            $bootstrapper = new \Weline\Server\Service\Runtime\RuntimeDependencyBootstrapper();
            $result = $bootstrapper->configureEventExtensionForRuntime($runtime);

            self::assertSame('failed', $result['status']);
            self::assertStringContainsString('loaded_ini=', $result['diagnostics']);
            self::assertStringContainsString('scan_dirs=', $result['diagnostics']);
            self::assertStringContainsString('extension_dir=', $result['diagnostics']);
        } finally {
            $this->removeEventConfiguratorFixture($root, $extensionDirectory, $scanDirectory);
        }
    }

    public function testEventConfiguratorRollsBackWhenFreshChildVerificationFails(): void
    {
        [$root, $extensionDirectory, $scanDirectory, $extensionBinary] = $this->createEventConfiguratorFixture();
        $target = $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini';
        try {
            $bootstrapper = new \Weline\Server\Service\Runtime\RuntimeDependencyBootstrapper();
            $result = $bootstrapper->configureEventExtensionForRuntime(
                $this->eventConfiguratorRuntime($extensionDirectory, $scanDirectory, $extensionBinary),
                static fn(): array => [
                    'exit_code' => 9,
                    'loaded' => false,
                    'classes' => false,
                    'scanned_files' => [],
                    'output' => '',
                    'stderr' => 'event unavailable',
                ],
            );

            self::assertSame('failed', $result['status']);
            self::assertFalse(\file_exists($target));
            self::assertStringContainsString('rollback=ok', $result['diagnostics']);
        } finally {
            $this->removeEventConfiguratorFixture($root, $extensionDirectory, $scanDirectory);
        }
    }

    public function testRuntimeDependencyBootstrapperConfiguresEventBeforeAndAfterInstall(): void
    {
        $source = (string)\file_get_contents(
            BP . 'app/code/Weline/Server/Service/Runtime/RuntimeDependencyBootstrapper.php'
        );

        self::assertGreaterThanOrEqual(2, \substr_count($source, 'configureEventExtensionForRuntime()'));
        self::assertStringContainsString('$dependency === \'event\'', $source);
        self::assertStringContainsString('!$reentry && $posix && !$this->canUseEvent()', $source);
    }

    /** @return array{string,string,string,string} */
    private function createEventConfiguratorFixture(): array
    {
        $root = \realpath(\sys_get_temp_dir()) . DIRECTORY_SEPARATOR . 'wls-event-config-' . \bin2hex(\random_bytes(8));
        $extensionDirectory = $root . DIRECTORY_SEPARATOR . 'extensions';
        $scanDirectory = $root . DIRECTORY_SEPARATOR . 'conf.d';
        self::assertTrue(\mkdir($extensionDirectory, 0700, true));
        self::assertTrue(\mkdir($scanDirectory, 0700, true));
        $extensionBinary = $extensionDirectory . DIRECTORY_SEPARATOR . 'event.so';
        self::assertNotFalse(\file_put_contents($extensionBinary, 'test-event-binary'));
        return [$root, $extensionDirectory, $scanDirectory, $extensionBinary];
    }

    /** @return array<string, mixed> */
    private function eventConfiguratorRuntime(
        string $extensionDirectory,
        string $scanDirectory,
        string $extensionBinary,
    ): array {
        return [
            'os_family' => 'Linux',
            'php_binary' => PHP_BINARY,
            'event_loaded' => false,
            'event_base_available' => false,
            'event_buffer_available' => false,
            'loaded_ini' => '/fixture/php.ini',
            'scan_dirs' => [$scanDirectory],
            'extension_dir' => $extensionDirectory,
            'extension_binary' => $extensionBinary,
        ];
    }

    private function removeEventConfiguratorFixture(
        string $root,
        string $extensionDirectory,
        string $scanDirectory,
    ): void {
        foreach ([
            $scanDirectory . DIRECTORY_SEPARATOR . '99-weline-event.ini',
            $scanDirectory . DIRECTORY_SEPARATOR . '.weline-event.ini.lock',
            $extensionDirectory . DIRECTORY_SEPARATOR . 'event.so',
        ] as $file) {
            if (\is_file($file) || \is_link($file)) {
                @\unlink($file);
            }
        }
        @\rmdir($scanDirectory);
        @\rmdir($extensionDirectory);
        @\rmdir($root);
    }

    /** @var list<string> */
    private array $cleanupFiles = [];
    /** @var list<string> */
    private array $cleanupDirectories = [];

    protected function setUp(): void
    {
        if (!\defined('BP')) {
            \define(
                'BP',
                \rtrim(\dirname(__DIR__, 8), '/\\') . DIRECTORY_SEPARATOR,
            );
        }
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }
        foreach ([
            'APP_PATH' => BP . 'app' . DS,
            'APP_ETC_PATH' => BP . 'app' . DS . 'etc' . DS,
            'PUB' => BP . 'pub' . DS,
            'VENDOR_PATH' => BP . 'vendor' . DS,
            'APP_CODE_PATH' => BP . 'app' . DS . 'code' . DS,
        ] as $name => $path) {
            if (!\defined($name)) {
                \define($name, $path);
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->cleanupFiles as $file) {
            if (\is_link($file) || \is_file($file)) {
                @\unlink($file);
            }
        }
        foreach (\array_reverse($this->cleanupDirectories) as $directory) {
            @\rmdir($directory);
        }
    }

    public function testTlsAlpnProbeVerifiesAdvertisedProtocolContract(): void
    {
        if (!\extension_loaded('openssl')
            || !\defined('OPENSSL_KEYTYPE_EC')
            || !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')
            || !\defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')
            || !\function_exists('openssl_pkey_new')
            || !\function_exists('stream_socket_server')
            || !\function_exists('stream_socket_client')
        ) {
            self::markTestSkipped('The OpenSSL EC, TLS 1.3, and stream socket runtime is unavailable.');
        }

        $probe = new \Weline\Server\Service\Runtime\TlsAlpnRuntimeProbe();
        if (!$probe->configured()) {
            self::markTestSkipped('The PHP stream runtime does not expose ALPN configuration support.');
        }

        $snapshot = $probe->snapshot();
        $reason = (string)($snapshot['reason'] ?? 'No ALPN probe reason was reported.');

        self::assertTrue((bool)($snapshot['runtime_verified'] ?? false), $reason);
        self::assertTrue((bool)($snapshot['tls13_runtime_verified'] ?? false), $reason);
        self::assertSame('h2', $snapshot['negotiated_protocols']['preferred'] ?? null);
        self::assertSame('http/1.1', $snapshot['negotiated_protocols']['fallback'] ?? null);
        self::assertSame('TLSv1.3', $snapshot['tls_protocols']['preferred'] ?? null);
        self::assertSame('TLSv1.3', $snapshot['tls_protocols']['fallback'] ?? null);
    }

    public function testRuntimeDirectoryRejectsSymbolicLinkTraversal(): void
    {
        if (\PHP_OS_FAMILY === 'Windows' || !\function_exists('symlink')) {
            self::markTestSkipped('Symbolic-link ownership rules are POSIX-specific.');
        }
        $instance = 'phpunit-edge-link-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        if (!\is_dir(\dirname($directory))) {
            self::assertTrue(@\mkdir(\dirname($directory), 0700, true));
        }
        $target = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . $instance;
        self::assertTrue(@\mkdir($target, 0700));
        $this->cleanupDirectories[] = $target;
        self::assertTrue(@\symlink($target, $directory));
        $this->cleanupFiles[] = $directory;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('symbolic link');
        GatewayBackendIngressTokenStore::ensureTokenFile($instance);
    }

    public function testRootInvocationRestoresProjectOwnerOnRuntimeFacts(): void
    {
        if (\PHP_OS_FAMILY === 'Windows'
            || !\function_exists('posix_geteuid')
            || \posix_geteuid() !== 0
        ) {
            self::markTestSkipped('Root ownership repair requires a POSIX root test process.');
        }
        $project = @\lstat((string)BP);
        if (!\is_array($project)
            || !\is_int($project['uid'] ?? null)
            || !\is_int($project['gid'] ?? null)
            || (int)$project['uid'] === 0
        ) {
            self::markTestSkipped('The test project must be owned by a non-root project user.');
        }
        $instance = 'phpunit-edge-root-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        self::assertTrue(@\mkdir($directory, 0700));
        $this->cleanupDirectories[] = $directory;
        $before = @\lstat($directory);
        self::assertIsArray($before);
        self::assertSame(0, (int)$before['uid']);

        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $this->trackGatewayBackendIngressTokenStore($directory, [$token]);
        $stateLock = $directory . DIRECTORY_SEPARATOR . '.state.lock';
        $directoryState = @\lstat($directory);
        $tokenState = @\lstat($token);
        $lockState = @\lstat($stateLock);
        self::assertIsArray($directoryState);
        self::assertIsArray($tokenState);
        self::assertIsArray($lockState);
        self::assertSame((int)$project['uid'], (int)$directoryState['uid']);
        self::assertSame((int)$project['gid'], (int)$directoryState['gid']);
        self::assertSame((int)$project['uid'], (int)$tokenState['uid']);
        self::assertSame((int)$project['gid'], (int)$tokenState['gid']);
        self::assertSame((int)$project['uid'], (int)$lockState['uid']);
        self::assertSame((int)$project['gid'], (int)$lockState['gid']);
        self::assertSame(0700, ((int)$directoryState['mode']) & 0777);
        self::assertSame(0600, ((int)$tokenState['mode']) & 0777);
        self::assertSame(0600, ((int)$lockState['mode']) & 0777);
    }

    public function testTokenAccessCollectsRetainedBackupOnlyForAValidCurrentToken(): void
    {
        $instance = 'phpunit-edge-token-recovery-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $backup = $token . '.wls-backup-' . \str_repeat('a', 16);
        self::assertNotFalse(\file_put_contents($backup, \str_repeat('b', 64) . PHP_EOL));
        $this->trackGatewayBackendIngressTokenStore($directory, [$token, $backup]);

        self::assertSame($token, GatewayBackendIngressTokenStore::ensureTokenFile($instance));
        self::assertFileDoesNotExist($backup);
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            \trim((string)\file_get_contents($token)),
        );
    }

    public function testTokenPathAndDigestRemainStableAcrossRepeatedEnsure(): void
    {
        $instance = 'phpunit-gateway-ingress-token-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $this->trackGatewayBackendIngressTokenStore($directory, [$token]);
        $first = GatewayBackendIngressTokenStore::readToken($instance);

        self::assertStringEndsWith(
            'var' . DIRECTORY_SEPARATOR . 'server' . DIRECTORY_SEPARATOR
                . 'gateway-backend' . DIRECTORY_SEPARATOR . $instance
                . DIRECTORY_SEPARATOR . 'ingress.token',
            $token,
        );
        self::assertSame($token, GatewayBackendIngressTokenStore::ensureTokenFile($instance));
        self::assertSame($first, GatewayBackendIngressTokenStore::readToken($instance));
        self::assertSame(\hash('sha256', $first), GatewayBackendIngressTokenStore::digest($instance));
        if (PHP_OS_FAMILY !== 'Windows') {
            self::assertSame(0700, (int)\fileperms($directory) & 0777);
            self::assertSame(0600, (int)\fileperms($token) & 0777);
        }
    }

    public function testConfiguredTokenFileResolvesCanonicalToken(): void
    {
        $instance = 'phpunit-gateway-ingress-config-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $this->trackGatewayBackendIngressTokenStore($directory, [$token]);

        $configured = GatewayBackendIngressTokenStore::resolveConfiguredTokenFile($token);

        self::assertSame($token, $configured['path']);
        self::assertSame(GatewayBackendIngressTokenStore::readToken($instance), $configured['token']);
    }

    public function testCanonicalEnvironmentNameResolvesToken(): void
    {
        $instance = 'phpunit-gateway-ingress-env-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $this->trackGatewayBackendIngressTokenStore($directory, [$token]);

        $configured = $this->withTokenEnvironment([
            'WLS_GATEWAY_BACKEND_TOKEN_FILE' => $token,
        ], static fn(): array => GatewayBackendIngressTokenStore::resolveConfiguredTokenEnvironment());

        self::assertSame($token, $configured['path']);
        self::assertSame(GatewayBackendIngressTokenStore::readToken($instance), $configured['token']);
    }

    public function testConflictingSourcesForOneEnvironmentNameFailClosed(): void
    {
        $instance = 'phpunit-gateway-ingress-env-conflict-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $otherInstance = 'phpunit-gateway-ingress-env-conflict-other-' . \bin2hex(\random_bytes(6));
        $otherDirectory = GatewayBackendIngressTokenStore::runtimeDirectory($otherInstance);
        $otherToken = GatewayBackendIngressTokenStore::ensureTokenFile($otherInstance);
        $this->trackGatewayBackendIngressTokenStore($directory, [$token]);
        $this->trackGatewayBackendIngressTokenStore($otherDirectory, [$otherToken]);

        $caught = $this->withTokenEnvironment([
            'WLS_GATEWAY_BACKEND_TOKEN_FILE' => $token,
        ], static function () use ($otherToken): ?\Throwable {
            $_SERVER['WLS_GATEWAY_BACKEND_TOKEN_FILE'] = $otherToken;
            try {
                GatewayBackendIngressTokenStore::resolveConfiguredTokenEnvironment();
            } catch (\Throwable $throwable) {
                return $throwable;
            }
            return null;
        });

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertStringContainsString('conflicting environment', $caught->getMessage());
    }

    public function testHardLinkedCanonicalTokenFailsClosed(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !\function_exists('link')) {
            self::markTestSkipped('Hard-link fixture is POSIX-specific.');
        }
        $instance = 'phpunit-gateway-ingress-hardlink-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $hardLink = $token . '.hardlink';
        self::assertTrue(@\link($token, $hardLink));
        $this->trackGatewayBackendIngressTokenStore($directory, [$token, $hardLink]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('non-linked');
        GatewayBackendIngressTokenStore::ensureTokenFile($instance);
    }

    public function testExpiredTokenOperationDeadlineFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('deadline');
        GatewayBackendIngressTokenStore::ensureTokenFile(
            'phpunit-gateway-ingress-expired-' . \bin2hex(\random_bytes(6)),
            (\hrtime(true) / 1_000_000_000) - 1.0,
        );
    }

    public function testTokenAccessPreservesRetainedBackupWhenCurrentTokenIsMalformed(): void
    {
        $instance = 'phpunit-edge-token-corrupt-' . \bin2hex(\random_bytes(6));
        $directory = GatewayBackendIngressTokenStore::runtimeDirectory($instance);
        $token = GatewayBackendIngressTokenStore::ensureTokenFile($instance);
        $backup = $token . '.wls-backup-' . \str_repeat('c', 16);
        self::assertNotFalse(\file_put_contents($backup, \str_repeat('d', 64) . PHP_EOL));
        self::assertNotFalse(\file_put_contents($token, "broken\n"));
        $this->trackGatewayBackendIngressTokenStore($directory, [$token, $backup]);

        try {
            GatewayBackendIngressTokenStore::ensureTokenFile($instance);
            self::fail('A malformed current token must not authorize backup cleanup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString(
                'token',
                \strtolower($exception->getMessage()),
            );
        }
        self::assertFileExists($backup);
        self::assertSame("broken\n", (string)\file_get_contents($token));
    }

    /** @param list<string> $files */
    private function trackGatewayBackendIngressTokenStore(
        string $directory,
        array $files,
    ): void
    {
        $instance = \basename($directory);
        foreach ($files as $file) {
            $this->cleanupFiles[] = $file;
        }
        $this->cleanupFiles[] = GatewayBackendIngressTokenStore::tokenFile($instance);
        $this->cleanupFiles[] = $directory . DIRECTORY_SEPARATOR . '.state.lock';
        $this->cleanupDirectories[] = $directory;
        $sessionDirectory = $directory . DIRECTORY_SEPARATOR . 'stek';
        if (\is_dir($sessionDirectory)) {
            $this->cleanupDirectories[] = $sessionDirectory;
        }
    }

    /**
     * @template TResult
     * @param array<string,string> $values
     * @param \Closure():TResult $operation
     * @return TResult
     */
    private function withTokenEnvironment(array $values, \Closure $operation): mixed
    {
        $before = [];
        foreach ($values as $name => $value) {
            $before[$name] = [
                'server_exists' => \array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
                'env_exists' => \array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'process' => \getenv($name),
            ];
            if ($value === '') {
                unset($_SERVER[$name], $_ENV[$name]);
                @\putenv($name);
                continue;
            }
            $_SERVER[$name] = $value;
            $_ENV[$name] = $value;
            @\putenv($name . '=' . $value);
        }
        try {
            return $operation();
        } finally {
            foreach ($before as $name => $snapshot) {
                if ($snapshot['server_exists']) {
                    $_SERVER[$name] = $snapshot['server'];
                } else {
                    unset($_SERVER[$name]);
                }
                if ($snapshot['env_exists']) {
                    $_ENV[$name] = $snapshot['env'];
                } else {
                    unset($_ENV[$name]);
                }
                if (\is_string($snapshot['process'])) {
                    @\putenv($name . '=' . $snapshot['process']);
                } else {
                    @\putenv($name);
                }
            }
        }
    }
}
