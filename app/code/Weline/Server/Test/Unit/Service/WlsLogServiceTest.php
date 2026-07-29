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

    public function testProcessLaunchLogConfigIsCrossPlatformAndInstanceScoped(): void
    {
        $base = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR
            . 'weline-wls-launch-logs-' . \bin2hex(\random_bytes(6));
        $createdFiles = [];

        try {
            self::assertSame([], WlsLogService::getProcessLaunchLogConfig(
                'disabled-process',
                'disabled',
                false,
                true,
                null,
                $base
            ));
            self::assertDirectoryDoesNotExist($base . \DIRECTORY_SEPARATOR . 'disabled');

            $posix = WlsLogService::getProcessLaunchLogConfig(
                'worker-one',
                'alpha',
                true,
                false,
                null,
                $base
            );
            self::assertSame(['outputLogFile'], \array_keys($posix));
            self::assertFileExists($posix['outputLogFile']);
            $createdFiles[] = $posix['outputLogFile'];
            self::assertFileDoesNotExist(WlsLogService::getProcessStderrLogFile(
                'worker-one',
                'alpha',
                null,
                $base
            ));

            $windows = WlsLogService::getProcessLaunchLogConfig(
                'worker-two',
                'beta',
                true,
                true,
                null,
                $base
            );
            self::assertSame(['stdoutLogFile', 'stderrLogFile'], \array_keys($windows));
            self::assertNotSame($windows['stdoutLogFile'], $windows['stderrLogFile']);
            self::assertFileExists($windows['stdoutLogFile']);
            self::assertFileExists($windows['stderrLogFile']);
            self::assertIsWritable($windows['stdoutLogFile']);
            self::assertIsWritable($windows['stderrLogFile']);
            $createdFiles[] = $windows['stdoutLogFile'];
            $createdFiles[] = $windows['stderrLogFile'];

            self::assertSame(
                [$windows['stdoutLogFile']],
                WlsLogService::getVisibleProcessLogFiles('worker-two', 'beta', null, $base)
            );
            \file_put_contents($windows['stderrLogFile'], 'early bootstrap failure');
            self::assertSame(
                [$windows['stdoutLogFile'], $windows['stderrLogFile']],
                WlsLogService::getVisibleProcessLogFiles('worker-two', 'beta', null, $base)
            );

            $other = WlsLogService::getProcessLaunchLogConfig(
                'worker-two',
                'gamma',
                true,
                true,
                null,
                $base
            );
            self::assertNotSame($windows['stdoutLogFile'], $other['stdoutLogFile']);
            self::assertNotSame($windows['stderrLogFile'], $other['stderrLogFile']);
            $createdFiles[] = $other['stdoutLogFile'];
            $createdFiles[] = $other['stderrLogFile'];
        } finally {
            foreach (\array_unique($createdFiles) as $file) {
                @\unlink($file);
            }
            foreach (['alpha', 'beta', 'gamma', 'disabled'] as $instance) {
                @\rmdir($base . \DIRECTORY_SEPARATOR . $instance);
            }
            @\rmdir($base);
        }
    }

    private function normalizePath(string $path): string
    {
        $normalized = \str_replace('\\', '/', $path);
        return \rtrim($normalized, '/') . '/';
    }
}
