<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;
use Weline\Server\Service\ServerInstanceManager;

final class StopCommandBootstrapCleanupResidualTest extends TestCase
{
    public function testBootstrapCleanupAlsoRunsResidualCleanupPair(): void
    {
        $manager = new class extends ServerInstanceManager {
            public ?ServerInstanceInfo $info = null;
            public array $rawData = ['startup_phase' => 'bootstrapping'];
            public array $deleted = [];

            public function __construct()
            {
            }

            public function getInstanceInfo(string $instanceName, bool $strict = false): ?ServerInstanceInfo
            {
                unset($strict);

                return $instanceName === 'default' ? $this->info : null;
            }

            public function getRawInstanceData(string $instanceName): ?array
            {
                return $instanceName === 'default' ? $this->rawData : null;
            }

            public function deleteInstance(string $instanceName): bool
            {
                $this->deleted[] = $instanceName;

                return true;
            }
        };
        $manager->info = new ServerInstanceInfo(
            'default',
            0,
            26900,
            '127.0.0.1',
            443,
            true,
            false,
            2,
            16899,
            0,
            '2026-04-20 08:28:04',
            1776673684,
            [
                new ServiceInfo(
                    'dispatcher',
                    'Dispatcher',
                    1,
                    0,
                    443,
                    ServiceInstance::STATE_FAILED,
                    30,
                    1,
                    'dispatcher-1',
                    0.0,
                    null,
                    ['process_name' => 'weline-wls-dispatcher-default-p11005ce4']
                ),
                new ServiceInfo(
                    'worker',
                    'HTTP Worker',
                    1,
                    51048,
                    16899,
                    ServiceInstance::STATE_READY,
                    20,
                    1,
                    'worker-1',
                    0.0,
                    101,
                    ['process_name' => 'weline-wls-worker-default-p11005ce4-1']
                ),
            ]
        );

        $stop = new class($manager) extends Stop {
            public array $calls = [];

            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            protected function printWelcome(): void
            {
            }

            protected function printGoodbye(bool $success = true, string $message = ''): void
            {
                unset($success, $message);
            }

            protected function acquireStopLock(string $instanceName, int $timeout = 5): bool
            {
                unset($instanceName, $timeout);

                return true;
            }

            protected function releaseStopLock(): void
            {
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function isMasterProcessAvailableForStop(ServerInstanceInfo $info): bool
            {
                unset($info);

                return true;
            }

            protected function showInstanceInfo(ServerInstanceInfo $info): void
            {
                unset($info);
                $this->calls[] = 'show';
            }

            protected function sendStopViaIpcAndWait(string $instanceName, int $controlPort, int $masterPid, bool $force): bool
            {
                unset($instanceName, $controlPort, $masterPid, $force);
                $this->calls[] = 'ipc';

                return true;
            }

            protected function runResidualCleanupPairWithRetry(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): void
            {
                unset($info);
                $this->calls[] = 'residual:' . $name;
            }

            protected function releaseSharedStateConsumersForInstance(string $instanceName): void
            {
                $this->calls[] = 'release:' . $instanceName;
            }

            protected function cleanupPidFiles(string $name, ServerInstanceInfo $info): void
            {
                unset($info);
                $this->calls[] = 'pid:' . $name;
            }

            protected function releaseStartLock(string $instanceName): void
            {
                $this->calls[] = 'unlock:' . $instanceName;
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $stop->execute([
                0 => 'server:stop',
                1 => 'default',
            ], []);
        } finally {
            \ob_end_clean();
        }

        self::assertSame(['default'], $manager->deleted);
        self::assertSame(
            [
                'show',
                'residual:default',
                'release:default',
                'pid:default',
                'unlock:default',
            ],
            $stop->calls
        );
    }
}
