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

    public function testPeekPolicyReturnsWarmPayloadWithoutRunningBuilder(): void
    {
        $adapter = new InMemoryAdapter();
        $pool = new CachePool('unit_scope_hot_peek', $adapter, jitterRatio: 0.0);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('pool')->willReturn($pool);
        $cacheManager->method('registerPolicy')->willReturnArgument(0);
        $service = new StorefrontScopeHotCache($cacheManager, null, new ImmediateSingleFlight());
        $policy = new \Weline\Framework\Cache\CachePolicy(
            resource: 'unit.peek',
            pool: 'unit_scope_hot_peek',
            scope: 'global',
            freshTtlSeconds: 60,
            staleTtlSeconds: 120,
        );

        self::assertNull($service->peekPolicy($policy, 'demo.key'));
        $builds = 0;
        self::assertSame('warm', $service->rememberPolicy($policy, 'demo.key', static function () use (&$builds): string {
            $builds++;

            return 'warm';
        }));
        self::assertSame(1, $builds);
        self::assertSame('warm', $service->peekPolicy($policy, 'demo.key'));
        StorefrontScopeHotCache::resetProcessCache();
        self::assertSame('warm', $service->peekPolicy($policy, 'demo.key'));
        self::assertSame(1, $builds);
        // peek(miss) + remember(shared_read+recheck) + peek(L2 after L1 reset)
        self::assertSame(['read', 'read', 'read', 'read'], $adapter->events);
    }

    public function testForgetPolicyAcrossGenerationsPurgesWarmSharedEntry(): void
    {
        $service = $this->policyService('unit_scope_hot_forget', $adapter);
        $policy = new \Weline\Framework\Cache\CachePolicy(
            resource: 'unit.forget',
            pool: 'unit_scope_hot_forget',
            scope: 'global',
            freshTtlSeconds: 60,
            staleTtlSeconds: 120,
        );

        self::assertSame('warm', $service->rememberPolicy($policy, 'demo.key', static fn(): string => 'warm'));
        StorefrontScopeHotCache::resetProcessCache();
        self::assertSame('warm', $service->peekPolicy($policy, 'demo.key'));

        self::assertGreaterThanOrEqual(1, $service->forgetPolicyAcrossGenerations($policy, 'demo.key'));
        StorefrontScopeHotCache::resetProcessCache();
        self::assertNull($service->peekPolicy($policy, 'demo.key'));
    }

    public function testForgetPolicyAcrossGenerationsResolvesDependencyVector(): void
    {
        $generation = $this->createMock(\Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class);
        $generation->method('fingerprint')->willReturn('fp-test-1');
        $service = $this->policyService('unit_scope_hot_forget_dep', $adapter, $generation);
        $policy = new \Weline\Framework\Cache\CachePolicy(
            resource: 'unit.forget.dep',
            pool: 'unit_scope_hot_forget_dep',
            scope: 'global',
            dependencies: ['theme'],
            freshTtlSeconds: 60,
            staleTtlSeconds: 120,
        );

        self::assertSame('warm', $service->rememberPolicy($policy, 'demo.key', static fn(): string => 'warm'));
        StorefrontScopeHotCache::resetProcessCache();
        self::assertSame('warm', $service->peekPolicy($policy, 'demo.key'));

        self::assertGreaterThanOrEqual(1, $service->forgetPolicyAcrossGenerations($policy, 'demo.key'));
        StorefrontScopeHotCache::resetProcessCache();
        self::assertNull($service->peekPolicy($policy, 'demo.key'));
    }

    public function testChromePoolByteBudgetCapsProcessL1(): void
    {
        $adapter = new InMemoryAdapter();
        $pool = new CachePool('weline_theme_storefront_chrome', $adapter, jitterRatio: 0.0);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('pool')->willReturn($pool);
        $service = new StorefrontScopeHotCache($cacheManager);

        $chunk = \str_repeat('c', 180_000);
        for ($i = 0; $i < 10; $i++) {
            $service->remember(
                'weline_theme_storefront_chrome',
                'chrome.budget.' . $i,
                60,
                static fn(): string => $chunk,
                [],
            );
        }

        $rp = new \ReflectionProperty(StorefrontScopeHotCache::class, 'processCache');
        $rp->setAccessible(true);
        /** @var array<string, array{payload:mixed}> $cache */
        $cache = $rp->getValue();
        $chromeBytes = 0;
        $chromeEntries = 0;
        foreach ($cache as $key => $entry) {
            if (!\str_contains((string)$key, 'chrome')) {
                continue;
            }
            ++$chromeEntries;
            $payload = $entry['payload'] ?? null;
            $chromeBytes += (\is_string($payload) ? \strlen($payload) : 0) + 64;
        }

        self::assertGreaterThan(0, $chromeEntries);
        self::assertLessThanOrEqual(1_048_576, $chromeBytes);
        self::assertLessThan(10, $chromeEntries);
    }

    public function testTrimProcessCacheToBudgetEvictsOldest(): void
    {
        $adapter = new InMemoryAdapter();
        $pool = new CachePool('unit_trim_budget', $adapter, jitterRatio: 0.0);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('pool')->willReturn($pool);
        $service = new StorefrontScopeHotCache($cacheManager);

        $payload = \str_repeat('t', 50_000);
        for ($i = 0; $i < 6; $i++) {
            $service->remember('unit_trim_budget', 'trim.' . $i, 60, static fn(): string => $payload, []);
        }

        $freed = StorefrontScopeHotCache::trimProcessCacheToBudget(120_000);
        self::assertGreaterThan(0, $freed);

        $rp = new \ReflectionProperty(StorefrontScopeHotCache::class, 'processCache');
        $rp->setAccessible(true);
        $bytes = new \ReflectionProperty(StorefrontScopeHotCache::class, 'processCacheBytes');
        $bytes->setAccessible(true);
        self::assertLessThanOrEqual(120_000, (int)$bytes->getValue());
        self::assertNotSame([], $rp->getValue());
    }

    private function policyService(string $poolId, ?InMemoryAdapter &$adapter = null, mixed $generation = null): StorefrontScopeHotCache
    {
        $adapter = new InMemoryAdapter();
        $pool = new CachePool($poolId, $adapter, jitterRatio: 0.0);
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('pool')->willReturn($pool);
        $cacheManager->method('registerPolicy')->willReturnArgument(0);
        if ($generation === null) {
            $generation = $this->createMock(\Weline\Framework\Cache\Contract\NamespaceGenerationInterface::class);
            $generation->method('fingerprint')->willReturn('fp-test-1');
        }

        return new StorefrontScopeHotCache($cacheManager, $generation, new ImmediateSingleFlight());
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
