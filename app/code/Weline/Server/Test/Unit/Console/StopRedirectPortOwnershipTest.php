<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\ServerInstanceManager;

final class StopRedirectPortOwnershipTest extends TestCase
{
    public function testResolveManagedStopRootPidPromotesManagedShellParent(): void
    {
        $stop = new class extends Stop {
            public function resolveRoot(int $pid): int
            {
                return $this->resolveManagedStopRootPid($pid);
            }

            protected function isStopPidRunning(int $pid): bool
            {
                return \in_array($pid, [321, 654], true);
            }

            protected function getStopParentPid(int $pid): int
            {
                return match ($pid) {
                    321 => 654,
                    654 => 0,
                    default => 0,
                };
            }

            protected function isStopWelineServerProcess(int $pid): bool
            {
                return \in_array($pid, [321, 654], true);
            }

            protected function isStopProcessManagerCreated(int $pid): bool
            {
                return $pid === 654;
            }

            protected function getStopProcessCommandLine(int $pid): string
            {
                return match ($pid) {
                    321 => 'php worker.php --name=weline-wls-worker-default-1',
                    654 => 'cmd.exe /d /c "php worker.php --name=weline-wls-worker-default-1"',
                    default => '',
                };
            }
        };

        self::assertSame(654, $stop->resolveRoot(321));
    }

    public function testFindWelineServerInstanceNameByPortUsesInstanceManagerForFrameworkOwnedPort(): void
    {
        $manager = new class extends ServerInstanceManager {
            public function findRunningInstanceNameByPort(int $port): ?string
            {
                return $port === 80 ? 'default' : null;
            }
        };

        $stop = new class($manager) extends Stop {
            public function __construct(private readonly ServerInstanceManager $manager)
            {
            }

            public function findByPort(int $port): ?string
            {
                return $this->findWelineServerInstanceNameByPort($port);
            }

            protected function getInstanceManager(): ServerInstanceManager
            {
                return $this->manager;
            }

            protected function inspectPortOccupantWithHistory(int $port): array
            {
                unset($port);

                return [
                    'pid_running' => true,
                    'is_weline' => true,
                ];
            }
        };

        self::assertSame('default', $stop->findByPort(80));
    }

    public function testResolveConfiguredHttpRedirectPortPrefersExplicitRedirectPort(): void
    {
        $stop = new class extends Stop {
            public function resolve(array $data): int
            {
                return $this->resolveConfiguredHttpRedirectPort($data);
            }
        };

        self::assertSame(18080, $stop->resolve([
            'port' => 17443,
            'ssl_enabled' => true,
            'http_redirect_port' => 18080,
        ]));
    }

    public function testResolveConfiguredHttpRedirectPortInfersPort80FromHttpsMainPort(): void
    {
        $stop = new class extends Stop {
            public function resolve(array $data): int
            {
                return $this->resolveConfiguredHttpRedirectPort($data);
            }
        };

        self::assertSame(80, $stop->resolve([
            'port' => 443,
            'ssl_enabled' => true,
            'http_redirect_port' => 0,
        ]));
    }

    public function testKillWlsProcessOnPortTerminatesResolvedManagedRootTree(): void
    {
        $stop = new class extends Stop {
            public array $killedPids = [];

            public function __construct()
            {
                $this->__init();
            }

            protected function getPortProcessId(int $port): int
            {
                return $port === 80 ? 321 : 0;
            }

            protected function isStopPidRunning(int $pid): bool
            {
                return \in_array($pid, [321, 654], true);
            }

            protected function getProcessPnameByPid(int $pid): string
            {
                return $pid === 321 ? '--name=weline-wls-worker-default-1' : 'unknown';
            }

            protected function getStopProcessCommandLine(int $pid): string
            {
                return match ($pid) {
                    321 => 'php http_redirect_worker.php --name=weline-http-redirect-default',
                    654 => 'cmd.exe /d /c "php http_redirect_worker.php --name=weline-http-redirect-default"',
                    default => '',
                };
            }

            protected function isStopWelineServerProcess(int $pid): bool
            {
                return \in_array($pid, [321, 654], true);
            }

            protected function isStopProcessManagerCreated(int $pid): bool
            {
                return $pid === 654;
            }

            protected function getStopParentPid(int $pid): int
            {
                return $pid === 321 ? 654 : 0;
            }

            protected function killManagedProcessTreeForStop(int $pid): bool
            {
                $this->killedPids[] = $pid;

                return true;
            }

            protected function logWlsPortTermination(int $port, int $pid, int $killPid): void
            {
                unset($port, $pid, $killPid);
            }
        };

        self::assertTrue($stop->killWlsProcessOnPort(80));
        self::assertSame([654], $stop->killedPids);
    }
}
