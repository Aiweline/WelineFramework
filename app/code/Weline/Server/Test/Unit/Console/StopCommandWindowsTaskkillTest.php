<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;
use Weline\Server\Service\Contract\ServiceInfo;
use Weline\Server\Service\Contract\ServiceInstance;

final class StopCommandWindowsTaskkillTest extends TestCase
{
    public function testTreeKillUsesBoundedTaskkillSequenceOnWindows(): void
    {
        $stop = new class extends Stop {
            /** @var list<array{pid:int,tree:bool}> */
            public array $calls = [];
            private int $runningChecks = 0;

            public function killTree(int $pid): bool
            {
                return $this->queryKillManagedProcessTreeForStop($pid);
            }

            protected function isWindowsPlatform(): bool
            {
                return true;
            }

            protected function executeWindowsTaskkillForStop(int $pid, bool $tree): int
            {
                $this->calls[] = ['pid' => $pid, 'tree' => $tree];

                return 1;
            }

            protected function queryStopPidRunning(int $pid): bool
            {
                unset($pid);
                $this->runningChecks++;

                return $this->runningChecks < 4;
            }
        };

        self::assertTrue($stop->killTree(18628));
        self::assertSame(
            [
                ['pid' => 18628, 'tree' => true],
            ],
            $stop->calls
        );
    }

    public function testSinglePidKillUsesDirectTaskkillOnWindows(): void
    {
        $stop = new class extends Stop {
            /** @var list<array{pid:int,tree:bool}> */
            public array $calls = [];
            private int $runningChecks = 0;

            public function killPid(int $pid): bool
            {
                return $this->killWindowsProcessForStop($pid, false);
            }

            protected function isWindowsPlatform(): bool
            {
                return true;
            }

            protected function executeWindowsTaskkillForStop(int $pid, bool $tree): int
            {
                $this->calls[] = ['pid' => $pid, 'tree' => $tree];

                return 1;
            }

            protected function queryStopPidRunning(int $pid): bool
            {
                unset($pid);
                $this->runningChecks++;

                return $this->runningChecks < 2;
            }
        };

        self::assertTrue($stop->killPid(15364));
        self::assertSame(
            [
                ['pid' => 15364, 'tree' => false],
            ],
            $stop->calls
        );
    }

    public function testWindowsRootResolutionDoesNotQueryParentWrapperCommandLine(): void
    {
        $stop = new class extends Stop {
            public function root(int $pid): int
            {
                return $this->resolveManagedStopRootPid($pid);
            }

            protected function isWindowsPlatform(): bool
            {
                return true;
            }

            protected function queryStopPidRunning(int $pid): bool
            {
                return $pid === 48592;
            }

            protected function queryStopParentPid(int $pid): int
            {
                throw new \RuntimeException('parent PID should not be queried on Windows stop cleanup');
            }

            protected function queryStopProcessCommandLine(int $pid): string
            {
                throw new \RuntimeException('parent command line should not be queried on Windows stop cleanup');
            }
        };

        self::assertSame(48592, $stop->root(48592));
    }

    public function testWindowsFrontendShellTitleScanningStaysOutOfStopCommand(): void
    {
        $reflection = new \ReflectionClass(Stop::class);

        self::assertFalse($reflection->hasMethod('collectWindowsFrontendShellPidsByTitle'));
    }

    public function testWindowTitleTasklistCollectionStaysOutOfStopCommand(): void
    {
        $reflection = new \ReflectionClass(Stop::class);

        self::assertFalse($reflection->hasMethod('buildWindowsCmdWindowTitleListCommand'));
    }

