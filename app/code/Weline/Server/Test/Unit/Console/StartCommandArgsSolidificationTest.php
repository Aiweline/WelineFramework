<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Console\Server\Start;
use Weline\Server\Service\ServerInstanceManager;
use Weline\Server\Service\SslCertificateService;

final class StartCommandArgsSolidificationTest extends TestCase
{
    public function testNoSslFlagForcesHttpOnlyMode(): void
    {
        $start = $this->createProbe();
        $config = $start->resolveConfig('default', ['no-ssl' => true]);

        self::assertTrue((bool)($config['no_ssl'] ?? false));
    }

    public function testHttpOnlyAliasAlsoForcesHttpOnlyMode(): void
    {
        $start = $this->createProbe();
        $config = $start->resolveConfig('default', ['http-only' => true]);

        self::assertTrue((bool)($config['no_ssl'] ?? false));
    }

    public function testDefaultPortPromotionOnlyAppliesWhenSslEnabled(): void
    {
        $start = $this->createProbe();

        self::assertSame(443, $start->normalizePortForSslState(80, true));
        self::assertSame(80, $start->normalizePortForSslState(80, true, true));
        self::assertSame(80, $start->normalizePortForSslState(80, false));
        self::assertSame(9981, $start->normalizePortForSslState(9981, true));
    }

    public function testStartupCertificateFilesReenableHttpsForReusablePublicHostCertificate(): void
    {
        $certDir = \sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wls-start-cert-' . \str_replace('.', '', \uniqid('', true)) . DIRECTORY_SEPARATOR;
        \mkdir($certDir, 0777, true);
        $certPath = $certDir . 'fullchain.pem';
        $keyPath = $certDir . 'privkey.pem';
        \file_put_contents($certPath, 'unit-cert');
        \file_put_contents($keyPath, 'unit-key');

        try {
            $sslService = $this->createMock(SslCertificateService::class);
            $sslService->expects($this->once())
                ->method('getCertificateDir')
                ->with('pre.example.com')
                ->willReturn($certDir);
            $sslService->expects($this->once())
                ->method('canReuseConfiguredCertificate')
                ->with($certPath, $keyPath)
                ->willReturn(true);
            $sslService->expects($this->once())
                ->method('certificateMatchesHost')
                ->with($certPath, 'pre.example.com')
                ->willReturn(true);
            $sslService->expects($this->once())
                ->method('syncCertificateRecordFromFiles')
                ->with('pre.example.com', $certPath, $keyPath, 0, true, '', false);
            $sslService->expects($this->once())
                ->method('regenerateCertificateMap')
                ->with(false);
            $sslService->expects($this->once())
                ->method('parseCertificate')
                ->with($certPath)
                ->willReturn(['issuer' => 'Unit CA', 'expires_at' => '2026-12-31 00:00:00']);

            $result = $this->createProbe()->useStartupCertificateFiles($sslService, 'pre.example.com', 'pre.example.com');

            self::assertIsArray($result);
            self::assertTrue((bool)($result['success'] ?? false));
            self::assertTrue((bool)($result['ssl_enabled'] ?? false));
            self::assertSame($certPath, $result['cert_path'] ?? null);
            self::assertSame($keyPath, $result['key_path'] ?? null);
        } finally {
            @\unlink($certPath);
            @\unlink($keyPath);
            @\rmdir($certDir);
        }
    }

    public function testPublicHostResolutionGuardAcceptsMatchingServerIp(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('isLocalDomain')->with('pre.example.com')->willReturn(false);
        $start = $this->createProbe(
            null,
            [],
            ['pre.example.com' => ['203.0.113.10']],
            ['203.0.113.10']
        );

        $result = $start->validatePublicHost($sslService, 'pre.example.com');

        self::assertTrue((bool)($result['success'] ?? false));
        self::assertSame(['203.0.113.10'], $result['resolved_ips'] ?? null);
        self::assertSame(['203.0.113.10'], $result['server_ips'] ?? null);
    }

