<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\Framework\Cache\Adapter\WlsMemoryAdapter;

class WlsMemoryAdapterTest extends TestCase
{
    public function testLocalMemoryStoreEvictsOldestEntries(): void
    {
        $adapter = new WlsMemoryAdapter('unit_wls_memory_evict', [
            'local_cache_size' => 10,
            'local_cache_memory_pressure_threshold' => 0.99,
        ]);

        $this->writeLocalCache($adapter, [
            'a' => 'A',
            'b' => 'B',
            'c' => 'C',
        ]);

        $this->assertSame(2, $adapter->evict(2));
        $this->assertSame(1, $adapter->getMemoryItemCount());
        $this->assertSame(['c'], \array_keys($this->readLocalCache($adapter)));
    }

    public function testClearMemoryDropsOnlyLocalCache(): void
    {
        $adapter = new WlsMemoryAdapter('unit_wls_memory_clear', [
            'local_cache_size' => 10,
            'local_cache_memory_pressure_threshold' => 0.99,
        ]);

        $this->writeLocalCache($adapter, [
            'a' => 'A',
            'b' => 'B',
        ]);

        $this->assertGreaterThan(0, $adapter->getMemoryUsage());
        $adapter->clearMemory();

        $this->assertSame(0, $adapter->getMemoryItemCount());
        $this->assertSame(0, $adapter->getMemoryUsage());
    }

    public function testOversizedValuesBypassWorkerLocalCache(): void
    {
        $adapter = new WlsMemoryAdapter('unit_wls_memory_large_value', [
            'max_memory' => 100,
            'local_cache_size' => 10,
            'local_cache_max_value_ratio' => 0.10,
            'local_cache_memory_pressure_threshold' => 0.99,
        ]);

        $this->invokeSetLocalCache($adapter, 'small', '12345');
        $this->invokeSetLocalCache($adapter, 'large', \str_repeat('x', 50));

        $localCache = $this->readLocalCache($adapter);

        $this->assertArrayHasKey('small', $localCache);
        $this->assertArrayNotHasKey('large', $localCache);
    }

    public function testHighMemoryPressureBypassesRemoteCacheReadAndWrite(): void
    {
        $previousLimit = \ini_get('memory_limit');
        @\ini_set('memory_limit', '512M');

        try {
            $adapter = new WlsMemoryAdapter('unit_wls_memory_pressure', [
                'local_cache_memory_pressure_threshold' => 0.0001,
            ]);

            $this->assertNull($adapter->get('remote_key'));
            $this->assertTrue($adapter->set('remote_key', 'value'));
            $this->assertSame(1, $adapter->getMisses());
            $this->assertSame(0, $adapter->getMemoryItemCount());
        } finally {
            if ($previousLimit !== false) {
                @\ini_set('memory_limit', $previousLimit);
            }
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function writeLocalCache(WlsMemoryAdapter $adapter, array $values): void
    {
        foreach ($values as $key => $value) {
            $this->invokeSetLocalCache($adapter, $key, $value);
        }
    }

    private function invokeSetLocalCache(WlsMemoryAdapter $adapter, string $key, mixed $value): void
    {
        $method = new ReflectionMethod($adapter, 'setLocalCache');
        $method->setAccessible(true);
        $method->invoke($adapter, $key, $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function readLocalCache(WlsMemoryAdapter $adapter): array
    {
        $reflection = new ReflectionClass($adapter);
        $property = $reflection->getProperty('localCache');
        $property->setAccessible(true);

        /** @var array<string, mixed> $localCache */
        $localCache = $property->getValue($adapter);
        return $localCache;
    }
}
