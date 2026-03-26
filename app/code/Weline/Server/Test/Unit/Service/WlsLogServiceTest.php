<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WlsLogService;

final class WlsLogServiceTest extends TestCase
{
    public function testResolveInstanceNameFromExplicitValue(): void
    {
        self::assertSame('shop_1', WlsLogService::resolveInstanceName('shop 1'));
    }

    public function testResolveInstanceNameFromProcessTag(): void
    {
        self::assertSame('demo', WlsLogService::resolveInstanceName(null, 'Worker#1:9981@demo'));
    }

    public function testGetLogDirAppendsInstanceWhenNoPlaceholder(): void
    {
        $dir = WlsLogService::getLogDir('alpha', null, 'var/log/wls');
        $normalized = $this->normalizePath($dir);

        self::assertStringEndsWith('/var/log/wls/alpha/', $normalized);
    }

    public function testGetLogDirSupportsInstancePlaceholder(): void
    {
        $dir = WlsLogService::getLogDir('beta', null, 'var/log/wls/{instance}/');
        $normalized = $this->normalizePath($dir);

        self::assertStringEndsWith('/var/log/wls/beta/', $normalized);
    }

    public function testWorkerAndProcessFilesAreInstanceScoped(): void
    {
        $workerLog = $this->normalizePath(WlsLogService::getWorkerLogFile(9981, 'store-a'));
        $processLog = $this->normalizePath(WlsLogService::getProcessLogFile('weline-wls-worker-store-a-1', 'store-a'));

        self::assertStringContainsString('/var/log/wls/store-a/worker-9981.log/', $workerLog);
        self::assertStringContainsString('/var/log/wls/store-a/weline-wls-worker-store-a-1.log/', $processLog);
    }

    private function normalizePath(string $path): string
    {
        $normalized = \str_replace('\\', '/', $path);
        return \rtrim($normalized, '/') . '/';
    }
}