    public function testPublicHostResolutionGuardRejectsOffServerDns(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('isLocalDomain')->with('pre.example.com')->willReturn(false);
        $start = $this->createProbe(
            null,
            [],
            ['pre.example.com' => ['203.0.113.10']],
            ['198.51.100.20']
        );

        $result = $start->validatePublicHost($sslService, 'pre.example.com');

        self::assertFalse((bool)($result['success'] ?? true));
        $message = (string)($result['message'] ?? '');
        self::assertStringContainsString('pre.example.com', $message);
        self::assertStringContainsString('203.0.113.10', $message);
        self::assertStringContainsString('198.51.100.20', $message);
    }

    public function testPublicHostResolutionGuardSkipsLocalDomains(): void
    {
        $sslService = $this->createMock(SslCertificateService::class);
        $sslService->method('isLocalDomain')->with('unit-test.weline.test')->willReturn(true);
        $start = $this->createProbe(
            null,
            [],
            ['unit-test.weline.test' => ['203.0.113.10']],
            []
        );

        $result = $start->validatePublicHost($sslService, 'unit-test.weline.test');

        self::assertTrue((bool)($result['success'] ?? false));
        self::assertTrue((bool)($result['skipped'] ?? false));
    }

    public function testNoDaemonRunsForegroundUnlessRestartRequested(): void
    {
        $start = $this->createProbe();

        $foregroundConfig = $start->resolveConfig('default', ['no-daemon' => true]);
        self::assertFalse((bool)($foregroundConfig['daemon'] ?? true));

        $restartConfig = $start->resolveConfig('default', ['no-daemon' => true, 'r' => true]);
        self::assertTrue((bool)($restartConfig['daemon'] ?? false));
    }

    public function testWorkerCountAcceptsBothLongAndShortFlags(): void
    {
        $start = $this->createProbe();

        $longFlagConfig = $start->resolveConfig('default', ['count' => '6']);
        self::assertSame(6, (int)($longFlagConfig['worker_count'] ?? 0));

        $shortFlagConfig = $start->resolveConfig('default', ['c' => '5']);
        self::assertSame(5, (int)($shortFlagConfig['worker_count'] ?? 0));
    }

    public function testWorkerMemoryLimitAcceptsEnvAndCliFlags(): void
    {
        $envConfig = $this->createProbe(null, ['wls' => ['worker_memory_limit' => '384m']])
            ->resolveConfig('default', []);
        self::assertSame('384M', (string)($envConfig['worker_memory_limit'] ?? ''));

        $cliConfig = $this->createProbe(null, ['wls' => ['worker_memory_limit' => '384M']])
            ->resolveConfig('default', ['worker-memory-limit' => '768']);
        self::assertSame('768M', (string)($cliConfig['worker_memory_limit'] ?? ''));
    }

    public function testPanelModeDefaultsMemoryTo512MWhenUnconfigured(): void
    {
        $config = $this->createProbe(null, ['wls' => ['panel' => ['enabled' => true]]])
            ->resolveConfig('default', []);

        self::assertSame('512M', (string)($config['worker_memory_limit'] ?? ''));
        self::assertSame('512M', (string)($config['dispatcher_memory_limit'] ?? ''));
    }

    public function testPanelModeCanBeEnabledByProcessEnvironment(): void
    {
        $previousEnabled = \getenv('WLS_PANEL_ENABLED');
        $previousMode = \getenv('WLS_PANEL_MODE');
        \putenv('WLS_PANEL_ENABLED=1');
        \putenv('WLS_PANEL_MODE');

        try {
            $config = $this->createProbe()
                ->resolveConfig('default', []);

            self::assertSame('512M', (string)($config['worker_memory_limit'] ?? ''));
            self::assertSame('512M', (string)($config['dispatcher_memory_limit'] ?? ''));
        } finally {
            $this->restoreEnvValue('WLS_PANEL_ENABLED', $previousEnabled);
            $this->restoreEnvValue('WLS_PANEL_MODE', $previousMode);
        }
    }

