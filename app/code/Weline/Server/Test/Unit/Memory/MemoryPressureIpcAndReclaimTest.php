<?php
declare(strict_types=1);

namespace Weline\Server\Test\Unit\Memory;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\FullPageCacheReclaimableAdapter;
use Weline\Framework\Runtime\MemoryReclaimableRegistry;
use Weline\Server\IPC\ControlMessage;

final class MemoryPressureIpcAndReclaimTest extends TestCase
{
    public function testMemoryPressureMessageEncodesLevelAndStagger(): void
    {
        $encoded = ControlMessage::memoryPressure('yellow', 50);
        $decoded = ControlMessage::decode($encoded);
        self::assertSame(ControlMessage::TYPE_MEMORY_PRESSURE, $decoded['type'] ?? null);
        self::assertSame('yellow', $decoded['level'] ?? null);
        self::assertSame(50, (int)($decoded['stagger_ms'] ?? 0));
    }

    public function testReclaimReportIncludesBytes(): void
    {
        $encoded = ControlMessage::memoryReclaimReport(4096, 'red', ['worker_id' => 2]);
        $decoded = ControlMessage::decode($encoded);
        self::assertSame(ControlMessage::TYPE_MEMORY_RECLAIM_REPORT, $decoded['type'] ?? null);
        self::assertSame(4096, (int)($decoded['reclaim_bytes'] ?? 0));
        self::assertSame('red', $decoded['host_level_applied'] ?? null);
        self::assertSame(2, (int)($decoded['worker_id'] ?? 0));
    }

    public function testFpcAdapterIsLastResortPriority(): void
    {
        $adapter = new FullPageCacheReclaimableAdapter();
        self::assertSame(100, $adapter->reclaimPriority());
        $registry = new MemoryReclaimableRegistry();
        $registry->register($adapter);
        self::assertCount(1, $registry->all());
    }
}
