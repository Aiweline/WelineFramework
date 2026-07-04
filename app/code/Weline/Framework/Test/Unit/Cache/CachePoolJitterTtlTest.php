<?php

declare(strict_types=1);

/**
 * CachePool TTL 抖动单测（防雪崩）
 */

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CachePoolJitterTtlTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $objectManagerInstancesBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->objectManagerInstancesBackup = $this->getObjectManagerInstances();
        $instances = $this->objectManagerInstancesBackup;
        $instances[EventsManager::class] = new class {
            /**
             * @param array<string, mixed> $data
             */
            public function dispatch(string $name, array $data = []): void
            {
            }
        };
        $this->setObjectManagerInstances($instances);
    }

    protected function tearDown(): void
    {
        $this->setObjectManagerInstances($this->objectManagerInstancesBackup);
        parent::tearDown();
    }

    public function testTtlJitterFallsWithinExpectedRange(): void
    {
        $adapter = new CachePoolJitterSpyAdapter();
        $pool = new CachePool('jitter_pool', $adapter, '', false, 1800, true, 0.10);

        $samples = 100;
        for ($i = 0; $i < $samples; $i++) {
            $pool->set('key_' . $i, 'value');
        }

        $this->assertCount($samples, $adapter->ttls);

        $minAcceptable = (int) \floor(1800 * 0.9);
        $maxAcceptable = (int) \ceil(1800 * 1.1);

        $unique = [];
        foreach ($adapter->ttls as $ttl) {
            $this->assertGreaterThanOrEqual($minAcceptable, $ttl, 'TTL should not drop below 90%');
            $this->assertLessThanOrEqual($maxAcceptable, $ttl, 'TTL should not exceed 110%');
            $unique[$ttl] = true;
        }

        $this->assertGreaterThan(1, \count($unique), 'TTL should vary across writes');
    }

    public function testPermanentPoolIgnoresJitter(): void
    {
        $adapter = new CachePoolJitterSpyAdapter();
        $pool = new CachePool('jitter_perm_pool', $adapter, '', true, 86400, true, 0.20);

        for ($i = 0; $i < 10; $i++) {
            $pool->set('key_' . $i, 'v');
        }

        foreach ($adapter->ttls as $ttl) {
            $this->assertSame(86400, $ttl, 'Permanent pool must not jitter TTL');
        }
    }

    public function testShortTtlBypassesJitter(): void
    {
        $adapter = new CachePoolJitterSpyAdapter();
        $pool = new CachePool('jitter_short_pool', $adapter, '', false, 30, true, 0.20);

        for ($i = 0; $i < 10; $i++) {
            $pool->set('key_' . $i, 'v');
        }

        foreach ($adapter->ttls as $ttl) {
            $this->assertSame(30, $ttl, 'TTL below 60s should not jitter');
        }
    }

    public function testZeroJitterRatioDisablesJitter(): void
    {
        $adapter = new CachePoolJitterSpyAdapter();
        $pool = new CachePool('jitter_zero_pool', $adapter, '', false, 1800, true, 0.0);

        for ($i = 0; $i < 10; $i++) {
            $pool->set('key_' . $i, 'v');
        }

        foreach ($adapter->ttls as $ttl) {
            $this->assertSame(1800, $ttl, 'Zero ratio means no jitter');
        }
    }

    public function testStatsExposesJitterRatio(): void
    {
        $adapter = new CachePoolJitterSpyAdapter();
        $pool = new CachePool('jitter_stats_pool', $adapter, '', false, 1800, true, 0.15);

        $stats = $pool->getStats();
        $this->assertArrayHasKey('jitter_ratio', $stats);
        $this->assertEqualsWithDelta(0.15, $stats['jitter_ratio'], 0.0001);
    }

    /**
     * @return array<string, mixed>
     */
    private function getObjectManagerInstances(): array
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);

        /** @var array<string, mixed> $instances */
        $instances = $property->getValue();
        return $instances;
    }

    /**
     * @param array<string, mixed> $instances
     */
    private function setObjectManagerInstances(array $instances): void
    {
        $reflection = new ReflectionClass(ObjectManager::class);
        $property = $reflection->getProperty('instances');
        $property->setAccessible(true);
        $property->setValue(null, $instances);
    }
}

final class CachePoolJitterSpyAdapter implements CacheAdapterInterface
{
    /** @var array<int, int> */
    public array $ttls = [];

    /** @var array<string, mixed> */
    private array $storage = [];

    public function get(string $key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->storage[$key] = $value;
        $this->ttls[] = $ttl;
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