    public function testPanelModePreservesExplicitMemoryLimits(): void
    {
        $envConfig = $this->createProbe(
            null,
            [
                'wls' => [
                    'panel' => ['enabled' => true],
                    'worker_memory_limit' => '384m',
                    'dispatcher_memory_limit' => '448m',
                ],
            ]
        )->resolveConfig('default', []);

        self::assertSame('384M', (string)($envConfig['worker_memory_limit'] ?? ''));
        self::assertSame('448M', (string)($envConfig['dispatcher_memory_limit'] ?? ''));

        $cliConfig = $this->createProbe(null, ['wls' => ['panel' => ['enabled' => true]]])
            ->resolveConfig('default', ['worker-memory-limit' => '768']);
        self::assertSame('768M', (string)($cliConfig['worker_memory_limit'] ?? ''));
        self::assertSame('768M', (string)($cliConfig['dispatcher_memory_limit'] ?? ''));
    }

    public function testDispatcherMemoryLimitDefaultsToWorkerWhenSolidified(): void
    {
        $manager = new StartInstanceManagerProbe();
        $start = new StartInstanceInfoProbe($manager);

        $start->persistInstanceInfo('unit-memory');

        $info = $manager->savedInstances[0]['info'];
        self::assertSame('512M', $info['worker_memory_limit'] ?? null);
        self::assertSame('512M', $info['dispatcher_memory_limit'] ?? null);
    }

    public function testSslCertAndKeyCanBeProvidedViaCliFlags(): void
    {
        $start = $this->createProbe();
        $config = $start->resolveConfig('default', [
            'ssl-cert' => '/tmp/test-cert.pem',
            'ssl-key' => '/tmp/test-key.pem',
        ]);

        self::assertSame('/tmp/test-cert.pem', (string)($config['ssl_cert'] ?? ''));
        self::assertSame('/tmp/test-key.pem', (string)($config['ssl_key'] ?? ''));
    }

    public function testLegacyManagedLocalHostFallsBackToGeneratedProjectHost(): void
    {
        $start = $this->createProbe(
            ['host' => 'p11005ce4.weline.local', 'ssl_domain' => 'p11005ce4.weline.local']
        );
        $config = $start->resolveConfig('default', []);

        self::assertSame('unit-test.weline.test', (string)($config['host'] ?? ''));
        self::assertArrayNotHasKey('ssl_domain', $config);
    }

    public function testMissingHostAlsoFallsBackToGeneratedProjectHost(): void
    {
        $start = $this->createProbe(['host' => '', 'ssl_domain' => 'localhost']);
        $config = $start->resolveConfig('default', []);

        self::assertSame('unit-test.weline.test', (string)($config['host'] ?? ''));
        self::assertArrayNotHasKey('ssl_domain', $config);
    }

    public function testCustomHostIsPreservedWhenPresent(): void
    {
        $start = $this->createProbe(['host' => 'custom.example.test']);
        $config = $start->resolveConfig('default', []);

        self::assertSame('custom.example.test', (string)($config['host'] ?? ''));
    }

    public function testManagedLocalHostListenAddressUsesLoopback(): void
    {
        $start = $this->createProbe();

        self::assertSame('127.0.0.1', $start->resolveListenHost('p11005ce4.weline.test'));
        self::assertSame('127.0.0.1', $start->resolveListenHost('demo.weline.localhost'));
        self::assertSame('0.0.0.0', $start->resolveListenHost('0.0.0.0'));
        self::assertSame('0.0.0.0', $start->resolveListenHost('www.example.com'));
        self::assertSame('192.168.1.10', $start->resolveListenHost('192.168.1.10'));
    }

    public function testEnvCustomHostOverridesSavedLegacyHost(): void
    {
        $start = $this->createProbe(
            ['host' => 'p11005ce4.weline.local'],
            ['wls' => ['host' => 'demo.internal.example']]
        );
        $config = $start->resolveConfig('default', []);

        self::assertSame('demo.internal.example', (string)($config['host'] ?? ''));
    }

    public function testWildcardListenHostUsesServerHostAsPublicHostWithoutWarning(): void
    {
        $start = $this->createProbe(
            null,
            [
                'wls' => ['host' => '0.0.0.0'],
                'server' => ['host' => 'p11005ce4.weline.test'],
            ]
        );

        $result = $start->validateExternalAllowlist('default', ['host' => '0.0.0.0']);

        self::assertTrue($result['valid']);
        self::assertSame('p11005ce4.weline.test', $result['config']['public_host'] ?? null);
        self::assertNull($result['warning']);
    }

