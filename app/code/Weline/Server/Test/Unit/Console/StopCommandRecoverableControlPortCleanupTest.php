<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;

final class StopCommandRecoverableControlPortCleanupTest extends TestCase
{
    public function testCollectRemainingRecoverablePortsIncludesControlAndServicePortsFromInstance(): void
    {
        $info = new ServerInstanceInfo(
            'default',
            200,
            26895,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16895,
            80,
            '2026-04-20 10:19:17',
            1776651557,
            [
                new ServiceInfo(
                    'session_server',
                    'Session Server',
                    1,
                    422,
                    26422,
                    ServiceInstance::STATE_READY
                ),
                new ServiceInfo(
                    'worker',
                    'HTTP Worker',
                    1,
                    0,
                    16895,
                    ServiceInstance::STATE_READY
                ),
            ]
        );

        $stop = new class extends Stop {
            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [443];
            }

            protected function inspectRecoverablePortOccupant(int $port): array
            {
                return match ($port) {
                    443 => [
                        'in_use' => true,
                        'pid' => 4430,
                        'pid_running' => true,
                        'is_weline' => true,
                        'state' => 'weline',
                    ],
                    26895 => [
                        'in_use' => true,
                        'pid' => 300,
                        'pid_running' => true,
                        'is_weline' => false,
                        'state' => 'foreign',
                    ],
                    26422 => [
                        'in_use' => true,
                        'pid' => 422,
                        'pid_running' => true,
                        'is_weline' => true,
                        'state' => 'weline',
                    ],
                    default => [
                        'in_use' => false,
                        'pid' => 0,
                        'pid_running' => false,
                        'is_weline' => false,
                        'state' => 'free',
                    ],
                };
            }

            protected function isRecoverableWlsPortResponder(int $port): bool
            {
                unset($port);

                return false;
            }

            protected function queryStopPidRunning(int $pid): bool
            {
                return \in_array($pid, [300, 422, 4430], true);
            }

            protected function queryStopWelineServerProcess(int $pid): bool
            {
                unset($pid);

                return false;
            }

            protected function queryStopProcessManagerCreated(int $pid): bool
            {
                return $pid === 300;
            }
        };

        $ports = $this->invokeProtected($stop, 'collectRemainingRecoverableWlsPorts', 'default', $info);

