<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Console;

require_once __DIR__ . '/stop_test_bootstrap.php';

use PHPUnit\Framework\TestCase;
use Weline\Server\Console\Server\Stop;
use Weline\Server\Service\Contract\ServerInstanceInfo;

final class StopCommandResidualCleanupRetryTest extends TestCase
{
    public function testResidualCleanupRetriesWhilePrefixOnlyWorkerStillRunning(): void
    {
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

        $stop = new class extends Stop {
            public int $attempts = 0;

            public function runResidualCleanup(string $name, ServerInstanceInfo $info): void
            {
                $this->runResidualCleanupPairWithRetry($name, $info);
            }

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [];
            }

            protected function collectRecoverablePortsFromEndpointRecord(string $name, bool $includeSharedState = false): array
            {
                unset($name, $includeSharedState);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true,
                bool $includeSharedState = false
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback, $includeSharedState);
                $this->attempts++;

                return $this->attempts === 1 ? 8 : 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true,
                bool $includeSharedState = false
            ): array
            {
                unset($name, $info, $remainingPorts, $includePrefixPids, $includeSharedState);

                return $this->attempts === 1 ? [701] : [];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);

                return $pids;
            }
        };

        $stop->__init();

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $stop->runResidualCleanup('default', $info);
            $output = (string) \ob_get_clean();
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }

        self::assertSame(2, $stop->attempts);
        self::assertNotSame('', trim($output));
    }

    public function testResidualCleanupDoesNotDispatchKillForExitedHistoricalPids(): void
    {
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

        $stop = new class extends Stop {
            public int $runningChecks = 0;

            public function runCleanupPass(string $name, ServerInstanceInfo $info): int
            {
                return $this->runResidualCleanupPass($name, $info, true);
            }

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [701, 702];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);
                $this->runningChecks++;
                \PHPUnit\Framework\Assert::assertSame([701, 702], \array_values($pids));

                return [];
            }

            protected function terminateCurrentInstanceProcessPrefixes(string $name, bool $includeSharedState = false): int
            {
                unset($name, $includeSharedState);

                return 0;
            }

            protected function cleanupStaleRecoverableProcessPidFiles(): void
            {
            }
        };

        self::assertSame(0, $stop->runCleanupPass('default', $info));
        self::assertSame(1, $stop->runningChecks);
    }

    public function testResidualCleanupUsesPrefixFallbackOnEveryAttempt(): void
    {
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

        $stop = new class extends Stop {
            /** @var list<bool> */
            public array $prefixFallbackFlags = [];

            public function runResidualCleanup(string $name, ServerInstanceInfo $info): void
            {
                $this->runResidualCleanupPairWithRetry($name, $info);
            }

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [];
            }

            protected function collectRecoverablePortsFromEndpointRecord(string $name, bool $includeSharedState = false): array
            {
                unset($name, $includeSharedState);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true,
                bool $includeSharedState = false
            ): int {
                unset($name, $info, $quiet);
                $this->prefixFallbackFlags[] = $allowPrefixFallback;

                return 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return \count($this->prefixFallbackFlags) === 1 ? [80] : [];
            }

            protected function cleanupRecoverableConfiguredPorts(array $ports, ?ServerInstanceInfo $info = null, bool $includeSharedState = false): int
            {
                unset($ports, $info, $includeSharedState);

                return 0;
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true,
                bool $includeSharedState = false
            ): array
            {
                unset($name, $info, $remainingPorts, $includePrefixPids, $includeSharedState);

                return [];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);

                return $pids;
            }
        };

        $stop->__init();

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $stop->runResidualCleanup('default', $info);
            \ob_end_clean();
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }

        self::assertSame([true, true], $stop->prefixFallbackFlags);
    }

    public function testResidualCleanupKeepsPrefixVerificationAfterPidCleanup(): void
    {
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

        $stop = new class extends Stop {
            /** @var list<bool> */
            public array $prefixVerificationFlags = [];

            public function runResidualCleanup(string $name, ServerInstanceInfo $info): void
            {
                $this->runResidualCleanupPairWithRetry($name, $info);
            }

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true,
                bool $includeSharedState = false
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback, $includeSharedState);

                return 8;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true,
                bool $includeSharedState = false
            ): array {
                unset($name, $info, $remainingPorts, $includeSharedState);
                $this->prefixVerificationFlags[] = $includePrefixPids;

                return [];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);

                return $pids;
            }
        };

        $stop->__init();

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $stop->runResidualCleanup('default', $info);
            \ob_end_clean();
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }

        self::assertSame([true], $stop->prefixVerificationFlags);
    }

    public function testResidualCleanupSkipsPortFallbackWhileKnownPidsStillRun(): void
    {
        $info = new ServerInstanceInfo(
            'default',
            91001,
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

        $stop = new class extends Stop {
            public int $attempts = 0;
            public int $portChecks = 0;

            public function runResidualCleanup(string $name, ServerInstanceInfo $info): void
            {
                $this->runResidualCleanupPairWithRetry($name, $info);
            }

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [91001];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true,
                bool $includeSharedState = false
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback, $includeSharedState);
                $this->attempts++;

                return 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);
                $this->portChecks++;

                return [443];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);

                return $pids === [] ? [] : [91001];
            }
        };

        $stop->__init();

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $stop->runResidualCleanup('default', $info);
            \ob_end_clean();
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }

        self::assertSame(1, $stop->attempts);
        self::assertSame(0, $stop->portChecks);
    }

    public function testResidualCleanupStillUsesPrefixVerificationWhenNoPidWasHandled(): void
    {
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

        $stop = new class extends Stop {
            /** @var list<bool> */
            public array $prefixVerificationFlags = [];

            public function runResidualCleanup(string $name, ServerInstanceInfo $info): void
            {
                $this->runResidualCleanupPairWithRetry($name, $info);
            }

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true,
                bool $includeSharedState = false
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback, $includeSharedState);

                return 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true,
                bool $includeSharedState = false
            ): array {
                unset($name, $info, $remainingPorts, $includeSharedState);
                $this->prefixVerificationFlags[] = $includePrefixPids;

                return [];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);

                return $pids;
            }
        };

        $stop->__init();

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $stop->runResidualCleanup('default', $info);
            \ob_end_clean();
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }

        self::assertSame([true], $stop->prefixVerificationFlags);
    }

    public function testResidualCleanupRefreshesPortOnlyResidueBeforeReportingFailure(): void
    {
        $info = new ServerInstanceInfo(
            'default',
            0,
            26895,
            '127.0.0.1',
            443,
            true,
            false,
            1,
            16895,
            0,
            '2026-04-20 10:19:17',
            1776651557,
            []
        );

        $stop = new class extends Stop {
            public int $portProbeCount = 0;

            public function runResidualCleanup(string $name, ServerInstanceInfo $info): bool
            {
                $this->runResidualCleanupPairWithRetry($name, $info);

                return $this->wasLastResidualCleanupComplete();
            }

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info, bool $includeSharedState = false): array
            {
                unset($name, $info, $includeSharedState);

                return [];
            }

            protected function getRecoverableConfiguredPorts(string $name): array
            {
                unset($name);

                return [];
            }

            protected function collectRecoverablePortsFromEndpointRecord(string $name, bool $includeSharedState = false): array
            {
                unset($name, $includeSharedState);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true,
                bool $includeSharedState = false
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback, $includeSharedState);

                return 0;
            }

            protected function queryRecoverablePortOccupant(int $port): array
            {
                if ($port !== 26895) {
                    return [
                        'in_use' => false,
                        'pid' => 0,
                        'pid_running' => false,
                        'is_weline' => false,
                        'state' => 'free',
                    ];
                }

                $this->portProbeCount++;
                if ($this->portProbeCount === 1) {
                    return [
                        'in_use' => true,
                        'pid' => 0,
                        'pid_running' => false,
                        'is_weline' => true,
                        'state' => 'weline',
                    ];
                }

                return [
                    'in_use' => false,
                    'pid' => 0,
                    'pid_running' => false,
                    'is_weline' => false,
                    'state' => 'free',
                ];
            }

            protected function getPortProcessId(int $port): int
            {
                unset($port);

                return 0;
            }

            protected function isRecoverableWlsPortResponder(int $port): bool
            {
                unset($port);

                return false;
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true,
                bool $includeSharedState = false
            ): array {
                unset($name, $info, $remainingPorts, $includePrefixPids, $includeSharedState);

                return [];
            }

            protected function collectRunningResidualPids(array $pids, array $trustedPids = []): array
            {
                unset($trustedPids);

                return $pids;
            }
        };

        $stop->__init();

        $bufferLevel = \ob_get_level();
        \ob_start();
        try {
            $completed = $stop->runResidualCleanup('default', $info);
            $output = (string) \ob_get_clean();
        } finally {
            while (\ob_get_level() > $bufferLevel) {
                \ob_end_clean();
            }
        }

        self::assertTrue($completed);
        self::assertGreaterThanOrEqual(2, $stop->portProbeCount);
        self::assertStringNotContainsString('残留清理未完全完成', $output);
    }
}
