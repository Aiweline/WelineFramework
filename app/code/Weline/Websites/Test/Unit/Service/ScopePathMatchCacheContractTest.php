<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Data\ScopeData;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Service\ScopePathMatchCache;
use Weline\Websites\Service\Value\ScopePathMatchHit;
use Weline\Websites\Service\Value\ScopePathMatchKey;

final class ScopePathMatchCacheContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Context::leave();
        Context::enter(new Context());
        RequestContext::init();
        ScopeData::resetRequestState();
        ScopePathMatchCache::clearProcessCache();
    }

    protected function tearDown(): void
    {
        ScopeData::resetRequestState();
        ScopePathMatchCache::clearProcessCache();
        RequestContext::resetWelineVars();
        Context::leave();
        parent::tearDown();
    }

    public function testMatchKeyIgnoresQueryAndUsesCanonicalPath(): void
    {
        $key = ScopePathMatchKey::fromTrustedUrl('https://shop.example.test:8443/outlet/catalog?x=1#frag');
        self::assertSame('https', $key->scheme);
        self::assertSame('shop.example.test', $key->host);
        self::assertSame(8443, $key->port);
        self::assertSame('/outlet/catalog', $key->path);
        self::assertNotSame(
            $key->cacheIdentity('v1'),
            $key->cacheIdentity('v2'),
        );
    }

    public function testHitRoundTripPreservesStoreAndChannelSummaries(): void
    {
        $store = new StoreSummary(
            3,
            1,
            'outlet',
            'Outlet',
            Store::MODE_NORMAL,
            false,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
            'https://shop.example.test/outlet',
        );
        $channel = new SalesChannelSummary(
            9,
            1,
            3,
            SalesChannel::CODE_DEFAULT,
            'Default',
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            true,
        );
        $hit = ScopePathMatchHit::fromResolved(1, 'shop', $store, $channel, '/catalog');
        $restored = ScopePathMatchHit::fromArray($hit->toArray());
        self::assertInstanceOf(ScopePathMatchHit::class, $restored);
        self::assertTrue($restored->matchesWebsite(1, 'shop'));
        self::assertSame(3, $restored->storeSummary()?->id);
        self::assertSame(9, $restored->channelSummary()?->id);
        self::assertSame('/catalog', $restored->routePath);
    }

    public function testScopeDataInstallAndMatchHelpers(): void
    {
        $store = new StoreSummary(
            0,
            0,
            Store::CODE_DEFAULT,
            'Default',
            Store::MODE_NORMAL,
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
            null,
        );
        $channel = new SalesChannelSummary(
            0,
            0,
            0,
            SalesChannel::CODE_DEFAULT,
            'Default',
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            true,
        );
        ScopeData::install($store, $channel, '/');
        self::assertTrue(ScopeData::matchesStoreId(0));
        self::assertTrue(ScopeData::matchesChannelId(0));
        self::assertTrue(ScopeData::matchesStoreCode(0, 'default'));
        self::assertTrue(ScopeData::matchesChannelCode(0, 'default'));
        self::assertSame('/', ScopeData::getRoutePath());
        ScopeData::resetRequestState();
        self::assertNull(ScopeData::getStore());
    }

    public function testPathMatchCacheReadWriteUsesInjectedPool(): void
    {
        $pool = new ScopePathMatchCachePoolStub();
        $cache = new class ($pool) extends ScopePathMatchCache {
            public function __construct(CachePoolInterface $pool)
            {
                parent::__construct($pool);
            }

            public function registryVersion(): string
            {
                return 'test-registry-v1';
            }
        };
        $key = new ScopePathMatchKey('https', 'shop.example.test', 443, '/outlet');
        $store = new StoreSummary(
            5,
            2,
            'outlet',
            'Outlet',
            Store::MODE_NORMAL,
            false,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
            'https://shop.example.test/outlet',
        );
        $channel = new SalesChannelSummary(
            7,
            2,
            5,
            SalesChannel::CODE_DEFAULT,
            'Default',
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            true,
        );
        $hit = ScopePathMatchHit::fromResolved(2, 'brand', $store, $channel, '/');
        $cache->writeMatch($key, $hit);

        $read = $cache->readMatch($key);
        self::assertInstanceOf(ScopePathMatchHit::class, $read);
        self::assertSame(5, $read->storeId);
        self::assertSame(5, $cache->readStoreSnapshot(5)?->id);
        self::assertSame(7, $cache->readChannelSnapshot(7)?->id);
        self::assertSame(0, $pool->gets, 'A same-worker path hit must not round-trip to WLS.');

        $secondReader = new class ($pool) extends ScopePathMatchCache {
            public function __construct(CachePoolInterface $pool)
            {
                parent::__construct($pool);
            }

            public function registryVersion(): string
            {
                return 'test-registry-v1';
            }
        };
        self::assertInstanceOf(ScopePathMatchHit::class, $secondReader->readMatch($key));
        self::assertSame(5, $secondReader->readStoreSnapshot(5)?->id);
        self::assertSame(7, $secondReader->readChannelSnapshot(7)?->id);
        self::assertSame(0, $pool->gets, 'A new RequestContext in the same worker must reuse L1.');
    }
}

final class ScopePathMatchCachePoolStub implements CachePoolInterface
{
    /** @var array<string, mixed> */
    private array $store = [];
    public int $gets = 0;
    public int $sets = 0;

    public function get(string $key): mixed
    {
        $this->gets++;
        return $this->store[$key] ?? false;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->sets++;
        $this->store[$key] = $value;
        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->store);
    }

    public function getIdentity(): string
    {
        return 'scope_path_match_stub';
    }

    public function getTip(): string
    {
        return 'stub';
    }

    public function isPermanent(): bool
    {
        return false;
    }

    public function getMultiple(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get((string)$key);
        }
        return $out;
    }

    public function setMultiple(array $values, int $ttl = 0): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string)$key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(array $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string)$key);
        }
        return true;
    }

    public function deleteCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->delete($key);
    }

    public function hasCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->has($key);
    }

    public function getCustom(
        string $key,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): mixed {
        return $this->get($key);
    }

    public function setCustom(
        string $key,
        mixed $value,
        int $ttl = 0,
        bool $website = false,
        bool $lang = false,
        bool $currency = false
    ): bool {
        return $this->set($key, $value, $ttl);
    }

    public function getStats(): array
    {
        return [
            'identity' => $this->getIdentity(),
            'hits' => 0,
            'misses' => 0,
            'hit_ratio' => 0.0,
            'permanent' => false,
        ];
    }
}
