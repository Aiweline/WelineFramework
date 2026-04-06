<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Start;

final class StartBackgroundStartupReadyTest extends TestCase
{
    public function testWaitForBackgroundStartupReadyRequiresRunningPhase(): void
    {
        $file = $this->createTempInstanceFile(['startup_phase' => 'bootstrapping']);
        $start = new Start();

        $result = $this->invokeProtected($start, 'waitForBackgroundStartupReady', $file, 20, 10);

        self::assertFalse($result['ready']);
        self::assertSame('bootstrapping', $result['data']['startup_phase']);
    }

    public function testWaitForBackgroundStartupReadyTreatsRunningPhaseAsComplete(): void
    {
        $file = $this->createTempInstanceFile(['startup_phase' => 'running']);
        $start = new Start();

        $result = $this->invokeProtected($start, 'waitForBackgroundStartupReady', $file, 20, 10);

        self::assertTrue($result['ready']);
        self::assertSame('running', $result['data']['startup_phase']);
    }

    public function testFinalizeBackgroundStartupOutputDefersServerInfoUntilStartupCompleted(): void
    {
        $start = new class extends Start {
            public int $startupInfoCalls = 0;
            public int $usageInfoCalls = 0;

            protected function showStartupInfo(
                string $instanceName,
                string $host,
                int $port,
                int $count,
                bool $daemon,
                string $source = '',
                bool $sslEnabled = false,
                bool $dispatcherEnabled = false,
                int $workerPort = 0,
                int $httpRedirectPort = 0,
                bool $directReusePortEnabled = false
            ): void {
                unset($instanceName, $host, $port, $count, $daemon, $source, $sslEnabled, $dispatcherEnabled, $workerPort, $httpRedirectPort, $directReusePortEnabled);
                $this->startupInfoCalls++;
            }

            protected function showUsageInfo(string $host, int $port, string $instanceName, bool $sslEnabled = false): void
            {
                unset($host, $port, $instanceName, $sslEnabled);
                $this->usageInfoCalls++;
            }
        };

        $this->invokeProtected(
            $start,
            'finalizeBackgroundStartupOutput',
            false,
            'default',
            '127.0.0.1',
            8080,
            2,
            'cli',
            false,
            true,
            18080,
            0,
            false
        );

        self::assertSame(0, $start->startupInfoCalls);
        self::assertSame(0, $start->usageInfoCalls);

        $this->invokeProtected(
            $start,
            'finalizeBackgroundStartupOutput',
            true,
            'default',
            '127.0.0.1',
            8080,
            2,
            'cli',
            false,
            true,
            18080,
            0,
            false
        );

        self::assertSame(1, $start->startupInfoCalls);
        self::assertSame(1, $start->usageInfoCalls);
    }

    private function createTempInstanceFile(array $data): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'wls-start-');
        self::assertNotFalse($file);
        \file_put_contents($file, \json_encode($data, JSON_THROW_ON_ERROR));
        $this->addToAssertionCount(1);

        $this->registerFileCleanup($file);

        return $file;
    }

    private function registerFileCleanup(string $file): void
    {
        \register_shutdown_function(static function () use ($file): void {
            if (\is_file($file)) {
                @\unlink($file);
            }
        });
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
