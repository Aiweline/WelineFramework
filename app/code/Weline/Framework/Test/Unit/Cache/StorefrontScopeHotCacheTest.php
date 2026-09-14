<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Cache;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Runtime\PostResponseTaskQueue;

final class StorefrontScopeHotCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        StorefrontScopeHotCache::resetProcessCache();
        while (PostResponseTaskQueue::pendingCount() > 0) {
            PostResponseTaskQueue::drain(100.0, 1000);
        }
        parent::tearDown();
    }

    public function testRememberBuildsOnceUntilPurged(): void
    {
        $adapter = new InMemoryAdapter();
        $pool = new CachePool('unit_scope_hot', $adapter, jitterRatio: 0.0);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('pool')->willReturn($pool);

        $service = new StorefrontScopeHotCache($cacheManager);
        $calls = 0;
        $builder = static function () use (&$calls): string {
            $calls++;

            return 'payload-' . $calls;
        };

        self::assertSame('payload-1', $service->remember('unit_scope_hot', 'demo.key', 60, $builder, []));
        self::assertSame('payload-1', $service->remember('unit_scope_hot', 'demo.key', 60, $builder, []));
        self::assertSame(1, $calls);

        $service->forget('unit_scope_hot', 'demo.key', []);
        self::assertSame('payload-2', $service->remember('unit_scope_hot', 'demo.key', 60, $builder, []));
        self::assertSame(2, $calls);
    }

    public function testPolicyWaitBudgetAcquiresBeforeSharedRead(): void
    {
        $adapter = new InMemoryAdapter();
        $pool = new CachePool('unit_scope_hot_policy', $adapter, jitterRatio: 0.0);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('pool')->willReturn($pool);
        $cacheManager->method('registerPolicy')->willReturnArgument(0);
        $flight = new ImmediateSingleFlight();
        $service = new StorefrontScopeHotCache($cacheManager, null, $flight);
        $policy = new \Weline\Framework\Cache\CachePolicy(
            resource: 'unit.heavy',
            pool: 'unit_scope_hot_policy',
            scope: 'global',
            singleFlightWaitMs: 250,
        );

        self::assertSame('payload', $service->rememberPolicy($policy, 'demo.key', static fn(): string => 'payload'));
        self::assertSame(250, $flight->lastTimeoutMs);
        self::assertSame(['acquire', 'release'], $flight->events);
        self::assertSame(['read'], $adapter->events);
    }

    public function testColdMissDoesNotWaitForAContendedSingleFlightLock(): void
    {
        $adapter = new InMemoryAdapter();
        $pool = new CachePool('unit_scope_hot_contended', $adapter, jitterRatio: 0.0);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('pool')->willReturn($pool);
        $flight = new ImmediateSingleFlight();
        $service = new StorefrontScopeHotCache($cacheManager, null, $flight);

        self::assertSame('fresh', $service->remember('unit_scope_hot_contended', 'demo.key', 60, static fn(): string => 'fresh', []));
        self::assertSame(0, $flight->lastTimeoutMs);
    }
}

final class InMemoryAdapter implements CacheAdapterInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var list<string> */
    public array $events = [];

    public function get(string $key): mixed
    {
        $this->events[] = 'read';
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->data[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->data[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->data = [];

        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }
}

final class ImmediateSingleFlight implements SingleFlightInterface
{
    public int $lastTimeoutMs = -1;

    /** @var list<string> */
    public array $events = [];

    public function acquire(string $key, int $timeoutMs = 1500, int $ttlSeconds = 30): ?string
    {
        unset($key, $ttlSeconds);
        $this->events[] = 'acquire';
        $this->lastTimeoutMs = $timeoutMs;

        return 'immediate-token';
    }

    public function release(string $key, string $token): void
    {
        $this->events[] = 'release';
        unset($key, $token);
    }
}
