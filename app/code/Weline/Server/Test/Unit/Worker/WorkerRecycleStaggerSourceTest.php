<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Worker;

use PHPUnit\Framework\TestCase;

final class WorkerRecycleStaggerSourceTest extends TestCase
{
    public function testHttpWorkerUsesConfigurableDeterministicRecycleBudget(): void
    {
        $source = (string)\file_get_contents(BP . 'app/code/Weline/Server/bin/worker.php');

        self::assertStringContainsString("['worker_max_requests']", $source);
        self::assertStringContainsString("['worker_recycle_stagger_requests']", $source);
        self::assertStringContainsString('$workerId - 1', $source);
        self::assertStringNotContainsString('$maxRequests = 10000', $source);
    }
}