    public function testRecoverableKnownPortsCanIncludeOwnedSharedStateForBootstrapCleanup(): void
    {
        $stop = new class extends Stop {
            public function ports(ServerInstanceInfo $info, bool $includeSharedState): array
            {
                return $this->collectRecoverableKnownPorts($info->name, $info, $includeSharedState);
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [];
            }

            protected function getRawStopInstanceData(string $name): ?array
            {
                unset($name);

                return null;
            }
        };

        $info = self::createInstanceInfo('codex-perf-0424');

        self::assertNotContains(26422, $stop->ports($info, false));
        self::assertNotContains(26423, $stop->ports($info, false));
        self::assertNotContains(26422, $stop->ports($info, true));
        self::assertNotContains(26423, $stop->ports($info, true));
    }

    public function testSharedStatePortCleanupRequiresLiveOwnerToMatchStoppedInstance(): void
    {
        $stop = new class extends Stop {
            /** @var array<int, array{in_use:bool,pid:int,pid_running:bool,is_weline:bool,state:string}> */
            public array $ports = [];
            /** @var array<int, string> */
            public array $processNames = [];

            public function remaining(ServerInstanceInfo $info, bool $includeSharedState): array
            {
                return $this->collectRemainingRecoverableWlsPorts($info->name, $info, $includeSharedState);
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [];
            }

            protected function getRawStopInstanceData(string $name): ?array
            {
                unset($name);

                return null;
            }

            protected function inspectRecoverablePortOccupant(int $port): array
            {
                return $this->ports[$port] ?? [
                    'in_use' => false,
                    'pid' => 0,
                    'pid_running' => false,
                    'is_weline' => false,
                    'state' => 'free',
                ];
            }

            protected function queryStopPidRunning(int $pid): bool
            {
                return isset($this->processNames[$pid]);
            }

            protected function getProcessPnameByPid(int $pid): string
            {
                return $this->processNames[$pid] ?? '';
            }

            protected function queryStopProcessCommandLine(int $pid): string
            {
                return $this->processNames[$pid] ?? '';
            }
        };

        $info = self::createInstanceInfo('codex-perf-0424');
        $stop->ports = [
            26422 => ['in_use' => true, 'pid' => 111, 'pid_running' => true, 'is_weline' => true, 'state' => 'weline'],
            26423 => ['in_use' => true, 'pid' => 222, 'pid_running' => true, 'is_weline' => true, 'state' => 'weline'],
        ];
        $stop->processNames = [
            111 => '--name=weline-wls-session-codex-perf-0424-p11005ce4 --instance-name=codex-perf-0424',
            222 => '--name=weline-wls-memory-default-p11005ce4 --instance-name=default',
        ];

        self::assertSame([], $stop->remaining($info, false));
        self::assertSame([], $stop->remaining($info, true));
    }

    private static function createInstanceInfo(string $name): ServerInstanceInfo
    {
        return new ServerInstanceInfo(
            name: $name,
            masterPid: 333,
            controlPort: 35965,
            host: 'p11005ce4.weline.test',
            port: 9512,
            sslEnabled: false,
            dispatcherEnabled: false,
            workerCount: 1,
            workerBasePort: 25964,
            httpRedirectPort: 0,
            startedAt: '2026-04-24 04:20:49',
            startedTimestamp: 1777004449,
            services: [
                new ServiceInfo(
                    role: 'session_server',
                    displayName: 'Session Server',
                    instanceId: 1,
                    pid: 111,
                    port: 26422,
                    state: ServiceInstance::STATE_READY,
                    metadata: ['process_name' => 'weline-wls-session-' . $name . '-p11005ce4'],
                    rootPid: 111,
                    launcherPid: 111
                ),
                new ServiceInfo(
                    role: 'memory_server',
                    displayName: 'Memory Service',
                    instanceId: 1,
                    pid: 222,
                    port: 26423,
                    state: ServiceInstance::STATE_READY,
                    metadata: ['process_name' => 'weline-wls-memory-' . $name . '-p11005ce4'],
                    rootPid: 222,
                    launcherPid: 222
                ),
                new ServiceInfo(
                    role: 'worker',
                    displayName: 'HTTP Worker',
                    instanceId: 1,
                    pid: 0,
                    port: 25964,
                    state: ServiceInstance::STATE_STARTING
                ),
            ]
        );
    }
}
