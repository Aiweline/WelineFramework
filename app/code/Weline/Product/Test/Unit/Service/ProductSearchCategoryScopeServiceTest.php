<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Product\Service\ProductCatalogQueryConsumer;
use Weline\Product\Service\ProductSearchCategoryScopeService;
use Weline\Product\Service\SearchCategoryScopeFixture;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProductSearchCategoryScopeServiceTest extends TestCase
{
    private object $menu;

    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/fixtures/search-category-scope.php';
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        RequestContext::setWelineWebsiteId(3);
        $identity = ScopeIdentity::channel(3, 'shop', 'main', 'app', ScopeIdentity::MODE_NORMAL);
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext($identity, 'en_US', 'CNY', hash('sha256', 'fixture'), hash('sha256', 'fixture-key'), true));
        $events = new class { public function dispatch(mixed ...$args): void {} };
        ObjectManager::setInstance(EventsManager::class, $events);
        $grants = new class { public function currentWebsiteId(): int { return 3; } };
        ObjectManager::setInstance(\Weline\Websites\Service\WebsiteAclGrantService::class, $grants);
        $this->menu = new class {
            public int $calls = 0;
            public function navTree(int $websiteId): array
            {
                ++$this->calls;
                return [['id' => 999, 'name' => 'Rendered menu', 'url' => '/unneeded', 'meta' => ['category_id' => 999], 'children' => []]];
            }
        };
        $menu = $this->menu;
        ObjectManager::setInstance(StorefrontAllMenuCategoryTreeService::class, $menu);
    }

    public function testSearchReadsCatalogDataWithoutBuildingNavigationUrls(): void
    {
        SearchCategoryScopeFixture::$rows = [['category_id' => 7, 'name' => 'Catalog category', 'nodes' => []]];
        $result = (new ProductSearchCategoryScopeService(new ProductCatalogQueryConsumer()))->listForSearch();
        self::assertSame('Catalog category', $result[0]['label']);
        self::assertSame(['category_id' => 7], $result[0]['params']);
        self::assertSame(0, $this->menu->calls);
        self::assertSame([['catalog', 'tree', ['space' => 'product', 'scope_level' => 'website', 'website_id' => 3, 'locale' => 'en_US']]], SearchCategoryScopeFixture::$queries);
    }

    public function testSearchKeepsActiveFilteringDepthAndPathLabelFallback(): void
    {
        SearchCategoryScopeFixture::$rows = [
            ['category_id' => 1, 'name' => '', 'path' => '/han-fu', 'nodes' => [
                ['category_id' => 2, 'name' => 'Child', 'nodes' => [
                    ['category_id' => 3, 'name' => 'Grandchild', 'nodes' => [
                        ['category_id' => 4, 'name' => 'Too deep', 'nodes' => []],
                    ]],
                ]],
            ]],
            ['category_id' => 5, 'name' => 'Inactive', 'status' => 'inactive', 'nodes' => []],
        ];
        $result = (new ProductSearchCategoryScopeService(new ProductCatalogQueryConsumer()))->listForSearch();
        self::assertCount(1, $result);
        self::assertSame('han fu', $result[0]['label']);
        self::assertSame('Grandchild', $result[0]['children'][0]['children'][0]['label']);
        self::assertSame([], $result[0]['children'][0]['children'][0]['children']);
    }

    public function testEmptyCatalogRetainsDemoFallbackWithoutNavigationLookup(): void
    {
        SearchCategoryScopeFixture::$rows = [];
        $result = (new ProductSearchCategoryScopeService(new ProductCatalogQueryConsumer()))->listForSearch();
        self::assertNotEmpty($result);
        self::assertSame(1, $result[0]['params']['is_demo']);
        self::assertSame(0, $this->menu->calls);
    }
}