    public function testWildcardListenHostFallsBackToGeneratedProjectHostWithoutWarning(): void
    {
        $start = $this->createProbe(
            null,
            ['wls' => ['host' => '0.0.0.0']]
        );

        $result = $start->validateExternalAllowlist('default', ['host' => '0.0.0.0']);

        self::assertTrue($result['valid']);
        self::assertSame('unit-test.weline.test', $result['config']['public_host'] ?? null);
        self::assertNull($result['warning']);
    }

    public function testBaseGetEnvConfigReturnsArray(): void
    {
        $start = new StartBaseEnvConfigProbe();

        self::assertIsArray($start->readEnvConfig());
    }

    public function testSaveInstanceInfoUsesManagerEndpointSemantics(): void
    {
        $manager = new StartInstanceManagerProbe();
        $start = new StartInstanceInfoProbe($manager);

        $start->persistInstanceInfo('unit-solidify');

        self::assertCount(1, $manager->savedInstances);
        self::assertSame('unit-solidify', $manager->savedInstances[0]['name']);

        $info = $manager->savedInstances[0]['info'];
        self::assertSame('unit-solidify', $info['name'] ?? null);
        self::assertSame('127.0.0.1', $info['host'] ?? null);
        self::assertSame('127.0.0.1', $info['public_host'] ?? null);
        self::assertSame(9443, $info['port'] ?? null);
        self::assertSame(2, $info['count'] ?? null);
        self::assertSame(19443, $info['worker_port'] ?? null);
        self::assertSame(80, $info['http_redirect_port'] ?? null);
    }

    public function testSaveInstanceInfoKeepsPublicHostSeparateFromListenHost(): void
    {
        $manager = new StartInstanceManagerProbe();
        $start = new StartInstanceInfoProbe($manager);

        $start->persistInstanceInfoWithPublicHost('unit-public-host');

        $info = $manager->savedInstances[0]['info'];
        self::assertSame('127.0.0.1', $info['host'] ?? null);
        self::assertSame('p11005ce4.weline.test', $info['public_host'] ?? null);
    }

    public function testBaseStartExposesInstanceManagerForRuntimePersistence(): void
    {
        $start = new StartInstanceManagerAccessorProbe();

        self::assertInstanceOf(ServerInstanceManager::class, $start->readInstanceManager());
    }

    private function createProbe(
        ?array $savedConfig = null,
        array $envConfig = [],
        array $publicHostIps = [],
        array $currentServerIps = []
    ): StartConfigProbe
    {
        $sslServiceMock = $this->createMock(SslCertificateService::class);
        ObjectManager::setInstance(SslCertificateService::class, $sslServiceMock);

        return new StartConfigProbe($savedConfig, $envConfig, $publicHostIps, $currentServerIps);
    }

    private function restoreEnvValue(string $name, string|false $value): void
    {
        if ($value === false) {
            \putenv($name);
            return;
        }

        \putenv($name . '=' . $value);
    }
}

final class StartConfigProbe extends Start
{
    public function __construct(
        private readonly ?array $savedConfig = null,
        private readonly array $envConfig = [],
        private readonly array $publicHostIps = [],
        private readonly array $currentServerIps = []
    ) {
    }

    public function resolveConfig(string $instanceName, array $args): array
    {
        return $this->getServerConfig($instanceName, $args);
    }

    public function resolveListenHost(string $host): string
    {
        return $this->resolveServerListenHost($host);
    }

    public function normalizePortForSslState(int $port, bool $sslEnabled, bool $portExplicit = false): int
    {
        return $this->normalizeDefaultPortForSslState($port, $sslEnabled, $portExplicit);
    }

    public function useStartupCertificateFiles(
        SslCertificateService $sslService,
        string $domain,
        string $syncDomain
    ): ?array {
        return $this->tryUseStartupCertificateFiles($sslService, $domain, $syncDomain);
    }

    public function validatePublicHost(SslCertificateService $sslService, string $host): array
    {
        return $this->validatePublicHostResolvesToCurrentServer($host, $sslService);
    }

