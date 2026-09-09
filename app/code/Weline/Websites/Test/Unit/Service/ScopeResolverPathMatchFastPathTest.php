<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Data\ScopeData;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Service\ScopePathMatchCache;
use Weline\Websites\Service\ScopeResolver;
use Weline\Websites\Service\Value\ScopePathMatchHit;
use Weline\Websites\Service\Value\ScopePathMatchKey;

final class ScopeResolverPathMatchFastPathTest extends TestCase
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

    public function testResolveUsesL2HitAndPastesL3WithoutCatalogScan(): void
    {
        $store = new StoreSummary(
            4,
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
            8,
            1,
            4,
            SalesChannel::CODE_DEFAULT,
            'Default',
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            true,
        );
        $pool = new ScopePathMatchCachePoolStub();
        $cache = new class ($pool) extends ScopePathMatchCache {
            public function registryVersion(): string
            {
                return 'fast-v1';
            }
        };
        $url = 'https://shop.example.test/outlet/catalog';
        $key = ScopePathMatchKey::fromTrustedUrl($url);
        $cache->writeMatch($key, ScopePathMatchHit::fromResolved(1, 'shop', $store, $channel, '/catalog'));
        self::assertSame(3, $pool->sets);

        $storeCatalog = new class implements StoreCatalogInterface {
            public int $byWebsiteCalls = 0;
            public function byWebsite(int $websiteId): array { $this->byWebsiteCalls++; return []; }
            public function byCode(int $websiteId, string $storeCode): ?StoreSummary { return null; }
            public function byId(int $storeId): ?StoreSummary { return null; }
            public function defaultStore(int $websiteId): ?StoreSummary { return null; }
            public function all(): array { return []; }
        };
        $channelCatalog = new class implements SalesChannelCatalogInterface {
            public int $defaultCalls = 0;
            public function byStore(int $storeId): array { return []; }
            public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary { return null; }
            public function byId(int $channelId): ?SalesChannelSummary { return null; }
            public function defaultChannel(int $storeId): ?SalesChannelSummary
            {
                $this->defaultCalls++;
                return null;
            }
            public function defaultChannelForStore(StoreSummary $store): ?SalesChannelSummary
            {
                $this->defaultCalls++;
                return null;
            }
        };

        $resolver = new ScopeResolver($storeCatalog, $channelCatalog, $cache);
        $scope = $resolver->resolve(1, 'shop', $url);

        self::assertSame('/catalog', $scope->routePath);
        self::assertSame('shop', $scope->identity->websiteCode);
        self::assertSame('outlet', $scope->identity->storeCode);
        self::assertSame('default', $scope->identity->channelCode);
        self::assertSame(4, RequestContext::getWelineStoreId());
        self::assertSame(8, RequestContext::getWelineChannelId());
        self::assertSame('/catalog', RequestContext::getStorefrontRoutePath());
        self::assertSame(0, $storeCatalog->byWebsiteCalls);
        self::assertSame(0, $channelCatalog->defaultCalls);
        self::assertSame(3, $pool->sets, 'An L2/L1 hit must not rewrite the shared path snapshot.');
        self::assertTrue(ScopeData::matchesStoreId(4));
        self::assertTrue(ScopeData::matchesChannelId(8));
        self::assertSame('outlet', ScopeData::getStore()?->code);
    }

    public function testResolvePassesResolvedStoreToChannelCatalogWithoutReloadingStore(): void
    {
        $store = new StoreSummary(
            14,
            1,
            'outlet-fast',
            'Outlet Fast',
            Store::MODE_NORMAL,
            false,
            true,
            Store::LIFECYCLE_ACTIVE,
            null,
            'https://scope-no-store-reload.example.test/outlet-fast',
        );
        $channel = new SalesChannelSummary(
            18,
            1,
            14,
            SalesChannel::CODE_DEFAULT,
            'Default',
            true,
            true,
            Store::LIFECYCLE_ACTIVE,
            true,
        );

        $storeCatalog = new class ($store) implements StoreCatalogInterface {
            public int $byIdCalls = 0;

            public function __construct(private readonly StoreSummary $store)
            {
            }

            public function byWebsite(int $websiteId): array
            {
                return $websiteId === $this->store->websiteId ? [$this->store] : [];
            }

            public function byCode(int $websiteId, string $storeCode): ?StoreSummary
            {
                return $websiteId === $this->store->websiteId && $storeCode === $this->store->code
                    ? $this->store
                    : null;
            }

            public function byId(int $storeId): ?StoreSummary
            {
                $this->byIdCalls++;
                return $storeId === $this->store->id ? $this->store : null;
            }

            public function defaultStore(int $websiteId): ?StoreSummary
            {
                return null;
            }

            public function all(): array
            {
                return [$this->store];
            }
        };
        $channelCatalog = new class ($channel) implements SalesChannelCatalogInterface {
            public int $legacyDefaultCalls = 0;
            public int $summaryDefaultCalls = 0;

            public function __construct(private readonly SalesChannelSummary $channel)
            {
            }

            public function byStore(int $storeId): array
            {
                return [];
            }

            public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary
            {
                return null;
            }

            public function byId(int $channelId): ?SalesChannelSummary
            {
                return null;
            }

            public function defaultChannel(int $storeId): ?SalesChannelSummary
            {
                $this->legacyDefaultCalls++;
                return null;
            }

            public function defaultChannelForStore(StoreSummary $store): ?SalesChannelSummary
            {
                $this->summaryDefaultCalls++;
                return $store->id === $this->channel->storeId ? $this->channel : null;
            }
        };

        $pathCache = new class extends ScopePathMatchCache {
            public function readMatch(ScopePathMatchKey $key): ?ScopePathMatchHit
            {
                return null;
            }

            public function writeMatch(ScopePathMatchKey $key, ScopePathMatchHit $hit): void
            {
            }
        };
        $resolver = new ScopeResolver($storeCatalog, $channelCatalog, $pathCache);
        $scope = $resolver->resolve(
            1,
            'shop',
            'https://scope-no-store-reload.example.test/outlet-fast/catalog',
        );

        self::assertSame('outlet-fast', $scope->identity->storeCode);
        self::assertSame(1, $channelCatalog->summaryDefaultCalls);
        self::assertSame(0, $channelCatalog->legacyDefaultCalls);
        self::assertSame(0, $storeCatalog->byIdCalls);
    }
}
