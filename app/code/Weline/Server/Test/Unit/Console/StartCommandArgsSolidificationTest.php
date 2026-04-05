<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Console\Server\Start;
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

    private function createProbe(): StartConfigProbe
    {
        $sslServiceMock = $this->createMock(SslCertificateService::class);
        ObjectManager::setInstance(SslCertificateService::class, $sslServiceMock);

        return new StartConfigProbe();
    }
}

final class StartConfigProbe extends Start
{
    public function resolveConfig(string $instanceName, array $args): array
    {
        return $this->getServerConfig($instanceName, $args);
    }

    protected function getDefaultHost(): string
    {
        return 'unit-test.local';
    }

    protected function loadSavedInstanceConfig(string $instanceName): ?array
    {
        unset($instanceName);

        return null;
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
