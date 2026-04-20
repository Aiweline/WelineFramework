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

            protected function runResidualCleanupPass(string $name, ServerInstanceInfo $info, bool $quiet = false): int
            {
                unset($name, $info, $quiet);
                $this->attempts++;

                return $this->attempts === 1 ? 8 : 0;
            }

            protected function collectRemainingRecoverableWlsPorts(string $name, ServerInstanceInfo $info): array
            {
                unset($name, $info);

                return [];
            }

            protected function collectResidualVerificationPids(string $name, ServerInstanceInfo $info, array $remainingPorts): array
            {
                unset($name, $info, $remainingPorts);

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
        self::assertStringContainsString('重试次数', $output);
    }
}
