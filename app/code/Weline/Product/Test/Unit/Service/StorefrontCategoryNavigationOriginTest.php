<?php
declare(strict_types=1);
namespace Weline\Product\Test\Unit\Service;

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
use Weline\Product\Service\ProductCatalogQueryConsumer;
use Weline\Product\Service\SearchCategoryScopeFixture;
use Weline\Product\Service\StorefrontAllMenuCategoryTreeService;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StorefrontCategoryNavigationOriginTest extends TestCase
{
    private array $shared = [];
    private string $generation = 'one';
    private Url $url;
    private StorefrontAllMenuCategoryTreeService $service;

    protected function setUp(): void
    {
        require_once BP . 'app/code/Weline/Framework/Common/functions.php';
        require_once BP . 'app/code/Weline/Product/test/fixtures/search-category-scope.php';
        StorefrontScopeHotCache::resetProcessCache();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        $this->scope();
        $this->origin(19655);
        SearchCategoryScopeFixture::$queries = [];
        SearchCategoryScopeFixture::$rows = [[
            'category_id' => 11, 'name' => 'Women', 'path' => 'women', 'uuid' => 'women',
            'banner' => '/media/women.jpg', 'description' => 'Women description', 'summary' => 'Women summary',
            'nodes' => [['category_id' => 12, 'parent_id' => 11, 'name' => 'Hanfu', 'path' => 'women/hanfu', 'uuid' => 'hanfu']],
        ]];
        $adapter = $this->createMock(CacheAdapterInterface::class);
        $adapter->method('get')->willReturnCallback(fn(string $key): mixed => $this->shared[$key] ?? null);
        $adapter->method('set')->willReturnCallback(function(string $key, mixed $value, int $ttl = 0): bool { $this->shared[$key] = $value; return true; });
        $adapter->method('delete')->willReturnCallback(function(string $key): bool { unset($this->shared[$key]); return true; });
        $pool = new CachePool('unit_category_origin', $adapter, jitterRatio: 0.0);
        $manager = $this->createMock(CacheManager::class);
        $manager->method('registerPolicy')->willReturnCallback(static fn(CachePolicy $policy): CachePolicy => $policy);
        $manager->method('pool')->willReturn($pool);
        $generations = $this->createMock(NamespaceGenerationInterface::class);
        $generations->method('fingerprint')->willReturnCallback(fn(array $paths): string => hash('sha256', $this->generation . serialize($paths)));
        $flight = $this->createMock(SingleFlightInterface::class);
        $flight->method('acquire')->willReturn('unit-token');
        $hotCache = new StorefrontScopeHotCache($manager, $generations, $flight);
        $request = (new \ReflectionClass(WlsRequest::class))->newInstanceWithoutConstructor();
        foreach (['parsedHost' => 'shop.test:19655', 'parsedHttps' => true] as $name => $value) {
            (new \ReflectionProperty($request, $name))->setValue($request, $value);
        }
        $this->url = new class($request) extends Url {
            public int $calls = 0;
            protected function getRequest(): \Weline\Framework\Http\Request { return $this->request; }
            public function getFrontendUrl(string $path = '', array $params = [], bool $merge_url_params = false) {
                ++$this->calls;
                return parent::getFrontendUrl($path, $params, $merge_url_params);
            }
        };
        $events = $this->getMockBuilder(EventsManager::class)->disableOriginalConstructor()->onlyMethods(['dispatch'])->getMock();
        ObjectManager::setInstance(EventsManager::class, $events);
        $this->service = new StorefrontAllMenuCategoryTreeService(new ProductCatalogQueryConsumer(), $hotCache, new MenuTreeNormalizer(), $this->url);
    }

    protected function tearDown(): void
    {
        StorefrontScopeHotCache::resetProcessCache();
        RequestContext::cleanup();
        Context::leave();
    }

    private function scope(string $locale = 'en_US', string $currency = 'USD'): void
    {
        $identity = ScopeIdentity::channel(3, 'shop', 'main', 'web', ScopeIdentity::MODE_NORMAL);
        $fp = hash('sha256', $this->generation);
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext($identity, $locale, $currency, $fp, $fp, true));
        WelineEnv::set('website_id', 3);
        WelineEnv::set('website_code', 'shop');
        WelineEnv::set('area', 'frontend');
        WelineEnv::set('user.lang', $locale);
        WelineEnv::set('user.currency', $currency);
        WelineEnv::set('website.language', 'zh_Hans_CN');
        WelineEnv::set('website.currency', 'CNY');
    }

    private function origin(int $port): void
    {
        WelineEnv::set('website_url', 'https://shop.test:' . $port . '/store');
        WelineEnv::set('server.http_host', 'shop.test:' . $port);
        WelineEnv::set('request.scheme', 'https');
    }

    public function testSharedRawTreeServesCurrentOriginAndRetainsNodeContract(): void
    {
        $first = $this->service->navTree(3);
        $this->origin(9555);
        $second = $this->service->navTree(3);
        self::assertSame('https://shop.test:9555/store/USD/en_US/category/women', $second[0]['url']);
        self::assertSame('https://shop.test:9555/store/USD/en_US/category/women/hanfu', $second[0]['children'][0]['url']);
        self::assertSame('https://shop.test:19655/store/USD/en_US/category/women', $first[0]['url']);
        $this->origin(19655);
        self::assertSame($first, $this->service->navTree(3));
        self::assertCount(1, SearchCategoryScopeFixture::$queries, 'Changing transport must reuse the same raw scope cache.');
        self::assertSame('Women', $second[0]['name']);
        self::assertSame('category:women', $second[0]['ref']);
        self::assertSame(['category_id' => 11, 'parent_id' => 0, 'path' => 'women'], $second[0]['meta']);
        self::assertSame('/media/women.jpg', $second[0]['banner']);
        self::assertSame('Women description', $second[0]['description']);
        self::assertSame('Women summary', $second[0]['summary']);
        self::assertStringNotContainsString('shop.test', json_encode($this->shared));
        $calls = $this->url->calls;
        Context::current()->set('meta.request_id', 'not-a-cache-key');
        Context::current()->set('input.uri', '/unrelated?preview=0');
        self::assertSame($first, $this->service->navTree(3));
        self::assertSame($calls, $this->url->calls, 'The request memo must also avoid repeated URL rewrites.');

        RequestContext::cleanup();
        Context::leave();
        Context::enter(new Context(['meta' => ['type' => 'request', 'mode' => 'fpm']]));
        $this->scope();
        $this->origin(9555);
        StorefrontScopeHotCache::resetProcessCache();
        self::assertSame($second, $this->service->navTree(3), 'A fresh process cache must reuse the origin-free shared payload.');
        self::assertCount(1, SearchCategoryScopeFixture::$queries);
    }

    public function testRequestMemoObservesScopeVersionAndLocaleChanges(): void
    {
        $this->service->navTree(3);
        SearchCategoryScopeFixture::$rows[0]['name'] = 'Updated women';
        $this->generation = 'two';
        $this->scope();
        self::assertSame('Updated women', $this->service->navTree(3)[0]['name']);
        self::assertCount(2, SearchCategoryScopeFixture::$queries);
        $this->scope('fr_FR', 'EUR');
        self::assertSame('https://shop.test:19655/store/EUR/fr_FR/category/women', $this->service->navTree(3)[0]['url']);
        self::assertSame('fr_FR', SearchCategoryScopeFixture::$queries[2][2]['locale']);
    }
}