    /**
     * @return array{valid: bool, config: array<string, mixed>, warning: string|null}
     */
    public function validateExternalAllowlist(string $instanceName, array $config): array
    {
        $host = (string)($config['host'] ?? '');
        $valid = $this->validateExternalHostAllowlist($instanceName, $host, $config);

        $warning = new \ReflectionProperty(Start::class, 'deferredStartupWarning');
        $warning->setAccessible(true);

        return [
            'valid' => $valid,
            'config' => $config,
            'warning' => $warning->getValue($this),
        ];
    }

    protected function resolvePublicHostIps(string $host): array
    {
        $host = \strtolower(\trim($host));

        return $this->publicHostIps[$host] ?? [];
    }

    protected function detectCurrentServerIps(): array
    {
        return $this->currentServerIps;
    }

    protected function getDefaultHost(): string
    {
        return 'unit-test.weline.test';
    }

    protected function loadSavedInstanceConfig(string $instanceName): ?array
    {
        unset($instanceName);

        return $this->savedConfig;
    }

    protected function getEnvConfig(): array
    {
        return $this->envConfig;
    }

    protected function restoreManagedCertificateForConfig(array &$config, SslCertificateService $sslService, string $host): bool
    {
        unset($config, $sslService, $host);

        return false;
    }

    protected function autoDetectSslCertificates(): ?array
    {
        return null;
    }

    protected function ensureHostsFileConfigured(string $host): void
    {
        unset($host);
    }

    protected function ensureLocalSelfSignedCertificates(): void
    {
    }

    protected function generateCertificateMap(): void
    {
    }

    protected function calculateWorkerCount($workerCount, string $mode): int
    {
        unset($mode);

        if ($workerCount === 'auto' || $workerCount === null || $workerCount === '') {
            return 4;
        }

        return (int)$workerCount;
    }
}

final class StartBaseEnvConfigProbe extends Start
{
    public function readEnvConfig(): array
    {
        return $this->getEnvConfig();
    }

    protected function restoreManagedCertificateForConfig(array &$config, SslCertificateService $sslService, string $host): bool
    {
        unset($config, $sslService, $host);

        return false;
    }

    protected function autoDetectSslCertificates(): ?array
    {
        return null;
    }

    protected function ensureHostsFileConfigured(string $host): void
    {
        unset($host);
    }

    protected function ensureLocalSelfSignedCertificates(): void
    {
    }

    protected function generateCertificateMap(): void
    {
    }

    protected function calculateWorkerCount($workerCount, string $mode): int
    {
        unset($mode);

        if ($workerCount === 'auto' || $workerCount === null || $workerCount === '') {
            return 4;
        }

        return (int)$workerCount;
    }
}

final class StartInstanceInfoProbe extends Start
{
    public function __construct(private readonly ServerInstanceManager $manager)
    {
    }

    public function persistInstanceInfo(string $instanceName): void
    {
        $this->saveInstanceInfo(
            $instanceName,
            '127.0.0.1',
            9443,
            2,
            true,
            true,
            '/tmp/cert.pem',
            '/tmp/key.pem',
            true,
            19443,
            80,
            true,
            true,
            false,
            19443,
            [],
            ['frontend_process_mode' => true],
            '512M'
        );
    }

    public function persistInstanceInfoWithPublicHost(string $instanceName): void
    {
        $this->saveInstanceInfo(
            $instanceName,
            '127.0.0.1',
            9443,
            2,
            true,
            true,
            '/tmp/cert.pem',
            '/tmp/key.pem',
            true,
            19443,
            80,
            false,
            false,
            false,
            19443,
            [],
            [],
            '512M',
            '',
            'p11005ce4.weline.test'
        );
    }

    protected function getInstanceManager(): ServerInstanceManager
    {
        return $this->manager;
    }
}

final class StartInstanceManagerProbe extends ServerInstanceManager
{
    /**
     * @var list<array{name: string, info: array<string, mixed>}>
     */
    public array $savedInstances = [];

    public function saveInstance(string $name, array $info): void
    {
        $this->savedInstances[] = [
            'name' => $name,
            'info' => $info,
        ];
    }
}

final class StartInstanceManagerAccessorProbe extends Start
{
    public function readInstanceManager(): ServerInstanceManager
    {
        return $this->getInstanceManager();
    }
}
