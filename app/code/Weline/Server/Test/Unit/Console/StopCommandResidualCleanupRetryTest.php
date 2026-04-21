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

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback);
                $this->attempts++;

                return $this->attempts === 1 ? 8 : 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true
            ): array
            {
                unset($name, $info, $remainingPorts, $includePrefixPids);

                return $this->attempts === 1 ? [701] : [];
            }

            protected function collectRunningResidualPids(array $pids): array
            {
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

    public function testResidualCleanupDefersPrefixFallbackUntilRetry(): void
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

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true
            ): int {
                unset($name, $info, $quiet);
                $this->prefixFallbackFlags[] = $allowPrefixFallback;

                return 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return \count($this->prefixFallbackFlags) === 1 ? [80] : [];
            }

            protected function cleanupRecoverableConfiguredPorts(array $ports, ?ServerInstanceInfo $info = null): int
            {
                unset($ports, $info);

                return 0;
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true
            ): array
            {
                unset($name, $info, $remainingPorts, $includePrefixPids);

                return [];
            }

            protected function collectRunningResidualPids(array $pids): array
            {
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

        self::assertSame([false, true], $stop->prefixFallbackFlags);
    }

    public function testResidualCleanupSkipsPrefixVerificationAfterPidCleanup(): void
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

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback);

                return 8;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true
            ): array {
                unset($name, $info, $remainingPorts);
                $this->prefixVerificationFlags[] = $includePrefixPids;

                return [];
            }

            protected function collectRunningResidualPids(array $pids): array
            {
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

        self::assertSame([false], $stop->prefixVerificationFlags);
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

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [91001];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback);
                $this->attempts++;

                return 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);
                $this->portChecks++;

                return [443];
            }

            protected function collectRunningResidualPids(array $pids): array
            {
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

            protected function collectResidualCleanupCandidatePids(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function runResidualCleanupPass(
                string $name,
                ServerInstanceInfo $info,
                bool $quiet = false,
                bool $allowPrefixFallback = true
            ): int {
                unset($name, $info, $quiet, $allowPrefixFallback);

                return 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function collectResidualVerificationPids(
                string $name,
                ServerInstanceInfo $info,
                array $remainingPorts,
                bool $includePrefixPids = true
            ): array {
                unset($name, $info, $remainingPorts);
                $this->prefixVerificationFlags[] = $includePrefixPids;

                return [];
            }

            protected function collectRunningResidualPids(array $pids): array
            {
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
}
