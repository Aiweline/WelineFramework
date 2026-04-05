<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Runtime;

use PHPUnit\Framework\TestCase;
use Weline\Server\Runtime\WorkerFiberHealthSnapshot;

final class WorkerFiberHealthSnapshotTest extends TestCase
{
    public function testBuildMapsSuspendedFiberToIdle(): void
    {
        $fiber = new \Fiber(static function (): void {
            \Fiber::suspend();
        });
        $fiber->start();
        self::assertTrue($fiber->isSuspended());

        $raw = "GET /stream HTTP/1.1\r\nHost: example.com\r\nAccept: text/event-stream\r\n\r\n";
        $list = WorkerFiberHealthSnapshot::build([
            42 => ['fiber' => $fiber, 'rawRequest' => $raw],
        ]);

        self::assertSame([
            ['conn_id' => 42, 'status' => 'idle', 'protocol' => 'sse'],
        ], $list);
    }
}
