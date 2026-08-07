<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

final class StopCommandRecoverableMasterPidCleanupTest extends TestCase
{
    public function testMissingInstanceCleanupUsesExactGenerationInsteadOfInferredPidsOrPrefixes(): void
    {
        $stop = new class extends Stop {
            /** @var list<string> */
            public array $retiredInstances = [];

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [48372, 52084, 48372];
            }

            protected function retireExactInstanceGeneration(string $name): array
            {
                $this->retiredInstances[] = $name;

                return [
                    'terminated' => 2,
                    'released' => 2,
                    'unreleased' => 0,
                    'reasons' => [],
                ];
            }

            protected function terminateRecoverableProcessIds(array $pids): int
            {
                unset($pids);

                throw new \RuntimeException('numeric PID list must not authorize termination');
            }

            protected function killRecoverableProcessPrefix(string $prefix): int
            {
                unset($prefix);

                throw new \RuntimeException('process-name prefix must not authorize termination');
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($pids, $trustedPids);

                return [];
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
        self::assertSame(['default'], $stop->retiredInstances);
    }

    private function invokeCleanup(Stop $stop, string $instanceName, bool $dryRun): int
    {
        $reflection = new \ReflectionMethod($stop, 'cleanupRecoverableProcessesWithoutInstanceFile');
        $reflection->setAccessible(true);

        return (int) $reflection->invoke($stop, $instanceName, $dryRun);
    }
}
