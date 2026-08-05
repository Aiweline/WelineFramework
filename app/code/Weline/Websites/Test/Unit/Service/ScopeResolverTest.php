<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Api\Catalog\Data\SalesChannelSummary;
use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\SalesChannelCatalogInterface;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\SalesChannel;
use Weline\Websites\Model\Store;
use Weline\Websites\Service\Exception\ScopeResolutionException;
use Weline\Websites\Service\ScopeResolver;

final class ScopeResolverTest extends TestCase
{
    private ?Context $previousContext;

    /** @var array<string, mixed> */
    private array $previousServer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContext = Context::getCurrent();
        $this->previousServer = $_SERVER;
        Context::enter(new Context());
        RequestContext::resetWelineVars();
    }

    protected function tearDown(): void
    {
        RequestContext::resetWelineVars();
        $_SERVER = $this->previousServer;
        if ($this->previousContext instanceof Context) {
            Context::enter($this->previousContext);
        } else {
            Context::leave();
        }
        parent::tearDown();
    }

    public function testZeroWebsiteResolvesDefaultAndFreezesCompleteScope(): void
    {
        $defaultStore = $this->store(1, 0, Store::CODE_DEFAULT, isDefault: true);
        $defaultChannel = $this->channel(11, 0, 1, SalesChannel::CODE_DEFAULT);
        $resolver = $this->resolver([$defaultStore], [$defaultChannel]);

        $navigation = $resolver->resolve(
            0,
            'default',
            'https://example.test/catalog?ignored=1',
            [ScopeResolver::PARAM_STORE => 'default', ScopeResolver::PARAM_CHANNEL => 'default'],
        );
        $identity = $navigation->identity;

        self::assertSame('channel|0|default|default|default|normal|v1', $identity->canonicalKey());
        self::assertTrue($identity->equals(RequestContext::scopeIdentity() ?? ScopeIdentity::global()));
        self::assertSame(0, RequestContext::getWelineWebsiteId());
        self::assertSame('default', RequestContext::getWelineWebsiteCode());
        self::assertSame(1, RequestContext::getWelineStoreId());
        self::assertSame(11, RequestContext::getWelineChannelId());
        self::assertSame('default.default.default', ScopeContext::getScope());
        self::assertSame('/catalog', $navigation->routePath);
    }

    public function testZeroWebsiteRejectsNonDefaultCodeWithoutFreezingScope(): void
    {
        $resolver = $this->resolver([], []);

        $this->assertRejected(
            static fn() => $resolver->resolve(0, 'other', 'https://example.test/'),
            'website_context_invalid',
        );
    }

    public function testLongestStorePathWinsAcrossBareAndWwwAlias(): void
    {
        $defaultStore = $this->store(1, 7, Store::CODE_DEFAULT, isDefault: true);
        $shopStore = $this->store(2, 7, 'shop', url: 'https://example.test/shop');
        $saleStore = $this->store(3, 7, 'sale', url: 'https://example.test/shop/sale');
        $resolver = $this->resolver(
            [$defaultStore, $shopStore, $saleStore],
            [
                $this->channel(11, 7, 1, SalesChannel::CODE_DEFAULT),
                $this->channel(12, 7, 2, SalesChannel::CODE_DEFAULT),
                $this->channel(13, 7, 3, SalesChannel::CODE_DEFAULT),
            ],
        );

        $navigation = $resolver->resolve(
            7,
            'shop_site',
            'https://www.example.test/shop/sale/item?campaign=1',
            [ScopeResolver::PARAM_STORE => 'sale'],
        );
        $identity = $navigation->identity;

        self::assertSame('sale', $identity->storeCode);
        self::assertSame(3, RequestContext::getWelineStoreId());
        self::assertSame(13, RequestContext::getWelineChannelId());
        self::assertSame('/item', $navigation->routePath);
    }

    public function testPathPrefixCollisionFallsBackToWebsiteDefaultStore(): void
    {
        $defaultStore = $this->store(1, 7, Store::CODE_DEFAULT, isDefault: true);
        $shopStore = $this->store(2, 7, 'shop', url: 'https://example.test/shop');
        $resolver = $this->resolver(
            [$defaultStore, $shopStore],
            [
                $this->channel(11, 7, 1, SalesChannel::CODE_DEFAULT),
                $this->channel(12, 7, 2, SalesChannel::CODE_DEFAULT),
            ],
        );

        $navigation = $resolver->resolve(7, 'shop_site', 'https://example.test/shopper');
        $identity = $navigation->identity;

        self::assertSame(Store::CODE_DEFAULT, $identity->storeCode);
        self::assertSame(1, RequestContext::getWelineStoreId());
        self::assertSame('/shopper', $navigation->routePath);
    }

    public function testEquivalentPriorityStoreUrlsAreRejectedAsAmbiguous(): void
    {
        $defaultStore = $this->store(1, 7, Store::CODE_DEFAULT, isDefault: true);
        $first = $this->store(2, 7, 'first', url: 'https://example.test/shop');
        $second = $this->store(3, 7, 'second', url: 'https://www.example.test/shop');
        $resolver = $this->resolver([$defaultStore, $first, $second], []);

        $this->assertRejected(
            static fn() => $resolver->resolve(7, 'shop_site', 'https://example.test/shop/item'),
            'store_url_ambiguous',
        );
    }

    public function testDisabledTombstonedAndCrossWebsiteMatchesFailClosed(): void
    {
        $defaultStore = $this->store(1, 7, Store::CODE_DEFAULT, isDefault: true);
        $cases = [
            'disabled' => [
                $this->store(2, 7, 'disabled', enabled: false, url: 'https://example.test/store'),
                'store_disabled',
            ],
            'tombstoned' => [
                $this->store(
                    3,
                    7,
                    'tombstoned',
                    enabled: false,
                    lifecycleStatus: Store::LIFECYCLE_TOMBSTONE,
                    tombstonedAt: '2026-07-23 00:00:00',
                    url: 'https://example.test/store',
                ),
                'store_tombstoned',
            ],
            'cross_website' => [
                $this->store(4, 8, 'foreign', url: 'https://example.test/store'),
                'store_website_conflict',
            ],
        ];

        foreach ($cases as $label => [$candidate, $reason]) {
            RequestContext::resetWelineVars();
            $resolver = $this->resolver([$defaultStore, $candidate], []);
            $this->assertRejected(
                static fn() => $resolver->resolve(7, 'shop_site', 'https://example.test/store/item'),
                $reason,
                $label,
            );
        }
    }

    public function testExplicitStoreAndChannelConflictsCannotSelectScope(): void
    {
        $defaultStore = $this->store(1, 7, Store::CODE_DEFAULT, isDefault: true);
        $shopStore = $this->store(2, 7, 'shop', url: 'https://example.test/shop');
        $shopChannel = $this->channel(12, 7, 2, SalesChannel::CODE_DEFAULT);
        $alternateChannel = $this->channel(13, 7, 2, 'wholesale');
        $resolver = $this->resolver([$defaultStore, $shopStore], [$shopChannel, $alternateChannel]);
        $cases = [
            'unknown_store' => [[ScopeResolver::PARAM_STORE => 'unknown'], 'store_assertion_conflict'],
            'existing_other_store' => [[ScopeResolver::PARAM_STORE => Store::CODE_DEFAULT], 'store_assertion_conflict'],
            'unknown_channel' => [[ScopeResolver::PARAM_CHANNEL => 'unknown'], 'channel_assertion_conflict'],
            'existing_other_channel' => [[ScopeResolver::PARAM_CHANNEL => 'wholesale'], 'channel_assertion_conflict'],
            'non_scalar_store' => [[ScopeResolver::PARAM_STORE => ['shop']], 'store_assertion_conflict'],
        ];

        foreach ($cases as $label => [$params, $reason]) {
            RequestContext::resetWelineVars();
            $this->assertRejected(
                static fn() => $resolver->resolve(7, 'shop_site', 'https://example.test/shop/item', $params),
                $reason,
                $label,
            );
        }
    }

    public function testDefaultChannelMustBeEnabledEffectiveAndBelongToTheFrozenScope(): void
    {
        $defaultStore = $this->store(1, 7, Store::CODE_DEFAULT, isDefault: true);
        $shopStore = $this->store(2, 7, 'shop', url: 'https://example.test/shop');
        $cases = [
            'disabled' => $this->channel(12, 7, 2, SalesChannel::CODE_DEFAULT, enabled: false),
            'effective_disabled' => $this->channel(12, 7, 2, SalesChannel::CODE_DEFAULT, effectiveEnabled: false),
            'cross_website' => $this->channel(12, 8, 2, SalesChannel::CODE_DEFAULT),
            'cross_store' => $this->channel(12, 7, 3, SalesChannel::CODE_DEFAULT),
        ];

        foreach ($cases as $label => $channel) {
            RequestContext::resetWelineVars();
            $resolver = $this->resolver([$defaultStore, $shopStore], [$channel]);
            $this->assertRejected(
                static fn() => $resolver->resolve(7, 'shop_site', 'https://example.test/shop/item'),
                'default_channel_unavailable',
                $label,
            );
        }
    }

    /**
     * @param list<StoreSummary> $stores
     * @param list<SalesChannelSummary> $channels
     */
    private function resolver(array $stores, array $channels): ScopeResolver
    {
        return new ScopeResolver(
            new ScopeResolverStoreCatalogStub($stores),
            new ScopeResolverSalesChannelCatalogStub($channels),
        );
    }

    private function store(
        int $id,
        int $websiteId,
        string $code,
        bool $isDefault = false,
        bool $enabled = true,
        string $lifecycleStatus = Store::LIFECYCLE_ACTIVE,
        ?string $tombstonedAt = null,
        ?string $url = null,
        string $storeMode = Store::MODE_NORMAL,
    ): StoreSummary {
        return new StoreSummary(
            $id,
            $websiteId,
            $code,
            $code,
            $storeMode,
            $isDefault,
            $enabled,
            $lifecycleStatus,
            $tombstonedAt,
            $url,
        );
    }

    private function channel(
        int $id,
        int $websiteId,
        int $storeId,
        string $code,
        bool $enabled = true,
        bool $effectiveEnabled = true,
    ): SalesChannelSummary {
        return new SalesChannelSummary(
            $id,
            $websiteId,
            $storeId,
            $code,
            $code,
            $code === SalesChannel::CODE_DEFAULT,
            $enabled,
            Store::LIFECYCLE_ACTIVE,
            $effectiveEnabled,
        );
    }

    private function assertRejected(callable $operation, string $reason, string $label = ''): void
    {
        try {
            $operation();
            self::fail('Scope resolution should have been rejected: ' . $label);
        } catch (ScopeResolutionException $exception) {
            self::assertSame($reason, $exception->reason, $label);
        }

        self::assertNull(RequestContext::scopeIdentity(), $label);
        self::assertSame(0, RequestContext::getWelineStoreId(), $label);
        self::assertSame('', RequestContext::getWelineStoreCode(), $label);
        self::assertSame(0, RequestContext::getWelineChannelId(), $label);
        self::assertSame('', RequestContext::getWelineChannelCode(), $label);
    }
}

