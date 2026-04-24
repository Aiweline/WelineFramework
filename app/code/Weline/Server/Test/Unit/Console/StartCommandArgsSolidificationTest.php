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

    public function testEnvCustomHostOverridesSavedLegacyHost(): void
    {
        $start = $this->createProbe(
            ['host' => 'p11005ce4.weline.local'],
            ['wls' => ['host' => 'demo.internal.example']]
        );
        $config = $start->resolveConfig('default', []);

        self::assertSame('demo.internal.example', (string)($config['host'] ?? ''));
    }

    public function testBaseGetEnvConfigReturnsArray(): void
    {
        $start = new StartBaseEnvConfigProbe();

        self::assertIsArray($start->readEnvConfig());
    }

    public function testSaveInstanceInfoUsesManagerAppendOnlySemantics(): void
    {
        $manager = new StartInstanceManagerProbe();
        $start = new StartInstanceInfoProbe($manager);

        $start->persistInstanceInfo('unit-solidify');

        self::assertCount(1, $manager->savedInstances);
        self::assertSame('unit-solidify', $manager->savedInstances[0]['name']);

        $info = $manager->savedInstances[0]['info'];
        self::assertSame('unit-solidify', $info['name'] ?? null);
        self::assertSame('127.0.0.1', $info['host'] ?? null);
        self::assertSame(9443, $info['port'] ?? null);
        self::assertSame(2, $info['count'] ?? null);
        self::assertSame(19443, $info['worker_port'] ?? null);
        self::assertSame(80, $info['http_redirect_port'] ?? null);
    }

    public function testBaseStartExposesInstanceManagerForRuntimePersistence(): void
    {
        $start = new StartInstanceManagerAccessorProbe();

        self::assertInstanceOf(ServerInstanceManager::class, $start->readInstanceManager());
    }

    private function createProbe(?array $savedConfig = null, array $envConfig = []): StartConfigProbe
    {
        $sslServiceMock = $this->createMock(SslCertificateService::class);
        ObjectManager::setInstance(SslCertificateService::class, $sslServiceMock);

        return new StartConfigProbe($savedConfig, $envConfig);
    }
}

final class StartConfigProbe extends Start
{
    public function __construct(
        private readonly ?array $savedConfig = null,
        private readonly array $envConfig = []
    ) {
    }

    public function resolveConfig(string $instanceName, array $args): array
    {
        return $this->getServerConfig($instanceName, $args);
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
            [101, 102],
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
