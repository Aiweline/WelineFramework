<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;

final class WlsMemoryAdapterAtomicTest extends TestCase
{
    public function testCasConflictEvictsWorkerLocalStaleSnapshot(): void
    {
        $shared = new SharedCacheStateDouble();
        $shared->setCache('rate', 'quota', ['count' => 1]);
        $adapter = new WlsMemoryAdapter('rate', [
            'local_cache_size' => 10,
            'local_cache_memory_pressure_threshold' => 0.99,
        ], $shared);

        self::assertSame(['count' => 1], $adapter->get('quota'));
        $shared->setCache('rate', 'quota', ['count' => 2]);

        self::assertFalse($adapter->compareAndSet(
            'quota',
            ['count' => 1],
            ['count' => 3],
        ));
        self::assertSame(['count' => 2], $adapter->get('quota'));
        self::assertInstanceOf(CacheAdapterHealthInterface::class, $adapter);
        self::assertTrue($adapter->isAvailable());
    }

    public function testRemoteFailureMarksAdapterUnavailable(): void
    {
        $shared = new SharedCacheStateDouble();
        $shared->fail = true;
        $adapter = new WlsMemoryAdapter('rate_unavailable', [], $shared);

        self::assertNull($adapter->get('quota'));
        self::assertFalse($adapter->isAvailable());
    }

    public function testClearBumpsEpochSoPeerWorkerDropsLocalCache(): void
    {
        $shared = new SharedCacheStateDouble();
        $config = [
            'local_cache_size' => 10,
            'local_cache_memory_pressure_threshold' => 0.99,
        ];
        $writer = new WlsMemoryAdapter('acl_epoch', $config, $shared);
        $reader = new WlsMemoryAdapter('acl_epoch', $config, $shared);

        self::assertTrue($writer->set('acl_2_source', ['Weline_AppStore::index']));
        self::assertSame(['Weline_AppStore::index'], $reader->get('acl_2_source'));

        self::assertTrue($writer->clear());

        $synced = new \ReflectionProperty(WlsMemoryAdapter::class, 'epochSyncedRequestId');
        $synced->setAccessible(true);
        $synced->setValue($reader, null);

        self::assertNull($reader->get('acl_2_source'));
    }
}

final class SharedCacheStateDouble implements SharedCacheStateInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $values = [];
    public bool $fail = false;

    public function get(string $namespace, string $key): mixed
    {
        return $this->getCache($namespace, $key);
    }

    public function set(string $namespace, string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->setCache($namespace, $key, $value, $ttl);
    }

    public function delete(string $namespace, string $key): bool
    {
        return $this->deleteCache($namespace, $key);
    }

    public function exists(string $namespace, string $key): bool
    {
        return $this->hasCache($namespace, $key);
    }

    public function incr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        $value = (int)$this->getCache($namespace, $key) + $delta;
        $this->setCache($namespace, $key, $value, $ttl);

        return $value;
    }

    public function cas(
        string $namespace,
        string $key,
        mixed $expected,
        mixed $value,
        int $ttl = 0,
    ): bool {
        return $this->compareAndSetCache($namespace, $key, $expected, $value, $ttl);
    }

    public function clearNamespace(string $namespace): bool
    {
        return $this->clearCache($namespace);
    }

    public function getCache(string $poolIdentity, string $key): mixed
    {
        $this->assertAvailable();

        return $this->values[$poolIdentity][$key] ?? null;
    }

    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool
    {
        $this->assertAvailable();
        $this->values[$poolIdentity][$key] = $value;

        return true;
    }

    public function deleteCache(string $poolIdentity, string $key): bool
    {
        $this->assertAvailable();
        unset($this->values[$poolIdentity][$key]);

        return true;
    }

    public function hasCache(string $poolIdentity, string $key): bool
    {
        $this->assertAvailable();

        return \array_key_exists($key, $this->values[$poolIdentity] ?? []);
    }

    public function clearCache(string $poolIdentity): bool
    {
        $this->assertAvailable();
        unset($this->values[$poolIdentity]);

        return true;
    }

    public function compareAndSetCache(
        string $poolIdentity,
        string $key,
        mixed $expected,
        mixed $value,
        int $ttl = 0,
    ): bool {
        $this->assertAvailable();
        $current = $this->values[$poolIdentity][$key] ?? null;
        if ($current !== $expected) {
            return false;
        }
        if ($value === null) {
            unset($this->values[$poolIdentity][$key]);
        } else {
            $this->values[$poolIdentity][$key] = $value;
        }

        return true;
    }

    public function disconnect(): void
    {
    }

    private function assertAvailable(): void
    {
        if ($this->fail) {
            throw new \RuntimeException('shared_cache_unavailable');
        }
    }
}
