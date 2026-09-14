<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Runtime\StorefrontPageContext;
use Weline\Product\Repository\ProductRepository;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontProductMediaUrlResolver;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

final class StorefrontProductWidgetCatalogTest extends TestCase
{
    private ?Context $previousContext;
    private array $cacheReads = [];

    protected function setUp(): void
    {
        $this->previousContext = Context::getCurrent();
        Context::enter(new Context(['input' => ['uri' => '/en_US/products', 'query' => []]]));
        RequestContext::setWelineUserLang('en_US');
        RequestContext::setWelineUserCurrency('USD');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            ScopeIdentity::channel(0, 'default', 'fixture', 'web', 'normal'), 'en_US', 'USD',
            hash('sha256', 'namespaces'), hash('sha256', 'context'), true,
        ));
        StorefrontScopeHotCache::resetProcessCache();
    }

    protected function tearDown(): void
    {
        Context::leave();
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
        StorefrontScopeHotCache::resetProcessCache();
    }

    public function testListingCandidatesAreSelectedBeforeOnlyTheirMediaIsHydrated(): void
    {
        Context::getCurrent()->set('input.query', ['af_hanfu_chao_dai' => 'Ming Style']);
        $offers = [];
        foreach (range(1, 25) as $id) {
            $offers[] = array_replace($this->offer($id, in_array($id, [3, 7, 25], true)), ['image' => '']);
        }
        // The controller publishes this before filters/pagination. A recommendation
        // can therefore include product 24 even when the current filter excludes it.
        StorefrontPageContext::setListingOffers($offers);
        $selectedIds = [7, 3, 24];
        [$widget, $mediaKey] = $this->widget($selectedIds, [$this->offer(999)]);
        $cards = $widget->cards(3);

        self::assertSame([$mediaKey], $this->cacheReads, 'Existing candidates must not trigger another full/summary catalog read.');
        self::assertSame($selectedIds, array_column($cards, 'product_id'), 'Keep first-24 window, descending IDs, Hanfu priority and fallback.');
        self::assertSame([0, 1, 2], array_column($cards, 'card_index'));
        self::assertSame([0.07, 0.03, 0.24], array_column($cards, 'price'));
        self::assertSame(array_map(static fn(int $id): string => 'https://cdn.example.test/' . $id . '.jpg', $selectedIds), array_column($cards, 'image'));
        self::assertSame($offers, StorefrontPageContext::listingOffers(), 'Hydration must not overwrite the unfiltered context.');
    }

    public function testResolvedEmptyListingDoesNotFallBackToAnotherCatalog(): void
    {
        Context::getCurrent()->set('input.query', ['af_hanfu_chao_dai' => 'Ming Style']);
        StorefrontPageContext::setListingOffers([]);
        [$widget] = $this->widget([], [$this->offer(999)]);
        self::assertSame([], $widget->cards(3));
        self::assertSame([], $this->cacheReads);
    }

    public function testHomepageAndProductPageWithoutListingContextKeepExistingFallbacks(): void
    {
        $fallback = [$this->offer(2), $this->offer(1, true)];
        $cacheContext = StorefrontCacheKeyContext::current();
        foreach ([
            ['/', [], false],
            ['/product/182', [], false],
            ['/products', ['af_hanfu_chao_dai' => 'Ming Style'], true],
        ] as [$uri, $query, $full]) {
            Context::enter(new Context(['input' => ['uri' => $uri, 'query' => $query]]));
            RequestContext::setWelineUserLang('en_US');
            RequestContext::setWelineUserCurrency('USD');
            StorefrontCacheKeyContext::install($cacheContext);
            StorefrontPageContext::clear();
            RequestContext::remove('product.catalog.full_rows.request');
            StorefrontScopeHotCache::resetProcessCache();
            $this->cacheReads = [];
            [$widget, , $fullKey, $summaryKey] = $this->widget([], $fallback);
            $cards = $widget->cards(3);
            self::assertSame([$full ? $fullKey : $summaryKey], $this->cacheReads, $uri);
            self::assertSame([1, 2], array_column($cards, 'product_id'));
            self::assertSame(['https://cdn.example.test/fallback-1.jpg', 'https://cdn.example.test/fallback-2.jpg'], array_column($cards, 'image'));
        }
    }

    /** @return array{StorefrontProductWidgetCatalog,string,string,string} */
    private function widget(array $mediaIds, array $fallbackOffers): array
    {
        $coordinator = (new \ReflectionClass(StorefrontCatalogCacheCoordinator::class))->newInstanceWithoutConstructor();
        $context = StorefrontCacheKeyContext::current();
        $fingerprint = hash('sha256', 'widget-fixture');
        $key = static fn($policy, string $logical): string => KeyBuilder::policyKey($policy, $logical, $fingerprint, $context);
        $mediaKey = $key(StorefrontCatalogCacheCoordinator::catalogTargetedOffersPolicy(),
            $coordinator->catalogTargetedOffersLogicalKey(0, $mediaIds, false) . '.media.v1');
        $fullKey = $key(StorefrontCatalogCacheCoordinator::catalogOffersPolicy(), $coordinator->catalogOffersLogicalKey(0));
        $summaryKey = $key(StorefrontCatalogCacheCoordinator::catalogSummaryOffersPolicy(), $coordinator->catalogSummaryOffersLogicalKey(0, 24));
        $images = [];
        foreach ($mediaIds as $id) {
            $images[$id] = 'https://cdn.example.test/' . $id . '.jpg';
        }
        $pool = $this->createMock(CachePoolInterface::class);
        $pool->method('getCustom')->willReturnCallback(function (string $cacheKey) use ($mediaKey, $fullKey, $summaryKey, $images, $fallbackOffers): array {
            $this->cacheReads[] = $cacheKey;
            self::assertContains($cacheKey, [$mediaKey, $fullKey, $summaryKey], 'Only the selected-media identity or unchanged fallback identities are valid.');
            return ['payload' => $cacheKey === $mediaKey ? $images : $fallbackOffers,
                'fresh_until' => microtime(true) + 300, 'stale_until' => microtime(true) + 1800, 'version' => 1];
        });
        $manager = $this->getMockBuilder(CacheManager::class)->disableOriginalConstructor()->onlyMethods(['pool'])->getMock();
        $manager->method('pool')->willReturn($pool);
        $generations = $this->createStub(NamespaceGenerationInterface::class);
        $generations->method('fingerprint')->willReturn($fingerprint);
        $catalog = (new \ReflectionClass(StorefrontCatalogViewService::class))->newInstanceWithoutConstructor();
        foreach ([
            'hotCache' => new StorefrontScopeHotCache($manager, $generations),
            'catalogCache' => $coordinator,
            'mediaUrls' => new StorefrontProductMediaUrlResolver($this->createStub(\Weline\FileManager\Api\FileAssetManagerInterface::class)),
        ] as $property => $value) {
            (new \ReflectionProperty($catalog, $property))->setValue($catalog, $value);
        }
        $products = (new \ReflectionClass(ProductRepository::class))->newInstanceWithoutConstructor();

        return [new StorefrontProductWidgetCatalog($catalog, $products), $mediaKey, $fullKey, $summaryKey];
    }

    private function offer(int $id, bool $hanfu = false): array
    {
        return ['product_id' => $id, 'sku' => ($hanfu ? 'HF-' : 'IMPORTED-') . $id, 'name' => 'Product ' . $id,
            'image' => 'https://cdn.example.test/fallback-' . $id . '.jpg', 'unit_price_minor' => $id,
            'currency' => 'USD', 'sellable' => true];
    }
}
