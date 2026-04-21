<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

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
                ['pid' => 18628, 'tree' => true],
                ['pid' => 18628, 'tree' => true],
                ['pid' => 18628, 'tree' => false],
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
                return $this->queryKillStopPid($pid, true);
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
                ['pid' => 15364, 'tree' => false],
            ],
            $stop->calls
        );
    }

    public function testBatchKillCommandRunsTaskkillThroughCmdShell(): void
    {
        $stop = new class extends Stop {
            public function command(array $ids): string
            {
                return $this->buildWindowsBatchStopCommand($ids);
            }
        };

        self::assertSame(
            'cmd /d /c start "" /B cmd /d /c "taskkill /F /T /PID 101 /PID 202 1>NUL 2>NUL"',
            $stop->command([101, 202])
        );
    }

    public function testRunningPidScanUsesTasklistInsteadOfPowerShell(): void
    {
        $stop = new class extends Stop {
            public function command(array $ids): string
            {
                return $this->buildWindowsCollectRunningPidsCommand($ids);
            }
        };

        self::assertSame('tasklist /FO CSV /NH', $stop->command([101, 202]));
    }

    public function testWindowsStopTargetsDoNotProbeParentsDuringBatchKill(): void
    {
        $stop = new class extends Stop {
            public function expand(array $pids): array
            {
                return $this->expandWindowsStopTargetPids($pids);
            }

            protected function queryStopParentPid(int $pid): int
            {
                unset($pid);
                throw new \RuntimeException('parent PID probing should not run during batch stop expansion');
            }
        };

        self::assertSame([300], $stop->expand([300]));
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
}
