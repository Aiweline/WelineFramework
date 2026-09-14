<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Session\Server;

use PHPUnit\Framework\TestCase;
use Weline\Server\Session\Server\SessionStore;

final class SessionStoreMemoryPressureTest extends TestCase
{
    public function testAllocatedPressureIsHandledBelowTheLiveHighWatermark(): void
    {
        $store = $this->populatedStore();
        gc_mem_caches();
        $live = memory_get_usage(false);
        $allocated = memory_get_usage(true);
        self::assertGreaterThan($live + 32768, $allocated, 'The native allocator fixture needs retained pages.');
        $high = $live + intdiv($allocated - $live, 2);
        $this->watermarks($store, $high, $live - 131072);
        $evicted = $store->relieveMemoryPressure();
        $after = memory_get_usage(true);
        self::assertTrue($evicted > 0 || $after < $high, 'Allocated pressure must reclaim allocator pages or evict cache entries even while live usage is below the high watermark.');
    }

    public function testPinnedAllocatorPagesDoNotCauseAnUnboundedCachePurge(): void
    {
        $pins = [];
        $store = $this->populatedStore($pins);
        gc_mem_caches();
        $live = memory_get_usage(false);
        $allocated = memory_get_usage(true);
        self::assertGreaterThan($live + 32768, $allocated);
        $high = $live + intdiv($allocated - $live, 2);
        $this->watermarks($store, $high, $live - 131072);
        $before = $store->getStats()['session_count'];
        $evicted = $store->relieveMemoryPressure();
        $after = $store->getStats()['session_count'];
        self::assertGreaterThan(0, $evicted, 'Partially occupied allocator pages remain above the high watermark.');
        self::assertGreaterThanOrEqual((int)floor($before * 0.9), $after, 'Allocator-only pressure must not remove the entire cache when retained payload references prevent memory from falling.');
        self::assertCount($before, $pins, 'The test deliberately keeps every payload alive outside the store.');
    }

    public function testLowPressureDoesNotEvictOrRunAllocatorReclamation(): void
    {
        $store = $this->populatedStore();
        $allocated = memory_get_usage(true);
        $this->watermarks($store, $allocated + 4194304, $allocated + 2097152);
        $before = $store->getStats();
        $allocatedBefore = memory_get_usage(true);
        $evicted = $store->relieveMemoryPressure();
        $allocatedAfter = memory_get_usage(true);
        self::assertSame(0, $evicted);
        self::assertSame($allocatedBefore, $allocatedAfter);
        self::assertSame($before['session_count'], $store->getStats()['session_count']);
    }

    public function testCallerCanReserveTemporaryAllocationBeforeLiveHighWatermark(): void
    {
        $store = $this->populatedStore();
        $live = memory_get_usage(false);
        $high = memory_get_usage(true) + 1048576;
        $this->watermarks($store, $high, $high - 524288);
        $reserve = $high - $live + 1048576;
        self::assertSame(0, $store->relieveMemoryPressure());
        self::assertGreaterThan(0, $store->relieveMemoryPressure($reserve));
        self::assertFalse($store->exists('entry-0'), 'Existing LRU order must be preserved.');
        self::assertTrue($store->exists('entry-127'));
    }

    public function testLivePressureStillReleasesEntriesTowardTheLowWatermark(): void
    {
        $store = $this->populatedStore();
        $live = memory_get_usage(false);
        $this->watermarks($store, $live - 65536, $live - 262144);
        self::assertGreaterThan(0, $store->relieveMemoryPressure());
        self::assertFalse($store->exists('entry-0'));
        self::assertTrue($store->exists('entry-127'));
    }

    private function populatedStore(?array &$pins = null): SessionStore
    {
        $store = new SessionStore([
            'persist_enabled' => false,
            'memory_high_watermark_bytes' => PHP_INT_MAX,
            'memory_low_watermark_bytes' => PHP_INT_MAX - 1,
            'max_sessions' => 1000,
        ]);
        for ($i = 0; $i < 128; ++$i) {
            $value = str_repeat(chr(65 + $i % 26), 16384);
            if ($pins !== null) {
                $pins[] = $value;
            }
            $store->set('entry-' . $i, 'payload', $value, 3600);
        }
        return $store;
    }

    private function watermarks(SessionStore $store, int $high, int $low): void
    {
        (new \ReflectionProperty(SessionStore::class, 'memoryHighWatermarkBytes'))->setValue($store, $high);
        (new \ReflectionProperty(SessionStore::class, 'memoryLowWatermarkBytes'))->setValue($store, $low);
    }
}

