<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;

final class StopCommandRecoverablePortCleanupTest extends TestCase
{
    public function testCleanupRecoverableProcessesWithoutInstanceFileTreatsConfiguredWlsPortAsRecoverableInDryRun(): void
    {
        $stop = new class extends Stop {
            protected function hasRecoverableManagedProcessHint(string $name): bool
            {
                unset($name);

                return false;
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [443];
            }

            protected function inspectRecoverablePortOccupant(int $port): array
            {
                unset($port);

                return [
                    'in_use' => true,
                    'pid' => 27416,
                    'pid_running' => true,
                    'is_weline' => true,
                    'state' => 'weline',
                ];
            }

            protected function isRecoverableWlsPortResponder(int $port): bool
            {
                unset($port);

                return false;
            }
        };

        self::assertSame(1, $this->invokeCleanup($stop, 'default', true));
    }

    public function testCleanupRecoverableProcessesWithoutInstanceFileReleasesConfiguredWlsPorts(): void
    {
        $stop = new class extends Stop {
            public array $releasedPorts = [];

            protected function hasRecoverableManagedProcessHint(string $name): bool
            {
                unset($name);

                return false;
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [80, 443];
            }

            protected function inspectRecoverablePortOccupant(int $port): array
            {
                return [
                    'in_use' => true,
                    'pid' => $port,
                    'pid_running' => true,
                    'is_weline' => true,
                    'state' => 'weline',
                ];
            }

            protected function isRecoverableWlsPortResponder(int $port): bool
            {
                unset($port);

                return false;
            }

            protected function isRecoverablePortInUse(int $port): bool
            {
                unset($port);

                return false;
            }

            protected function cleanupStaleRecoverableProcessPidFiles(): void
            {
            }

            protected function collectRecoverableManagedPids(string $name): array
            {
                unset($name);

                return [];
            }

            protected function killRecoverableProcessPrefix(string $prefix): int
            {
                unset($prefix);

                return 0;
            }

            public function killWlsProcessOnPort(int $port): bool
            {
                $this->releasedPorts[] = $port;

                return true;
            }
        };

        self::assertSame(2, $this->invokeCleanup($stop, 'default', false));
        self::assertSame([80, 443], $stop->releasedPorts);
    }

    private function invokeCleanup(Stop $stop, string $instanceName, bool $dryRun): int
    {
        $reflection = new \ReflectionMethod($stop, 'cleanupRecoverableProcessesWithoutInstanceFile');
        $reflection->setAccessible(true);

        return (int) $reflection->invoke($stop, $instanceName, $dryRun);
    }
}
