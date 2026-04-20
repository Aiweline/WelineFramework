<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\ServerInstanceManager;

final class StopCommandFastLocalCleanupTest extends TestCase
{
    public function testFastLocalCleanupSkipsIpcAndRunsLocalCleanupFlow(): void
    {
        $manager = new class extends ServerInstanceManager {
            public ?ServerInstanceInfo $info = null;
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
                unset($instanceName);

                return null;
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
            19982,
            '127.0.0.1',
            9982,
            false,
            false,
            1,
            10000,
            0,
            '2026-04-06 12:00:00',
            1775448000,
            []
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

            protected function runResidualCleanupPairWithRetry(string $name, ServerInstanceInfo $info): void
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

            protected function cleanupAllWlsProcesses(string $instanceName): void
            {
                $this->calls[] = 'cleanup:' . $instanceName;
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $stop->execute([
                0 => 'server:stop',
                1 => 'default',
                'force' => true,
                'f' => true,
                'fast-local' => true,
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
