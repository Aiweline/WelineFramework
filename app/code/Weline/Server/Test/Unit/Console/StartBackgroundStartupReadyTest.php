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
        $start = new class extends Start {
            protected function emitBackgroundStartupProgress(string $progress, string $lastProgress): void
            {
                unset($progress, $lastProgress);
            }

            protected function finishBackgroundStartupProgress(string $lastProgress): void
            {
                unset($lastProgress);
            }
        };

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

    public function testResolveBackgroundStartupReadyWaitMsScalesWithStartupShape(): void
    {
        $start = new class extends Start {
            protected function getEnvironmentValue(string $path, mixed $default = null): mixed
            {
                if ($path === 'wls.orchestrator.startup_timeout_sec') {
                    return 5;
                }
                return $default;
            }
        };

        $singleWorkerWait = $this->invokeProtected($start, 'resolveBackgroundStartupReadyWaitMs', [
            'count' => 1,
            'dispatcher_enabled' => false,
            'ssl_enabled' => false,
            'shared_state' => [],
        ]);
        $multiServiceWait = $this->invokeProtected($start, 'resolveBackgroundStartupReadyWaitMs', [
            'count' => 4,
            'dispatcher_enabled' => true,
            'ssl_enabled' => true,
            'shared_state' => [
                'session' => ['port' => 19970],
                'memory' => ['port' => 19971],
            ],
        ]);

        self::assertGreaterThan($singleWorkerWait, $multiServiceWait);
    }

    public function testResolveBackgroundMasterConfirmWaitAllowsControlPlaneMetadataWhenSpawnPidKnown(): void
    {
        $start = new class extends Start {
            protected function getEnvironmentValue(string $path, mixed $default = null): mixed
            {
                unset($path);
                return $default;
            }
        };

        self::assertSame(5000, $this->invokeProtected($start, 'resolveBackgroundMasterConfirmWaitMs', 0));
        self::assertSame(8000, $this->invokeProtected($start, 'resolveBackgroundMasterConfirmWaitMs', 12345));
    }

    public function testResolveBackgroundMasterConfirmWaitHonorsEnvironmentOverride(): void
    {
        $start = new class extends Start {
            protected function getEnvironmentValue(string $path, mixed $default = null): mixed
            {
                unset($default);
                return $path === 'wls.orchestrator.background_master_confirm_wait_sec' ? 2.5 : null;
            }
        };

        self::assertSame(2500, $this->invokeProtected($start, 'resolveBackgroundMasterConfirmWaitMs', 12345));
    }

    public function testStartupProgressSummaryIgnoresStoppedHistoricalServices(): void
    {
        $start = new Start();

        $summary = $this->invokeProtected($start, 'summarizeBackgroundStartupServices', [
            'services' => [
                'worker' => [
                    'display_name' => 'HTTP Worker',
                    'instances' => [
                        ['state' => 'stopped'],
                        ['state' => 'ready'],
                    ],
                ],
                'memory_server' => [
                    'display_name' => 'Memory Service',
                    'instances' => [
                        ['state' => 'exited'],
                    ],
                ],
            ],
        ]);

        self::assertSame(['ready' => 1, 'total' => 1, 'pending_detail' => ''], $summary);
    }

    public function testWaitForBackgroundStartupReadyExtendsIdleDeadlineWhenProgressAdvances(): void
    {
        $start = new class extends Start {
            public array $frames = [
                ['startup_phase' => 'bootstrapping'],
                [
                    'startup_phase' => 'bootstrapping',
                    'services' => [
                        'worker' => [
                            'display_name' => 'HTTP Worker',
                            'instances' => [
                                ['state' => 'starting'],
                            ],
                        ],
                    ],
                ],
                [
                    'startup_phase' => 'bootstrapping',
                    'services' => [
                        'worker' => [
                            'display_name' => 'HTTP Worker',
                            'instances' => [
                                ['state' => 'ready'],
                            ],
                        ],
                    ],
                ],
                [
                    'startup_phase' => 'running',
                    'services' => [
                        'worker' => [
                            'display_name' => 'HTTP Worker',
                            'instances' => [
                                ['state' => 'ready'],
                            ],
                        ],
                    ],
                ],
            ];
            public array $progressMessages = [];

            protected function readBackgroundStartupData(string $instanceFile): array
            {
                unset($instanceFile);
                return \array_shift($this->frames) ?? ['startup_phase' => 'bootstrapping'];
            }

            protected function emitBackgroundStartupProgress(string $progress, string $lastProgress): void
            {
                unset($lastProgress);
                $this->progressMessages[] = $progress;
            }

            protected function finishBackgroundStartupProgress(string $lastProgress): void
            {
                unset($lastProgress);
            }
        };

        $result = $this->invokeProtected($start, 'waitForBackgroundStartupReady', 'ignored', 50, 10, 200);

        self::assertTrue($result['ready']);
        self::assertSame('running', $result['data']['startup_phase']);
        self::assertGreaterThan(50, $result['waited_ms']);
        self::assertNotEmpty($start->progressMessages);
        self::assertStringContainsString('阶段：启动准备', $start->progressMessages[0]);
        self::assertStringContainsString('服务就绪：1/1', $start->progressMessages[\count($start->progressMessages) - 1]);
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
