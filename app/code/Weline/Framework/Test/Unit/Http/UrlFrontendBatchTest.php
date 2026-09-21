<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\App\Env;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

final class UrlFrontendBatchTest extends TestCase
{
    private array $serverBackup;
    private array $envSnapshot;
    private array $runtimeConfig;
    private array $instances;
    private ?Context $previousContext;
    private Url $url;
    private UrlFrontendBatchEventsSpy $events;

    protected function setUp(): void
    {
        parent::setUp();
        $this->serverBackup = $_SERVER;
        $this->envSnapshot = WelineEnv::getInstance()->capture();
        $this->runtimeConfig = (new ReflectionClass(Env::class))->getProperty('runtimeConfig')->getValue(Env::getInstance());
        $this->instances = ObjectManager::getInstances();
        $this->previousContext = Context::getCurrent();
        Context::enter(new Context());
        $_SERVER = [
            'HTTP_HOST' => 'fixture.test',
            'REQUEST_SCHEME' => 'https',
            'REQUEST_URI' => '/shop/en_US/current?existing=1',
            'WELINE_WEBSITE_URL' => 'https://fixture.test/shop',
        ];
        WelineEnv::getInstance()->initFromSnapshot([], [], [], [], $_SERVER);
        WelineEnv::set('website_url', 'https://fixture.test/shop', 'URL batch fixture');
        WelineEnv::set('website.currency', 'CNY', 'URL batch fixture');
        WelineEnv::set('website.language', 'zh_Hans_CN', 'URL batch fixture');
        Env::getInstance()->applyRuntimeConfig(['seo' => true, 'currency' => 'CNY', 'locale' => 'zh_Hans_CN']);
        RequestContext::setId('url-frontend-batch-fixture');
        StorefrontCacheKeyContext::install(new StorefrontCacheKeyContext(
            ScopeIdentity::channel(7, 'fixture', 'store', 'channel', ScopeIdentity::MODE_NORMAL),
            'en_US',
            'CNY',
            hash('sha256', 'fixture-namespaces'),
            hash('sha256', 'fixture-scope'),
            true,
        ));

        $request = $this->createMock(Request::class);
        $request->method('getBaseHost')->willReturn('https://fixture.test/shop');
        $request->method('getBaseUrl')->willReturn('https://fixture.test/shop/en_US/current?existing=1');
        $request->method('getRouterData')->with('router')->willReturn('catalog');
        $request->method('getGet')->willReturn(['existing' => '1', 'ignored_array' => ['x']]);
        $this->events = new UrlFrontendBatchEventsSpy();
        ObjectManager::setInstance(Request::class, $request);
        ObjectManager::setInstance(EventsManager::class, $this->events);
        $this->url = new Url($request);
    }

    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(Env::class);
        $reflection->getProperty('runtimeConfig')->setValue(Env::getInstance(), $this->runtimeConfig);
        $reflection->getMethod('rebuildEffectiveConfig')->invoke(Env::getInstance());
        (new ReflectionClass(ObjectManager::class))->getMethod('setScopedInstances')->invoke(null, $this->instances);
        $_SERVER = $this->serverBackup;
        WelineEnv::getInstance()->restore($this->envSnapshot);
        Context::leave();
        if ($this->previousContext !== null) {
            Context::enter($this->previousContext);
        }
        parent::tearDown();
    }

    public function testBatchMatchesIndividualUrlsAndPrefetchesBeforeEveryRewrite(): void
    {
        $paths = [
            'category' => '/shop/category/men?from=nav',
            8 => 'product/item',
            'duplicate' => 'product/item',
            'external' => 'https://external.test/path?outside=1',
            'wildcard' => '*/view',
            'current' => '',
        ];
        $params = ['sort' => 'name', 'zero' => 0, 'omit' => false];
        $expected = [];
        foreach ($paths as $key => $path) {
            $expected[$key] = $this->url->getFrontendUrl($path, $params, true);
        }
        self::assertSame(
            'https://fixture.test/shop/en_US/men-fashion?existing=1&from=nav&sort=name&zero=0',
            $expected['category'],
        );
        self::assertCount(count($paths) * 2, $this->events->calls);
        $this->events->calls = [];

        $actual = $this->url->getFrontendUrls($paths, $params, true);

        self::assertSame($expected, $actual);
        self::assertSame(array_keys($paths), array_keys($actual));
        self::assertSame($actual[8], $actual['duplicate']);
        self::assertSame([
            'category' => 'https://fixture.test/shop/en_US/category/men?from=nav',
            8 => 'https://fixture.test/shop/en_US/product/item',
            'duplicate' => 'https://fixture.test/shop/en_US/product/item',
            'external' => 'https://external.test/path?outside=1',
            'wildcard' => 'https://fixture.test/shop/en_US/catalog/view',
            'current' => 'https://fixture.test/shop/en_US/current?existing=1',
        ], $this->events->calls[0]['data']);
        $names = array_column($this->events->calls, 'name');
        self::assertSame(UrlFrontendBatchEventsSpy::PREFETCH, $names[0]);
        $perUrl = array_slice($names, 1);
        self::assertSame(
            array_merge(
                ...array_fill(0, count($paths), [
                    UrlFrontendBatchEventsSpy::REWRITE,
                    UrlFrontendBatchEventsSpy::PARAMS,
                ])
            ),
            $perUrl
        );
        self::assertStringContainsString('/category/men?', $this->events->calls[1]['data']);
        self::assertStringContainsString('/men-fashion?', $actual['category']);
    }

    public function testSeoDisabledSkipsRewriteButAlwaysDispatchesParams(): void
    {
        Env::getInstance()->applyRuntimeConfig(['seo' => false]);
        $paths = ['first' => 'category/men?from=nav', 'same' => 'category/men?from=nav'];
        $params = ['page' => 2];
        $expected = [];
        foreach ($paths as $key => $path) {
            $expected[$key] = $this->url->getFrontendUrl($path, $params, false);
        }

        self::assertSame($expected, $this->url->getFrontendUrls($paths, $params, false));
        self::assertSame('https://fixture.test/shop/en_US/category/men?from=nav&page=2', $expected['first']);
        // seo=off：无 prefetch / rewrite；params 在最终输出前始终派发。
        self::assertSame([
            UrlFrontendBatchEventsSpy::PARAMS,
            UrlFrontendBatchEventsSpy::PARAMS,
            UrlFrontendBatchEventsSpy::PARAMS,
            UrlFrontendBatchEventsSpy::PARAMS,
        ], array_column($this->events->calls, 'name'));
        self::assertStringContainsString('/category/men?', $expected['first']);
        self::assertStringNotContainsString('/men-fashion?', $expected['first']);
    }

    public function testEmptyBatchDoesNotDispatchEvents(): void
    {
        self::assertSame([], $this->url->getFrontendUrls([], ['page' => 2], true));
        self::assertSame([], $this->events->calls);
    }
}

final class UrlFrontendBatchEventsSpy extends EventsManager
{
    public const PREFETCH = 'Weline_Framework_Url::url_generate_rewrite_prefetch';
    public const REWRITE = 'Weline_Framework_Url::url_generate_rewrite';
    public const PARAMS = 'Weline_Framework_Url::url_generate_params';
    /** @var list<array{name:string,data:mixed}> */
    public array $calls = [];

    public function __construct()
    {
    }

    public function dispatch(string $eventName, mixed &$data = []): static
    {
        $this->calls[] = ['name' => $eventName, 'data' => $data];
        if ($eventName === self::REWRITE && Env::get('seo')) {
            $data = str_replace('/category/men', '/men-fashion', (string)$data);
        }
        return $this;
    }
}
