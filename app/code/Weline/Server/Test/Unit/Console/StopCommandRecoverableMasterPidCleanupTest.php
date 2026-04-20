<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

final class StopCommandRecoverableMasterPidCleanupTest extends TestCase
{
    public function testCleanupRecoverableProcessesWithoutInstanceFileAlsoTerminatesInferredMasterPid(): void
    {
        $stop = new class extends Stop {
            public array $terminatedPids = [];
            public array $killedPrefixes = [];

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [48372, 52084, 48372];
            }

            protected function terminateRecoverableProcessIds(array $pids): int
            {
                $this->terminatedPids = $pids;

                return \count($pids);
            }

            protected function killRecoverableProcessPrefix(string $prefix): int
            {
                $this->killedPrefixes[] = $prefix;

                return 0;
            }

            protected function hasRecoverableManagedProcessHint(string $name): bool
            {
                unset($name);

                return false;
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [];
            }

            protected function cleanupStaleRecoverableProcessPidFiles(): void
            {
            }
        };

        self::assertSame(2, $this->invokeCleanup($stop, 'default', false));
        self::assertSame([48372, 52084], $stop->terminatedPids);
        self::assertNotSame([], $stop->killedPrefixes);
    }

    private function invokeCleanup(Stop $stop, string $instanceName, bool $dryRun): int
    {
        $reflection = new \ReflectionMethod($stop, 'cleanupRecoverableProcessesWithoutInstanceFile');
        $reflection->setAccessible(true);

        return (int) $reflection->invoke($stop, $instanceName, $dryRun);
    }
}
