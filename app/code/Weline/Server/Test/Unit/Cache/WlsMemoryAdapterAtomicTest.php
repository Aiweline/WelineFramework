<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CacheAdapterHealthInterface;
use Weline\Framework\Cache\Contract\SharedCacheStateInterface;
use Weline\Server\Cache\Adapter\WlsMemoryAdapter;

final class WlsMemoryAdapterAtomicTest extends TestCase
{
    protected function tearDown(): void
    {
        WlsMemoryAdapter::clearAllMemory();
        parent::tearDown();
    }

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

    public function testRecoverRemoteProbeAllowsWorkerPoolAfterOtherPoolFailure(): void
    {
        WlsMemoryAdapter::clearAllMemory();
        $shared = new SharedCacheStateDouble();
        $shared->fail = true;
        $theme = new WlsMemoryAdapter('theme_pool', [], $shared);
        $worker = new WlsMemoryAdapter('frontend_worker_credential', [], $shared);

        self::assertNull($theme->get('token'));
        self::assertFalse($theme->isAvailable());
        self::assertFalse($worker->isAvailable());

        $shared->fail = false;
        $worker->recoverRemoteProbe();

        self::assertTrue($worker->isAvailable());
        self::assertTrue($worker->set('worker_state.v1', ['ok' => 1]));
        self::assertSame(['ok' => 1], $worker->get('worker_state.v1'));
    }

    public function testRecoverRemoteProbeDropsLiveFacadeSoNextOpReconnects(): void
    {
        WlsMemoryAdapter::clearAllMemory();
        $shared = new SharedCacheStateDouble();
        $adapter = new WlsMemoryAdapter('reconnect_pool', [], null);
        $facade = new \ReflectionProperty(WlsMemoryAdapter::class, 'memoryFacade');
        $facade->setAccessible(true);
        $facade->setValue($adapter, $shared);

        $adapter->recoverRemoteProbe();
        self::assertNull($facade->getValue($adapter));
    }

    public function testSlowCacheMissKeepsTheRemoteAdapterAvailable(): void
    {
        WlsMemoryAdapter::clearAllMemory();
        $shared = new SlowSharedCacheStateDouble();
        $adapter = new WlsMemoryAdapter('slow_remote', [
            'remote_slow_threshold_ms' => 25,
        ], $shared);

        self::assertNull($adapter->get('first'));
        self::assertSame(1, $shared->cacheReads);

        // 慢响应仍是合法 miss，后续读取必须继续访问共享缓存。
        self::assertNull($adapter->get('second'));
        self::assertSame(2, $shared->cacheReads);
        self::assertTrue($adapter->isAvailable());
    }

    public function testSlowSuccessfulReadPreservesPayloadAndOtherPools(): void
    {
        foreach ([null, false, ['present' => true]] as $payload) {
            WlsMemoryAdapter::clearAllMemory();
            $shared = $this->createMock(SharedCacheStateInterface::class);
            $shared->expects(self::exactly(2))->method('getCache')->willReturnCallback(
                static function (string $pool, string $key) use ($payload): mixed {
                    if ($pool === 'slow_success') {
                        usleep(35_000);
                        return $payload;
                    }
                    return ['peer' => 'present'];
                },
            );
            $shared->expects(self::once())->method('setCache')->with('slow_peer', 'false_value', false, 0)->willReturn(true);
            $config = ['remote_slow_threshold_ms' => 25, 'local_cache_size' => 0];
            $first = new WlsMemoryAdapter('slow_success', $config, $shared);
            $peer = new WlsMemoryAdapter('slow_peer', $config, $shared);

            self::assertSame($payload, $first->get('value'));
            self::assertTrue($first->isAvailable());
            self::assertSame(['peer' => 'present'], $peer->get('value'));
            self::assertTrue($peer->set('false_value', false));
        }
    }

    public function testSlowCasConflictDoesNotMarkOtherPoolsUnavailable(): void
    {
        $shared = $this->createMock(SharedCacheStateInterface::class);
        $shared->expects(self::once())->method('compareAndSetCache')->willReturnCallback(static function (): bool {
            usleep(35_000);
            return false;
        });
        $shared->expects(self::once())->method('getCache')->willReturn('present');
        $config = ['remote_slow_threshold_ms' => 25, 'local_cache_size' => 0];
        $first = new WlsMemoryAdapter('slow_conflict', $config, $shared);
        $peer = new WlsMemoryAdapter('conflict_peer', $config, $shared);

        self::assertFalse($first->compareAndSet('quota', 1, 2));
        self::assertTrue($first->isAvailable());
        self::assertSame('present', $peer->get('value'));
    }

    public function testMaintenanceBulkClearCanRetryAfterSlowPoolClear(): void
    {
        WlsMemoryAdapter::clearAllMemory();
        $shared = new SlowClearSharedCacheStateDouble();
        $config = ['remote_slow_threshold_ms' => 25];
        $firstPool = new WlsMemoryAdapter('bulk_clear_first', $config, $shared);
        $secondPool = new WlsMemoryAdapter('bulk_clear_second', $config, $shared);

        // 慢清理成功后，下一缓存池仍应正常执行维护清理。
        self::assertTrue($firstPool->clear());
        self::assertTrue($secondPool->clear());
        self::assertSame(2, $shared->cacheClears);
    }

    public function testEmptyLocalCacheSkipsEpochProbeBeforeFirstRemoteRead(): void
    {
        $shared = new CountingEpochStateDouble();
        $adapter = new WlsMemoryAdapter('empty_epoch_probe', [], $shared);

        self::assertNull($adapter->get('missing'));
        self::assertSame(0, $shared->epochReads);
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

    public function testLocalMemoryPressureBypassesOnlyL1AndPreservesSharedState(): void
    {
        $shared = new SharedCacheStateDouble();
        $config = [
            'local_cache_size' => 10,
            'local_cache_memory_pressure_threshold' => 0.0001,
        ];
        $writer = new WlsMemoryAdapter('preview_token_pressure', $config, $shared);
        $reader = new WlsMemoryAdapter('preview_token_pressure', $config, $shared);

        self::assertTrue($writer->set('token', ['page_id' => 42]));
        self::assertSame(0, $writer->getMemoryItemCount());
        self::assertSame(['page_id' => 42], $reader->get('token'));
        self::assertSame(0, $reader->getMemoryItemCount());
        self::assertTrue($reader->isAvailable());

        self::assertTrue($reader->compareAndSet(
            'token',
            ['page_id' => 42],
            ['page_id' => 43],
        ));
        self::assertSame(['page_id' => 43], $writer->get('token'));
    }
}

class SharedCacheStateDouble implements SharedCacheStateInterface
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

final class SlowSharedCacheStateDouble extends SharedCacheStateDouble
{
    public int $cacheReads = 0;

    public function get(string $namespace, string $key): mixed
    {
        return 0;
    }

    public function getCache(string $poolIdentity, string $key): mixed
    {
        ++$this->cacheReads;
        usleep(50_000);

        return null;
    }
}

final class SlowClearSharedCacheStateDouble extends SharedCacheStateDouble
{
    public int $cacheClears = 0;

    public function clearCache(string $poolIdentity): bool
    {
        ++$this->cacheClears;
        usleep(50_000);

        return parent::clearCache($poolIdentity);
    }
}

final class CountingEpochStateDouble extends SharedCacheStateDouble
{
    public int $epochReads = 0;

    public function get(string $namespace, string $key): mixed
    {
        ++$this->epochReads;

        return parent::get($namespace, $key);
    }
}
