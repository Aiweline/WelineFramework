<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\WorkerResponseMemoryGuard;

final class WorkerResponseMemoryGuardCapacityScalarsContractTest extends TestCase
{
    public function testCapacityStatusScalarsExposeCompactCounters(): void
    {
        $scalars = WorkerResponseMemoryGuard::capacityStatusScalars();
        self::assertArrayHasKey('compact_soft_count', $scalars);
        self::assertArrayHasKey('compact_hard_count', $scalars);
        self::assertArrayHasKey('drain_request_count', $scalars);
        self::assertArrayHasKey('last_pressure', $scalars);
        self::assertIsInt($scalars['compact_soft_count']);
        self::assertIsInt($scalars['compact_hard_count']);
        self::assertIsInt($scalars['drain_request_count']);
        self::assertIsFloat($scalars['last_pressure']);
    }

    public function testWorkerSnapshotMergesCapacityScalars(): void
    {
        $base = \dirname(__DIR__, 3);
        $worker = $base . '/bin/worker.php';
        $ssl = $base . '/bin/worker_ssl.php';
        self::assertStringContainsString('capacityStatusScalars()', (string)\file_get_contents($worker));
        self::assertStringContainsString('capacityStatusScalars()', (string)\file_get_contents($ssl));
    }
}