final class ScopeResolverStoreCatalogStub implements StoreCatalogInterface
{
    /** @param list<StoreSummary> $stores */
    public function __construct(private readonly array $stores)
    {
    }

    public function byWebsite(int $websiteId): array
    {
        return $this->stores;
    }

    public function byCode(int $websiteId, string $storeCode): ?StoreSummary
    {
        foreach ($this->stores as $store) {
            if ($store->websiteId === $websiteId && $store->code === $storeCode) {
                return $store;
            }
        }
        return null;
    }

    public function byId(int $storeId): ?StoreSummary
    {
        foreach ($this->stores as $store) {
            if ($store->id === $storeId) {
                return $store;
            }
        }
        return null;
    }

    public function defaultStore(int $websiteId): ?StoreSummary
    {
        foreach ($this->stores as $store) {
            if ($store->websiteId === $websiteId && $store->isDefault) {
                return $store;
            }
        }
        return null;
    }

    public function all(): array
    {
        return $this->stores;
    }
}

final class ScopeResolverSalesChannelCatalogStub implements SalesChannelCatalogInterface
{
    /** @param list<SalesChannelSummary> $channels */
    public function __construct(private readonly array $channels)
    {
    }

    public function byStore(int $storeId): array
    {
        return \array_values(\array_filter(
            $this->channels,
            static fn(SalesChannelSummary $channel): bool => $channel->storeId === $storeId,
        ));
    }

    public function byCode(int $storeId, string $channelCode): ?SalesChannelSummary
    {
        foreach ($this->channels as $channel) {
            if ($channel->storeId === $storeId && $channel->code === $channelCode) {
                return $channel;
            }
        }
        return null;
    }

    public function byId(int $channelId): ?SalesChannelSummary
    {
        foreach ($this->channels as $channel) {
            if ($channel->id === $channelId) {
                return $channel;
            }
        }
        return null;
    }

    public function defaultChannel(int $storeId): ?SalesChannelSummary
    {
        foreach ($this->channels as $channel) {
            if ($channel->storeId === $storeId && $channel->isDefault) {
                return $channel;
            }
        }
        return null;
    }
}
