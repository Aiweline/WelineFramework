<?php

declare(strict_types=1);

namespace Weline\Promotion\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Framework\Context;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Api\ProductAdminReadInterface;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Promotion\Model\PromotionActivityTheme;
use Weline\Promotion\Model\PromotionActivityThemeProduct;
use Weline\Promotion\Service\PromotionActivityThemeService;
use Weline\Promotion\Service\PromotionScopeResolver;
use Weline\Promotion\Service\PromotionStorefrontActiveDealResolver;
use Weline\Promotion\Service\PromotionThemeDealDiscountSyncService;
use Weline\Promotion\Service\PromotionThemeProductService;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;

final class PromotionStorefrontActiveDealResolverTest extends TestCase
{
    private array $savedInstances = [];
    private ScopeIdentity $scope;
    private array $themeRows = [];
    private int $themeQueries = 0;
    private array $readerCalls = [];
    private array $productRowsByWebsite = [];

    protected function setUp(): void
    {
        $this->enterRequest('promotion-deal-test');
        $this->scope = ScopeIdentity::website(7, 'site-seven');
        $instances = ObjectManager::getInstances();
        foreach ([ProductAdminReadInterface::class, RuntimeProviderResolver::class, StorefrontCatalogViewService::class] as $class) {
            $this->savedInstances[$class] = $instances[$class] ?? null;
        }
        $scopeReader = $this->createStub(CartScopeResolverInterface::class);
        $scopeReader->method('fromParams')->willReturnCallback(fn(): ScopeIdentity => $this->scope);
        $provider = new class($scopeReader) {
            public function __construct(private object $scopeReader) {}
            public function resolve(string $contract): ?object
            {
                return $contract === CartScopeResolverInterface::class ? $this->scopeReader : null;
            }
        };
        ObjectManager::setInstance(RuntimeProviderResolver::class, $provider);
        $reader = $this->createStub(ProductAdminReadInterface::class);
        $reader->method('search')->willReturnCallback(function (int $websiteId, array $filters): array {
            $this->readerCalls[] = [$websiteId, $filters];
            return $this->productRowsByWebsite[$websiteId] ?? [];
        });
        ObjectManager::setInstance(ProductAdminReadInterface::class, $reader);
        // Contain the already-proven old recursion during RED; the separate selection test forbids this edge.
        $catalog = new class {
            public function publishedOffersForProductIds(array $productIds, int $limit): array { return []; }
        };
        ObjectManager::setInstance(StorefrontCatalogViewService::class, $catalog);
    }

    protected function tearDown(): void
    {
        foreach ($this->savedInstances as $class => $instance) {
            if ($instance !== null) {
                ObjectManager::setInstance($class, $instance);
            } else {
                ObjectManager::removeInstance($class);
            }
        }
        Context::leave();
    }

    public function testEmptySelectionDoesNotDiscountEveryProduct(): void
    {
        $this->themeRows = [$this->theme(11, 7, 'empty-deal', 10) + ['price_band' => 'under_200']];
        $resolver = $this->resolver();

        self::assertNull($resolver->resolveForProduct(27));
        self::assertNull($resolver->resolveForProduct(27, 66.0), 'An empty filter selection must remain empty even when the catalog price fits the band.');
        self::assertSame([[7, ['status' => 'published']]], $this->readerCalls);
    }

    public function testEligibleProductsRetainEarliestSortOrderDealAndReuseRequestSelection(): void
    {
        $this->themeRows = [
            $this->theme(11, 7, 'ten-percent', 10, 20),
            $this->theme(12, 7, 'twenty-percent', 20, 10),
        ];
        $this->productRowsByWebsite = [7 => [['product_id' => 27], ['product_id' => 9]]];
        $resolver = $this->resolver();
        // sort_order 10 (theme 12) wins over stronger 20% that is later in admin order.
        $expected = [
            'deal_discount_type' => 'percentage',
            'deal_discount_value' => 20.0,
            'theme_id' => 12,
            'page_slug' => 'twenty-percent',
            'marketing_rule_id' => 0,
            'campaign_label' => 'Twenty percent',
            'campaign_url' => 'https://store.example.test/promotion/test',
            'sort_order' => 10,
        ];

        self::assertSame($expected, $resolver->resolveForProduct(27));
        self::assertSame($expected, $resolver->resolveForProduct(27, 66.0));
        self::assertSame($expected, $resolver->resolveForProduct(9));
        self::assertNull($resolver->resolveForProduct(44));
        self::assertSame(1, $this->themeQueries, 'Read the active themes once in this request and scope.');
        self::assertCount(2, $this->readerCalls, 'Select product IDs once per active theme, not once per product.');
        self::assertSame(11, $resolver->resolveForProduct(27, null, 11)['theme_id'] ?? null);
        self::assertSame(
            [12, 11],
            array_map(
                static fn(array $deal): int => (int)$deal['theme_id'],
                $resolver->listEligibleDealsForProduct(27),
            ),
        );
    }

