<?php
declare(strict_types=1);
namespace Weline\Product\Test\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\CachePolicy;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;
use Weline\Framework\Cache\Contract\NamespaceGenerationInterface;
use Weline\Framework\Cache\Contract\SingleFlightInterface;
use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Cache\Service\StorefrontScopeHotCache;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Url;
use Weline\Framework\Http\WlsRequest;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Api\Data\StorefrontPriceAdjustment;
use Weline\Product\Api\Data\StorefrontPriceContext;
use Weline\Product\Api\Storefront\StorefrontPriceAdjustmentProviderInterface;
use Weline\Product\Service\Storefront\StorefrontOfferPriceAssembler;
use Weline\Product\Service\StorefrontCatalogCacheCoordinator;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Promotion\Service\PromotionActivityThemeService;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StorefrontCampaignRouteCacheTest extends TestCase
{
    private array $shared = [];
    private StorefrontScopeHotCache $hotCache;
    private StorefrontCatalogCacheCoordinator $keys;
    private StorefrontCatalogViewService $catalog;
    private Url $url;

    protected function setUp(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        StorefrontScopeHotCache::resetProcessCache();
        $this->request(19655);
        $adapter = $this->createMock(CacheAdapterInterface::class);
        $adapter->method('get')->willReturnCallback(fn(string $key): mixed => $this->shared[$key] ?? null);
        $adapter->method('set')->willReturnCallback(function(string $key, mixed $value, int $ttl = 0): bool {
            $this->shared[$key] = $value;
            return true;
        });
        $adapter->method('delete')->willReturnCallback(function(string $key): bool {
            unset($this->shared[$key]);
            return true;
        });
        $pool = new CachePool('unit_campaign_route', $adapter, jitterRatio: 0.0);
        $manager = $this->createMock(CacheManager::class);
        $manager->method('registerPolicy')->willReturnCallback(static fn(CachePolicy $policy): CachePolicy => $policy);
        $manager->method('pool')->willReturn($pool);
        $generations = $this->createMock(NamespaceGenerationInterface::class);
        $generations->method('fingerprint')->willReturnCallback(static fn(array $paths): string => hash('sha256', serialize($paths)));
        $flight = $this->createMock(SingleFlightInterface::class);
        $flight->method('acquire')->willReturn('unit-token');
        $this->hotCache = new StorefrontScopeHotCache($manager, $generations, $flight);
        $this->keys = (new \ReflectionClass(StorefrontCatalogCacheCoordinator::class))->newInstanceWithoutConstructor();
        $this->catalog = (new \ReflectionClass(StorefrontCatalogViewService::class))->newInstanceWithoutConstructor();
        $this->setProperty($this->catalog, 'hotCache', $this->hotCache);
        $this->setProperty($this->catalog, 'catalogCache', $this->keys);
        $request = (new \ReflectionClass(WlsRequest::class))->newInstanceWithoutConstructor();
        $this->setProperty($request, 'parsedHost', 'shop.test:19655');
        $this->setProperty($request, 'parsedHttps', true);
        $this->url = new class($request) extends Url {
            public int $calls = 0;
            protected function getRequest(): \Weline\Framework\Http\Request { return $this->request; }
            public function getFrontendUrl(string $path = '', array $params = [], bool $merge_url_params = false) {
                ++$this->calls;
                return parent::getFrontendUrl($path, $params, $merge_url_params);
            }
        };
        ObjectManager::setInstance(Url::class, $this->url);
        ObjectManager::setInstance(EventsManager::class, $this->getMockBuilder(EventsManager::class)
            ->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock());
    }

    protected function tearDown(): void
    {
        StorefrontScopeHotCache::resetProcessCache();
        RequestContext::cleanup();
        Context::leave();
    }

    private function request(int $port): void
    {
        if (Context::hasCurrent()) {
            RequestContext::cleanup();
            Context::leave();
        }
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        $identity = ScopeIdentity::channel(3, 'shop', 'main', 'web', ScopeIdentity::MODE_NORMAL);
        $fingerprint = hash('sha256', 'one');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext($identity, 'en_US', 'USD', $fingerprint, $fingerprint, true));
        foreach (['website_id' => 3, 'website_code' => 'shop', 'area' => 'frontend',
            'user.lang' => 'en_US', 'user.currency' => 'USD', 'website.language' => 'zh_Hans_CN',
            'website.currency' => 'CNY', 'website_url' => 'https://shop.test:' . $port . '/store',
            'server.http_host' => 'shop.test:' . $port, 'request.scheme' => 'https'] as $key => $value) {
            WelineEnv::set($key, $value);
        }
    }

    private function setProperty(object $target, string $name, mixed $value): void
    {
        (new \ReflectionProperty($target, $name))->setValue($target, $value);
    }

    public function testPriceAssemblyPersistsOnlyInternalRouteButKeepsExternalProviderUrl(): void
    {
        $internal = 'https://shop.test:19655/store/USD/en_US/promotion/weekend';
        $external = 'https://partner.test:19655/custom?offer=7';
        $provider = new class($internal, $external) implements StorefrontPriceAdjustmentProviderInterface {
            public function __construct(private string $internal, private string $external) {}
            public function getCode(): string { return 'route_fixture'; }
            public function getPriority(): int { return 100; }
            public function collectAdjustments(StorefrontPriceContext $context): array {
                return [new StorefrontPriceAdjustment(
                    'deal', 'Weline_Promotion', 'promotion_activity_theme', '7', 'Weekend',
                    StorefrontPriceAdjustment::TYPE_PERCENTAGE, 10, 100, false,
                    StorefrontPriceAdjustment::GROUP_UNIT, $context->productId === 1 ? $this->internal : $this->external,
                    'Weekend', $context->productId === 1 ? 'promotion/weekend' : '',
                )];
            }
            public function listEligibleCampaignChoices(StorefrontPriceContext $context): array {
                return [
                    ['theme_id' => 7, 'label' => 'Weekend', 'url' => $this->internal,
                        'frontend_route' => 'promotion/weekend', 'page_slug' => 'weekend',
                        'deal_discount_type' => 'percentage', 'deal_discount_value' => 10],
                    ['theme_id' => 8, 'label' => 'Partner', 'url' => $this->external],
                ];
            }
        };
        $assembler = (new \ReflectionClass(StorefrontOfferPriceAssembler::class))->newInstanceWithoutConstructor();
        $this->setProperty($assembler, 'resolvedProviders', [$provider]);
        $this->setProperty($this->catalog, 'priceAssembler', $assembler);
        $this->setProperty($this->catalog, 'priceAssemblerResolved', true);
        $apply = new \ReflectionMethod($this->catalog, 'applyUnifiedStorefrontPricing');
        $raw = $apply->invoke($this->catalog, ['product_id' => 1, 'catalog_price_minor' => 10000, 'currency' => 'USD']);
        self::assertSame('', $raw['campaign_url'], 'A shareable catalog row must not retain the generating request origin.');
        self::assertSame('promotion/weekend', $raw['_campaign_frontend_route']);
        self::assertSame('', $raw['eligible_campaigns'][0]['url']);
        self::assertSame('promotion/weekend', $raw['eligible_campaigns'][0]['frontend_route']);
        self::assertSame($external, $raw['eligible_campaigns'][1]['url']);
        self::assertSame(9000, $raw['unit_price_minor']);
        self::assertSame(10000, $raw['compare_at_minor']);
        self::assertTrue($raw['has_deal']);
        self::assertSame('Weekend', $raw['campaign_label']);
        self::assertSame(10.0, $raw['deal_discount_value']);
        $outside = $apply->invoke($this->catalog, ['product_id' => 2, 'catalog_price_minor' => 10000, 'currency' => 'USD']);
        self::assertSame($external, $outside['campaign_url']);
        self::assertArrayNotHasKey('_campaign_frontend_route', $outside);
        $legacy = new StorefrontPriceAdjustment('legacy', 'Vendor_Module', 'custom', '1', 'External',
            StorefrontPriceAdjustment::TYPE_PERCENTAGE, 10, url: $external);
        self::assertSame('', $legacy->frontendRoute);
        self::assertSame($external, $legacy->url);
    }

    public function testAllCatalogReadsMaterializeCurrentOriginFromOneSharedRawPayload(): void
    {
        $external = 'https://partner.test:19655/custom?offer=7';
        $raw = [['product_id' => 1, 'catalog_price_minor' => 10000, 'unit_price_minor' => 9000,
            'compare_at_minor' => 10000, 'currency' => 'USD', 'campaign_label' => 'Weekend',
            'campaign_url' => '', '_campaign_frontend_route' => 'promotion/weekend',
            'eligible_campaigns' => [
                ['theme_id' => 7, 'label' => 'Weekend', 'url' => '', 'frontend_route' => 'promotion/weekend'],
                ['theme_id' => 8, 'label' => 'Partner', 'url' => $external],
            ]]];
        $entries = [
            [StorefrontCatalogCacheCoordinator::catalogSummaryOffersPolicy(), $this->keys->catalogSummaryOffersLogicalKey(3, 48)],
            [StorefrontCatalogCacheCoordinator::catalogTargetedOffersPolicy(), $this->keys->catalogTargetedOffersLogicalKey(3, [1], true)],
            [StorefrontCatalogCacheCoordinator::catalogOffersPolicy(), $this->keys->catalogOffersLogicalKey(3)],
        ];
        $loads = 0;
        foreach ($entries as [$policy, $key]) {
            $this->hotCache->rememberPolicy($policy, $key, static function() use ($raw, &$loads): array { ++$loads; return $raw; });
        }
        $this->request(9555);
        StorefrontScopeHotCache::resetProcessCache(); // A peer worker must consume L2, not rebuild.
        $expectedUrl = 'https://shop.test:9555/store/USD/en_US/promotion/weekend';
        foreach ([
            fn(): array => $this->catalog->publishedOfferSummaries(),
            fn(): array => $this->catalog->publishedOffersForProductIds([1]),
            fn(): array => $this->catalog->publishedOffers(),
            fn(): array => $this->catalog->publishedOfferSummaries(), // Full rows request shortcut.
            fn(): array => $this->catalog->publishedOffers(100, false), // Full rows summary shortcut.
        ] as $read) {
            $row = $read()[0];
            self::assertSame($expectedUrl, $row['campaign_url']);
            self::assertSame($expectedUrl, $row['eligible_campaigns'][0]['url']);
            self::assertSame($external, $row['eligible_campaigns'][1]['url']);
            self::assertArrayNotHasKey('_campaign_frontend_route', $row);
            self::assertArrayNotHasKey('frontend_route', $row['eligible_campaigns'][0]);
            self::assertSame(9000, $row['unit_price_minor']);
        }
        self::assertSame(1, $this->url->calls, 'The same route is resolved once through the existing request cache.');
        self::assertSame(3, $loads);
        foreach ($entries as [$policy, $key]) {
            self::assertSame($raw, $this->hotCache->rememberPolicy($policy, $key, static function(): array {
                self::fail('A shared catalog hit must not query/build the catalog.');
            }));
        }
        self::assertStringNotContainsString('shop.test', json_encode($this->shared));
        $this->request(19655);
        self::assertSame('https://shop.test:19655/store/USD/en_US/promotion/weekend',
            $this->catalog->publishedOfferSummaries()[0]['campaign_url']);
    }

    public function testLegacyAbsolutePayloadKeysAreRetiredTogether(): void
    {
        self::assertNotSame('product.catalog_offers.listing.v4.3', $this->keys->catalogOffersLogicalKey(3));
        self::assertNotSame('product.catalog_offers.listing.v3.3.summary', $this->keys->catalogOffersLogicalKey(3, 'summary'));
        self::assertNotSame('product.catalog_offers.summary.v2.3.48', $this->keys->catalogSummaryOffersLogicalKey(3, 48));
        $ids = hash('sha256', serialize([1]));
        self::assertNotSame('product.catalog_offers.targeted.v2.3.full.' . $ids, $this->keys->catalogTargetedOffersLogicalKey(3, [1]));
        self::assertNotSame('product.catalog_offers.targeted.v1.3.summary.' . $ids, $this->keys->catalogTargetedOffersLogicalKey(3, [1], false));
    }

    public function testPromotionRouteHasOneOriginFreeAuthority(): void
    {
        self::assertTrue(is_callable([PromotionActivityThemeService::class, 'storefrontPath']));
        self::assertSame('promotion', PromotionActivityThemeService::storefrontPath(' index '));
        self::assertSame('promotion', PromotionActivityThemeService::storefrontPath());
        self::assertSame('promotion/weekend', PromotionActivityThemeService::storefrontPath(' WEEKEND '));
        self::assertSame('promotion/summer%20sale', PromotionActivityThemeService::storefrontPath('summer sale'));
    }
}
