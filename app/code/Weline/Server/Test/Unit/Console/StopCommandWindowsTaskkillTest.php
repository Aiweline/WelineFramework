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
}
