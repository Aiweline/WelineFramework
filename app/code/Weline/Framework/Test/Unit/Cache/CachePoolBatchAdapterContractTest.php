<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\BatchCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Pool\CachePool;

/**
 * Prove CachePool routes getMultiple/setMultiple through BatchCacheAdapterInterface
 * instead of N× get/set (pseudo-batch / RPC N+1 regression).
 */
final class CachePoolBatchAdapterContractTest extends TestCase
{
    public function testGetMultipleAndSetMultiplePreferBatchAdapterOnce(): void
    {
        $adapter = new CachePoolBatchSpyAdapter();
        $pool = new CachePool('unit_batch', $adapter, 'batch contract', false, 300, true, 0.0);

        self::assertTrue($pool->setMultiple([
            'a' => 'alpha',
            'b' => false,
            'c' => 0,
        ], 120));

        self::assertSame(1, $adapter->setMultipleCalls);
        self::assertSame(0, $adapter->setCalls);
        self::assertSame(120, $adapter->lastSetMultipleTtl);
        self::assertCount(3, $adapter->lastSetMultipleValues);

        $result = $pool->getMultiple(['a', 'b', 'c', 'missing']);

        self::assertSame(1, $adapter->getMultipleCalls);
        self::assertSame(0, $adapter->getCalls);
        self::assertSame('alpha', $result['a']);
        self::assertFalse($result['b']);
        self::assertSame(0, $result['c']);
        self::assertNull($result['missing']);

        $stats = $pool->getStats();
        self::assertSame(3, $stats['hits']);
        self::assertSame(1, $stats['misses']);
    }

    public function testNonBatchAdapterFallsBackToPerKeyLoop(): void
    {
        $adapter = new CachePoolSingleKeySpyAdapter();
        $pool = new CachePool('unit_single', $adapter, 'fallback', false, 300, true, 0.0);

        self::assertTrue($pool->setMultiple(['x' => 1, 'y' => 2], 60));
        self::assertSame(2, $adapter->setCalls);

        $result = $pool->getMultiple(['x', 'y', 'z']);
        self::assertSame(3, $adapter->getCalls);
        self::assertSame(1, $result['x']);
        self::assertSame(2, $result['y']);
        self::assertNull($result['z']);
    }

    public function testDisabledPoolSkipsBatchAdapterAndCountsMisses(): void
    {
        $adapter = new CachePoolBatchSpyAdapter();
        $pool = new CachePool('unit_disabled_batch', $adapter, 'disabled', false, 300, false);

        self::assertTrue($pool->setMultiple(['k' => 'v']));
        self::assertSame(['k' => null], $pool->getMultiple(['k']));
        self::assertSame(0, $adapter->setMultipleCalls);
        self::assertSame(0, $adapter->getMultipleCalls);
        self::assertSame(1, $pool->getStats()['misses']);
    }

    public function testSetMultipleAppliesJitterOnceForWholeBatch(): void
    {
        $adapter = new CachePoolBatchSpyAdapter();
        // jitter_ratio=0.5 on TTL>=60 so applyJitter can move the value.
        $pool = new CachePool('unit_jitter_batch', $adapter, 'jitter', false, 300, true, 0.5);

        self::assertTrue($pool->setMultiple(['one' => 1, 'two' => 2], 100));
        self::assertSame(1, $adapter->setMultipleCalls);
        self::assertCount(2, $adapter->lastSetMultipleValues);
        // Both keys share the same already-jittered TTL argument.
        self::assertGreaterThanOrEqual(50, $adapter->lastSetMultipleTtl);
        self::assertLessThanOrEqual(150, $adapter->lastSetMultipleTtl);
    }
}

final class CachePoolBatchSpyAdapter implements BatchCacheAdapterInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;
    public int $getMultipleCalls = 0;
    public int $setMultipleCalls = 0;
    public int $lastSetMultipleTtl = 0;

    /** @var array<string, mixed> */
    public array $lastSetMultipleValues = [];

    /** @var array<string, mixed> */
    private array $storage = [];

    public function get(string $key): mixed
    {
        $this->getCalls++;
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->setCalls++;
        $this->storage[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->storage);
    }

    public function getMultiple(array $keys): array
    {
        $this->getMultipleCalls++;
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->storage[$key] ?? null;
        }

        return $out;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        $this->setMultipleCalls++;
        $this->lastSetMultipleTtl = $ttl;
        $this->lastSetMultipleValues = $values;
        foreach ($values as $key => $value) {
            $this->storage[(string) $key] = $value;
        }

        return true;
    }
}

final class CachePoolSingleKeySpyAdapter implements CacheAdapterInterface
{
    public int $getCalls = 0;
    public int $setCalls = 0;

    /** @var array<string, mixed> */
    private array $storage = [];

    public function get(string $key): mixed
    {
        $this->getCalls++;
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->setCalls++;
        $this->storage[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->storage[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->storage = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->storage);
    }
}
