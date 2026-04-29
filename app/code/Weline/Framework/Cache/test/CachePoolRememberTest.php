<?php

declare(strict_types=1);

/**
 * CachePool::remember() 单测：覆盖防穿透 + 防击穿场景。
 */

namespace Weline\Framework\Cache\test;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\HotKeyAwareInterface;
use Weline\Framework\Cache\Contract\RememberOptions;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class CachePoolRememberTest extends TestCase
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

    public function testRememberCallsCallbackOnceAndCachesResult(): void
    {
        $adapter = new CachePoolRememberSpyAdapter();
        $pool = new CachePool('remember_pool', $adapter, '', false, 600, true, 0.0);
        $pool->setSingleFlight(new RememberAlwaysFreeSingleFlight());

        $calls = 0;
        $callback = static function () use (&$calls): string {
            $calls++;
            return 'fresh-value';
        };

        $first = $pool->remember('order_id_1', 600, $callback);
        $second = $pool->remember('order_id_1', 600, $callback);

        $this->assertSame('fresh-value', $first);
        $this->assertSame('fresh-value', $second);
        $this->assertSame(1, $calls);
        $this->assertSame(1, $adapter->setCalls);
    }

    public function testRememberCachesNullSentinelOnNullCallbackResult(): void
    {
        $adapter = new CachePoolRememberSpyAdapter();
        $pool = new CachePool('remember_null_pool', $adapter, '', false, 1800, true, 0.0);
        $pool->setSingleFlight(new RememberAlwaysFreeSingleFlight());

        $calls = 0;
        $callback = static function () use (&$calls) {
            $calls++;
            return null;
        };

        $options = new RememberOptions(nullTtl: 30);

        $first = $pool->remember('missing_key', 1800, $callback, $options);
        $second = $pool->remember('missing_key', 1800, $callback, $options);
        $third = $pool->remember('missing_key', 1800, $callback, $options);

        $this->assertNull($first);
        $this->assertNull($second);
        $this->assertNull($third);
        $this->assertSame(1, $calls, 'Null sentinel should prevent repeated callback execution within TTL');

        $this->assertSame(1, $adapter->setCalls);
        $this->assertSame(30, $adapter->lastTtl, 'Null sentinel should use the short null TTL');
    }

    public function testRememberFallsBackWhenSingleFlightCannotAcquireAndCachePopulated(): void
    {
        $adapter = new CachePoolRememberSpyAdapter();
        $pool = new CachePool('remember_lock_pool', $adapter, '', false, 1800, true, 0.0);

        $coordinator = new RememberDeniedSingleFlight();
        $pool->setSingleFlight($coordinator);

        $callbackCalls = 0;
        $callback = function () use (&$callbackCalls, $adapter, $pool) {
            $callbackCalls++;
            $adapter->seedAfterCallback($pool, 'pre_cached_key', 'recovered-by-other-worker');
            return 'callback-fallback-value';
        };

        $first = $pool->remember('pre_cached_key', 1800, $callback);

        $this->assertSame('callback-fallback-value', $first, 'When lock denied and cache empty, fallback to callback');
        $this->assertSame(1, $callbackCalls);

        $adapter->seedAfterCallback($pool, 'second_key', 'value-from-leader');
        $second = $pool->remember('second_key', 1800, $callback);

        $this->assertSame('value-from-leader', $second);
        $this->assertSame(1, $callbackCalls);
    }

    public function testRememberDisabledPoolBypassesCacheAndAlwaysCallsCallback(): void
    {
        $adapter = new CachePoolRememberSpyAdapter();
        $pool = new CachePool('remember_disabled', $adapter, '', false, 1800, false, 0.0);

        $calls = 0;
        $callback = static function () use (&$calls): int {
            $calls++;
            return $calls;
        };

        $this->assertSame(1, $pool->remember('k', 1800, $callback));
        $this->assertSame(2, $pool->remember('k', 1800, $callback));
        $this->assertSame(0, $adapter->setCalls);
    }

    public function testRememberInvokesHotKeyHandlerOnHotKey(): void
    {
        $adapter = new CachePoolRememberSpyAdapter();
        $pool = new CachePool('remember_hot_pool', $adapter, '', false, 1800, true, 0.0);
        $pool->setSingleFlight(new RememberAlwaysFreeSingleFlight());

        $tracker = new RememberStubHotKeyTracker(threshold: 2);
        $pool->setHotKeyTracker($tracker);

        $hotEvents = [];
        $options = new RememberOptions(hotKeyHandler: static function (array $event) use (&$hotEvents): void {
            $hotEvents[] = $event;
        });

        $callback = static fn(): string => 'hot-value';

        $pool->remember('hot_key_1', 1800, $callback, $options);
        $pool->remember('hot_key_1', 1800, $callback, $options);
        $pool->remember('hot_key_1', 1800, $callback, $options);

        $this->assertNotEmpty($hotEvents, 'Hot key handler should fire after threshold reached');
        $this->assertSame('hot_key_1', $hotEvents[0]['key']);
        $this->assertSame('remember_hot_pool', $hotEvents[0]['identity']);
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

final class CachePoolRememberSpyAdapter implements CacheAdapterInterface
{
    public int $setCalls = 0;
    public int $lastTtl = 0;

    /** @var array<string, mixed> */
    private array $storage = [];

    public function get(string $key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->setCalls++;
        $this->lastTtl = $ttl;
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

    /**
     * 测试辅助：模拟「另一个 worker 同时把缓存写入了」。
     */
    public function seedAfterCallback(CachePool $pool, string $key, mixed $value): void
    {
        $reflection = new ReflectionClass(CachePool::class);
        $method = $reflection->getMethod('buildKey');
        $method->setAccessible(true);
        $hashed = $method->invoke($pool, $key);
        $this->storage[$hashed] = $value;
    }
}

final class RememberAlwaysFreeSingleFlight implements SingleFlightInterface
{
    public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string
    {
        return 'fake-token';
    }

    public function release(string $key, string $token): void
    {
    }
}

final class RememberDeniedSingleFlight implements SingleFlightInterface
{
    public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string
    {
        return null;
    }

    public function release(string $key, string $token): void
    {
    }
}

final class RememberStubHotKeyTracker implements HotKeyAwareInterface
{
    /** @var array<string, int> */
    private array $counts = [];

    public function __construct(private int $threshold = 5)
    {
    }

    public function touch(string $identity, string $key): void
    {
        $bucket = $identity . '|' . $key;
        $this->counts[$bucket] = ($this->counts[$bucket] ?? 0) + 1;
    }

    public function isHot(string $identity, string $key): bool
    {
        return ($this->counts[$identity . '|' . $key] ?? 0) >= $this->threshold;
    }

    public function getHits(string $identity, string $key): int
    {
        return $this->counts[$identity . '|' . $key] ?? 0;
    }

    public function listHotKeys(int $limit = 50): array
    {
        $rows = [];
        foreach ($this->counts as $bucket => $hits) {
            if ($hits < $this->threshold) {
                continue;
            }
            [$identity, $key] = \explode('|', $bucket, 2);
            $rows[] = ['identity' => $identity, 'key' => $key, 'hits' => $hits];
        }
        return $rows;
    }
}
