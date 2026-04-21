<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;

final class StopCommandResidualPidIndexTest extends TestCase
{
    public function testCollectIndexedResidualPidsFromPidIndexFallsBackWithoutJsonPathWhenProcessStillMatchesInstance(): void
    {
        $stop = new class extends Stop {
            protected function isResidualIndexedPidStillRunning(int $pid, string $pname, string $taskName): bool
            {
                return true;
            }
        };

        $pids = $this->invokeProtected(
            $stop,
            'collectIndexedResidualPidsFromPidIndex',
            [
                101 => ['pname' => '--name=weline-wls-master-default', 'jsonPath' => __FILE__],
                202 => ['pname' => '"php.exe" "worker_ssl.php" --name="weline-wls-worker-default-1"', 'jsonPath' => __FILE__],
                303 => ['pname' => '--name=weline-wls-worker-other-1', 'jsonPath' => __FILE__],
                404 => ['pname' => '--name=weline-wls-session-default', 'jsonPath' => __FILE__ . '.missing'],
                505 => ['pname' => '--name=weline-master-default-worker-3', 'jsonPath' => __FILE__],
            ],
            'default',
            202
        );

        self::assertSame([101, 404, 505], $pids);
    }

    public function testCollectResidualCleanupCandidatePidsIncludesRecoverableManagedPids(): void
    {
        $stop = new class extends Stop {
            protected function collectBaseResidualPids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [101, 202];
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [202, 303, 404];
            }

            protected function queryWindowsCmdWindowRowsForStop(): array
            {
                return [];
            }
        };

        $info = new ServerInstanceInfo(
            'default',
            0,
            0,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16899,
            0,
            '2026-04-20 10:19:17',
            1776651557,
            []
        );

        $pids = $this->invokeProtected($stop, 'collectResidualCleanupCandidatePids', 'default', $info);

        self::assertSame([101, 202, 303, 404], $pids);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
