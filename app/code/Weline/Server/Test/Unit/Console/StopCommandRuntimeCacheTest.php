<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;

final class StopCommandRuntimeCacheTest extends TestCase
{
    public function testMasterAvailabilityIgnoresStaleFastExitMarkerWhenPidExists(): void
    {
        $info = new ServerInstanceInfo(
            'default',
            12345,
            26895,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16895,
            80,
            '2026-04-21 09:00:00',
            1770000000,
            []
        );

        $stop = new class extends Stop {
            public function available(ServerInstanceInfo $info): bool
            {
                return $this->isMasterProcessAvailableForStop($info);
            }
        };

        self::assertTrue($stop->available($info));
    }

    public function testResolveManagedStopRootPidSkipsSlowWindowsProcessLookups(): void
    {
        $stop = new class extends Stop {
            /** @var array<string, int> */
            public array $calls = [
                'running' => 0,
                'command' => 0,
                'weline' => 0,
                'manager' => 0,
                'parent' => 0,
            ];

            protected function queryStopPidRunning(int $pid): bool
            {
                $this->calls['running']++;

                return \in_array($pid, [100, 50], true);
            }

            protected function queryStopProcessCommandLine(int $pid): string
            {
                $this->calls['command']++;

                return $pid === 50 ? 'cmd.exe /d /c worker.cmd' : '';
            }

            protected function queryStopWelineServerProcess(int $pid): bool
            {
                $this->calls['weline']++;

                return $pid === 50;
            }

            protected function queryStopProcessManagerCreated(int $pid): bool
            {
                $this->calls['manager']++;

                return false;
            }

            protected function queryStopParentPid(int $pid): int
            {
                $this->calls['parent']++;

                return $pid === 100 ? 50 : 0;
            }
        };

        $first = $this->invokeProtected($stop, 'resolveManagedStopRootPid', 100);
        $second = $this->invokeProtected($stop, 'resolveManagedStopRootPid', 100);

        self::assertSame(100, $first);
        self::assertSame(100, $second);
        self::assertSame(
            [
                'running' => 0,
                'command' => 0,
                'weline' => 0,
                'manager' => 0,
                'parent' => 0,
            ],
            $stop->calls
        );
    }

    public function testKillInvalidatesRuntimeCaches(): void
    {
        $stop = new class extends Stop {
            public int $runningQueries = 0;
            public int $killQueries = 0;
            public int $portQueries = 0;
            public int $headerQueries = 0;

            protected function queryStopPidRunning(int $pid): bool
            {
                unset($pid);
                $this->runningQueries++;

                return true;
            }

            protected function queryKillManagedProcessTreeForStop(int $pid): bool
            {
                unset($pid);
                $this->killQueries++;

                return true;
            }

            protected function queryRecoverablePortOccupant(int $port): array
            {
                $this->portQueries++;

                return [
                    'in_use' => true,
                    'pid' => $port + 1000,
                    'pid_running' => true,
                    'is_weline' => true,
                    'state' => 'weline',
                ];
            }

            protected function queryRecoverablePortHeaders(int $port, string $transport): string
            {
                unset($port, $transport);
                $this->headerQueries++;

                return "Server: Weline-Server\r\n\r\n";
            }
        };

        self::assertTrue($this->invokeProtected($stop, 'isStopPidRunning', 100));
        self::assertTrue($this->invokeProtected($stop, 'isStopPidRunning', 100));
        self::assertSame(1, $stop->runningQueries);

        $firstInspect = $this->invokeProtected($stop, 'inspectRecoverablePortOccupant', 80);
        $secondInspect = $this->invokeProtected($stop, 'inspectRecoverablePortOccupant', 80);
        $firstHeaders = $this->invokeProtected($stop, 'readRecoverablePortHeaders', 80, 'tcp');
        $secondHeaders = $this->invokeProtected($stop, 'readRecoverablePortHeaders', 80, 'tcp');

        self::assertSame($firstInspect, $secondInspect);
        self::assertSame($firstHeaders, $secondHeaders);
        self::assertSame(1, $stop->portQueries);
        self::assertSame(1, $stop->headerQueries);

        self::assertTrue($this->invokeProtected($stop, 'killManagedProcessTreeForStop', 100));
        self::assertTrue($this->invokeProtected($stop, 'isStopPidRunning', 100));
        $this->invokeProtected($stop, 'inspectRecoverablePortOccupant', 80);
        $this->invokeProtected($stop, 'readRecoverablePortHeaders', 80, 'tcp');

        self::assertSame(2, $stop->runningQueries);
        self::assertSame(2, $stop->portQueries);
        self::assertSame(2, $stop->headerQueries);
        self::assertSame(1, $stop->killQueries);
    }

    public function testRecoverableConfiguredPortsAreMemoizedPerInstance(): void
    {
        $stop = new class extends Stop {
            /** @var list<string> */
            public array $names = [];

            protected function queryRecoverableConfiguredPorts(string $name): array
            {
                $this->names[] = $name;

                return $name === 'default' ? [80, 443] : [8080];
            }
        };

        self::assertSame([80, 443], $this->invokeProtected($stop, 'getRecoverableConfiguredPorts', 'default'));
        self::assertSame([80, 443], $this->invokeProtected($stop, 'getRecoverableConfiguredPorts', 'default'));
        self::assertSame([8080], $this->invokeProtected($stop, 'getRecoverableConfiguredPorts', 'other'));
        self::assertSame([8080], $this->invokeProtected($stop, 'getRecoverableConfiguredPorts', 'other'));
        self::assertSame(['default', 'other'], $stop->names);
    }

    private function invokeProtected(object $object, string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object, ...$args);
    }
}