        self::assertSame([443, 26422, 26895], $ports);
    }

    public function testKillWlsProcessOnPortAcceptsIndexedWlsOwner(): void
    {
        $stop = new class extends Stop {
            public array $killed = [];

            protected function getPortProcessId(int $port): int
            {
                unset($port);

                return 300;
            }

            protected function queryStopPidRunning(int $pid): bool
            {
                return $pid === 300;
            }

            protected function getProcessPnameByPid(int $pid): string
            {
                unset($pid);

                return 'weline-wls-wrapper-default';
            }

            protected function queryStopProcessCommandLine(int $pid): string
            {
                unset($pid);

                return 'cmd.exe /d /c temp.cmd';
            }

            protected function queryStopWelineServerProcess(int $pid): bool
            {
                unset($pid);

                return false;
            }

            protected function queryStopProcessManagerCreated(int $pid): bool
            {
                return $pid === 300;
            }

            protected function queryStopParentPid(int $pid): int
            {
                unset($pid);

                return 0;
            }

            protected function queryKillManagedProcessTreeForStop(int $pid): bool
            {
                $this->killed[] = $pid;

                return true;
            }

            protected function logWlsPortTermination(int $port, int $pid, int $killPid): void
            {
                unset($port, $pid, $killPid);
            }
        };

        self::assertTrue($stop->killWlsProcessOnPort(26895));
        self::assertSame([300], $stop->killed);
    }

    public function testCleanupRecoverableConfiguredPortsTerminatesAllCandidatesInOneBatch(): void
    {
        $stop = new class extends Stop {
            /** @var list<list<int>> */
            public array $terminatedBatches = [];

            public function cleanupPorts(array $ports): int
            {
                return $this->cleanupRecoverableConfiguredPorts($ports);
            }

            protected function inspectRecoverablePortOccupant(int $port): array
            {
                return match ($port) {
                    80 => [
                        'in_use' => true,
                        'pid' => 101,
                        'pid_running' => true,
                        'is_weline' => true,
                        'state' => 'weline',
                    ],
                    443 => [
                        'in_use' => true,
                        'pid' => 102,
                        'pid_running' => true,
                        'is_weline' => true,
                        'state' => 'weline',
                    ],
                    16895 => [
                        'in_use' => true,
                        'pid' => 103,
                        'pid_running' => true,
                        'is_weline' => true,
                        'state' => 'weline',
                    ],
                    default => [
                        'in_use' => false,
                        'pid' => 0,
                        'pid_running' => false,
                        'is_weline' => false,
                        'state' => 'free',
                    ],
                };
            }

            protected function isRecoverableWlsPortResponder(int $port): bool
            {
                unset($port);

                return false;
            }

            protected function queryStopPidRunning(int $pid): bool
            {
                return \in_array($pid, [101, 102, 103, 202], true);
            }

            protected function queryStopWelineServerProcess(int $pid): bool
            {
                return \in_array($pid, [101, 102, 103], true);
            }

            protected function queryStopProcessManagerCreated(int $pid): bool
            {
                unset($pid);

                return false;
            }

            protected function getProcessPnameByPid(int $pid): string
            {
                return 'weline-wls-test-' . $pid;
            }

            protected function queryStopProcessCommandLine(int $pid): string
            {
                return 'php worker.php --name=weline-wls-test-' . $pid;
            }

            protected function resolveManagedStopRootPid(int $pid): int
            {
                return $pid === 102 ? 202 : $pid;
            }

            protected function terminateRecoverableProcessIds(array $pids): int
            {
                $this->terminatedBatches[] = \array_values($pids);

                return \count($pids);
            }
        };

        $stop->__init();
        \ob_start();
        try {
            $processed = $stop->cleanupPorts([80, 443, 16895]);
            \ob_end_clean();
        } catch (\Throwable $exception) {
            \ob_end_clean();
            throw $exception;
        }

        self::assertSame(3, $processed);
        self::assertSame([[101, 102, 103]], $stop->terminatedBatches);
    }

    public function testCollectRecoverablePortsFromInstanceUsesWorkerBasePort(): void
    {
        $info = new ServerInstanceInfo(
            'default',
            200,
            26895,
            '127.0.0.1',
            443,
            true,
            false,
            2,
            16895,
            80,
            '2026-04-20 10:19:17',
            1776651557,
            []
        );

        $stop = new class extends Stop {
            public function collectPorts(ServerInstanceInfo $info): array
            {
                return $this->collectRecoverablePortsFromInstance($info);
            }
        };

        self::assertSame([443, 80, 26895, 16895], $stop->collectPorts($info));
    }

    public function testDirectForceStopCandidatesSkipRootPromotionOnFirstPass(): void
    {
        $info = new ServerInstanceInfo(
            'default',
            33780,
            26895,
            '127.0.0.1',
            443,
            true,
            false,
            2,
            16895,
            80,
            '2026-04-20 10:19:17',
            1776651557,
            []
        );

        $stop = new class extends Stop {
            public function collectCandidates(ServerInstanceInfo $info): array
            {
                return $this->collectDirectForceStopCandidatePids($info);
            }

            protected function collectBaseResidualPids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [33780, 2604, 7704];
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [7704, 16048, 0];
            }

            protected function queryWindowsCmdWindowRowsForStop(): array
            {
                return [];
            }

            protected function collectManagedStopPids(array $pids): array
            {
                $this->fail('first force-stop pass should not promote candidate pids to root wrappers');
            }
        };

        self::assertSame([33780, 2604, 7704, 16048], $stop->collectCandidates($info));
    }

    public function testForceStopCandidatesIncludePrefixPidsOnFirstPass(): void
    {
        if (!\defined('DS')) {
            \define('DS', DIRECTORY_SEPARATOR);
        }

        $info = new ServerInstanceInfo(
            'unit-prefix-candidates-4f7b',
            101,
            0,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16895,
            0,
            '2026-04-21 02:36:00',
            1776710160,
            []
        );

        $stop = new class extends Stop {
            public function collectDirectCandidates(ServerInstanceInfo $info): array
            {
                return $this->collectDirectForceStopCandidatePids($info);
            }

            public function collectCleanupCandidates(string $name, ServerInstanceInfo $info): array
            {
                return $this->collectResidualCleanupCandidatePids($name, $info);
            }

            protected function collectResidualPrefixPids(string $name): array
            {
                unset($name);

                return [202, 101, 0];
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [303, 0];
            }
        };

        self::assertSame([101, 202, 303], $stop->collectDirectCandidates($info));
        self::assertSame([101, 202, 303], $stop->collectCleanupCandidates($info->name, $info));
    }

    public function testForceStopTerminationAlsoDelegatesScopedPrefixesToProcesser(): void
    {
        $info = new ServerInstanceInfo(
            'unit-prefix-terminate-4f7b',
            101,
            0,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16895,
            0,
            '2026-04-21 12:48:00',
            1776746880,
            []
        );

        $stop = new class extends Stop {
            public array $prefixCleanupNames = [];

            public function terminate(ServerInstanceInfo $info): int
            {
                return $this->terminateDirectForceStopCandidatePids($info);
            }

            protected function collectDirectForceStopCandidatePids(ServerInstanceInfo $info): array
            {
                unset($info);

                return [];
            }

            protected function terminateCurrentInstanceProcessPrefixes(string $name): int
            {
                $this->prefixCleanupNames[] = $name;

                return 7;
            }
        };

        self::assertSame(7, $stop->terminate($info));
        self::assertSame(['unit-prefix-terminate-4f7b'], $stop->prefixCleanupNames);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