    public function testManualEmptyBindingsRetainExplicitPriceBandEligibility(): void
    {
        $theme = $this->theme(11, 7, 'manual-price-band', 10);
        $theme['product_pick_mode'] = 'manual';
        $theme['price_band'] = 'under_200';
        $this->themeRows = [$theme];
        $resolver = $this->resolver();

        self::assertNull($resolver->resolveForProduct(27));
        self::assertSame(11, $resolver->resolveForProduct(27, 66.0)['theme_id'] ?? null);
        self::assertSame(10.0, $resolver->resolveForProduct(27, 66.0)['deal_discount_value'] ?? null);
        self::assertNull($resolver->resolveForProduct(27, 200.0));
        self::assertSame([], $this->readerCalls, 'Manual empty bindings do not use filter selection.');
    }

    public function testRequestSelectionIsIsolatedBetweenWebsiteScopes(): void
    {
        $this->themeRows = [
            $this->theme(11, 7, 'site-seven-deal', 10),
            $this->theme(12, 8, 'site-eight-deal', 20),
        ];
        $this->productRowsByWebsite = [7 => [['product_id' => 27]], 8 => [['product_id' => 9]]];
        $resolver = $this->resolver();

        self::assertSame(11, $resolver->resolveForProduct(27)['theme_id'] ?? null);
        $this->scope = ScopeIdentity::website(8, 'site-eight');
        self::assertNull($resolver->resolveForProduct(27));
        self::assertSame(12, $resolver->resolveForProduct(9)['theme_id'] ?? null);
        self::assertSame(2, $this->themeQueries);
        self::assertSame([[7, ['status' => 'published']], [8, ['status' => 'published']]], $this->readerCalls);
    }

    private function resolver(): PromotionStorefrontActiveDealResolver
    {
        $bindings = $this->getMockBuilder(PromotionActivityThemeProduct::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear', 'getItems'])
            ->addMethods(['where', 'order', 'select', 'fetch'])
            ->getMock();
        foreach (['clear', 'where', 'order', 'select', 'fetch'] as $method) {
            $bindings->method($method)->willReturnSelf();
        }
        $bindings->method('getItems')->willReturn([]);
        $products = new PromotionThemeProductService(
            $bindings,
            $this->createStub(StoreCatalogInterface::class),
        );
        $scope = new PromotionScopeResolver();
        $discount = new PromotionThemeDealDiscountSyncService();
        $model = $this->getMockBuilder(PromotionActivityTheme::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['clear', 'getItems'])
            ->addMethods(['where', 'order', 'select', 'fetch'])
            ->getMock();
        foreach (['clear', 'where', 'order', 'fetch'] as $method) {
            $model->method($method)->willReturnSelf();
        }
        $model->method('select')->willReturnCallback(function () use ($model): PromotionActivityTheme {
            ++$this->themeQueries;
            return $model;
        });
        $model->method('getItems')->willReturnCallback(fn(): array => array_map(
            static fn(array $row): object => new class($row) {
                public function __construct(private array $row) {}
                public function getData(): array { return $this->row; }
            },
            $this->themeRows,
        ));
        $class = new \ReflectionClass(PromotionActivityThemeService::class);
        $themes = $class->newInstanceWithoutConstructor();
        $url = $this->createStub(\Weline\Framework\Http\Url::class);
        $url->method('getFrontendUrl')->willReturn('https://store.example.test/promotion/test');
        foreach (['theme' => $model, 'themeProductService' => $products, 'scopeResolver' => $scope, 'dealDiscountSync' => $discount, 'url' => $url] as $name => $value) {
            $class->getProperty($name)->setValue($themes, $value);
        }

        return new PromotionStorefrontActiveDealResolver($themes, $products, $scope, $discount);
    }

    private function theme(int $id, int $websiteId, string $slug, float $discount, ?int $sortOrder = null): array
    {
        return [
            'id' => $id,
            'website_id' => $websiteId,
            'store_code' => '',
            'channel_code' => '',
            'theme_key' => $slug,
            'page_slug' => $slug,
            'page_title' => $slug,
            'status' => 'active',
            'sort_order' => $sortOrder ?? $id,
            'product_pick_mode' => 'filter',
            'product_filter_json' => '{"status":"published","limit":12}',
            'deal_discount_type' => 'percentage',
            'deal_discount_value' => $discount,
            'marketing_rule_id' => 0,
        ];
    }

    private function enterRequest(string $id): void
    {
        Context::enter(new Context([
            'input' => ['uri' => '/products'],
            'runtime' => ['request_context' => ['initialized' => true, 'request_id' => $id]],
        ]));
    }
}
