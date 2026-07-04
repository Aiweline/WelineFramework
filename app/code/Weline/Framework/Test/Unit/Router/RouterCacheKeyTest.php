<?php

declare(strict_types=1);

namespace Weline\Framework\Router\Test;

use Weline\Framework\Http\Request;
use Weline\Framework\Http\Url;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Router\Core;
use Weline\Framework\UnitTest\TestCore;

/**
 * 路由缓存键测试
 * 测试 www 和非 www 域名使用不同的缓存键，避免缓存冲突
 */
class RouterCacheKeyTest extends TestCore
{
    /**
     * 保存原始 $_SERVER 状态
     */
    private array $originalServer = [];

    /**
     * 测试前准备
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 保存原始 $_SERVER
        $this->originalServer = $_SERVER;
        // 清空静态缓存
        Url::$parserSites = [];
        Url::$parserMatchs = [];
        Url::$parserCache = [];
        Url::$parserUrlCache = [];
        Url::$splitUrlCache = [];
        Url::$parserServer = [];
    }

    /**
     * 测试后清理
     */
    protected function tearDown(): void
    {
        // 恢复原始 $_SERVER
        $_SERVER = $this->originalServer;
        // 清空静态缓存
        Url::$parserSites = [];
        Url::$parserMatchs = [];
        Url::$parserCache = [];
        Url::$parserUrlCache = [];
        Url::$splitUrlCache = [];
        Url::$parserServer = [];
    }

    /**
     * 模拟服务器环境
     */
    private function simulateServer(string $host, string $requestUri, string $websiteCode = ''): void
    {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['SERVER_NAME'] = $host;
        $_SERVER['SERVER_PORT'] = '80';
        if ($websiteCode) {
            $_SERVER['WELINE_WEBSITE_CODE'] = $websiteCode;
        } else {
            unset($_SERVER['WELINE_WEBSITE_CODE']);
        }
        // 清空静态缓存，确保每次测试都是干净的环境
        Url::$parserSites = [];
        Url::$parserMatchs = [];
        Url::$parserCache = [];
        Url::$parserUrlCache = [];
        Url::$splitUrlCache = [];
        Url::$parserServer = [];
    }

    /**
     * 测试 www 和非 www 域名生成不同的 uri_cache_key
     */
    public function testWwwAndNonWwwHaveDifferentUriCacheKeys(): void
    {
        // 测试 www 域名
        $this->simulateServer('www.streetonthedaily.com', '/test');
        /** @var Request $requestWww */
        $requestWww = ObjectManager::getInstance(Request::class);
        $requestWww->__init();
        $uriCacheKeyWww = $requestWww->uri_cache_key;

        // 测试非 www 域名
        $this->simulateServer('streetonthedaily.com', '/test');
        /** @var Request $requestNonWww */
        $requestNonWww = ObjectManager::getInstance(Request::class);
        $requestNonWww->__init();
        $uriCacheKeyNonWww = $requestNonWww->uri_cache_key;

        // 验证两个缓存键不同
        $this->assertNotEquals(
            $uriCacheKeyWww,
            $uriCacheKeyNonWww,
            'www 和非 www 域名应该生成不同的 uri_cache_key'
        );

        // 验证缓存键包含域名信息
        $this->assertStringContainsString('www.streetonthedaily.com', $uriCacheKeyWww);
        $this->assertStringContainsString('streetonthedaily.com', $uriCacheKeyNonWww);
        $this->assertStringContainsString('/test', $uriCacheKeyWww);
        $this->assertStringContainsString('/test', $uriCacheKeyNonWww);
    }

    /**
     * 测试使用网站代码时，www 和非 www 生成不同的缓存键
     */
    public function testWwwAndNonWwwWithWebsiteCodeHaveDifferentCacheKeys(): void
    {
        // 测试 www 域名（带网站代码）
        $this->simulateServer('www.streetonthedaily.com', '/test', 'site_www');
        /** @var Request $requestWww */
        $requestWww = ObjectManager::getInstance(Request::class);
        $requestWww->__init();
        $uriCacheKeyWww = $requestWww->uri_cache_key;

        // 测试非 www 域名（带网站代码）
        $this->simulateServer('streetonthedaily.com', '/test', 'site_non_www');
        /** @var Request $requestNonWww */
        $requestNonWww = ObjectManager::getInstance(Request::class);
        $requestNonWww->__init();
        $uriCacheKeyNonWww = $requestNonWww->uri_cache_key;

        // 验证两个缓存键不同
        $this->assertNotEquals(
            $uriCacheKeyWww,
            $uriCacheKeyNonWww,
            '不同网站代码应该生成不同的 uri_cache_key'
        );

        // 验证缓存键包含网站代码
        $this->assertStringContainsString('site_www', $uriCacheKeyWww);
        $this->assertStringContainsString('site_non_www', $uriCacheKeyNonWww);
    }

    /**
     * 测试 Router Core 生成不同的路由缓存键
     */
    public function testRouterCoreGeneratesDifferentCacheKeys(): void
    {
        // 测试 www 域名
        $this->simulateServer('www.streetonthedaily.com', '/test');
        /** @var Core $routerWww */
        $routerWww = ObjectManager::getInstance(Core::class);
        $routerWww->__init();
        $urlCacheKeyWww = $routerWww->url_cache_key;
        $ruleCacheKeyWww = $routerWww->rule_cache_key;
        $routerCacheKeyWww = $routerWww->_router_cache_key;

        // 测试非 www 域名
        $this->simulateServer('streetonthedaily.com', '/test');
        /** @var Core $routerNonWww */
        $routerNonWww = ObjectManager::getInstance(Core::class);
        $routerNonWww->__init();
        $urlCacheKeyNonWww = $routerNonWww->url_cache_key;
        $ruleCacheKeyNonWww = $routerNonWww->rule_cache_key;
        $routerCacheKeyNonWww = $routerNonWww->_router_cache_key;

        // 验证所有缓存键都不同
        $this->assertNotEquals($urlCacheKeyWww, $urlCacheKeyNonWww, 'url_cache_key 应该不同');
        $this->assertNotEquals($ruleCacheKeyWww, $ruleCacheKeyNonWww, 'rule_cache_key 应该不同');
        $this->assertNotEquals($routerCacheKeyWww, $routerCacheKeyNonWww, '_router_cache_key 应该不同');

        // 验证缓存键包含域名信息
        $this->assertStringContainsString('www.streetonthedaily.com', $urlCacheKeyWww);
        $this->assertStringContainsString('streetonthedaily.com', $urlCacheKeyNonWww);
    }

    /**
     * 测试相同域名相同路径生成相同的缓存键
     */
    public function testSameDomainSamePathGeneratesSameCacheKey(): void
    {
        // 第一次请求
        $this->simulateServer('www.streetonthedaily.com', '/test');
        /** @var Request $request1 */
        $request1 = ObjectManager::getInstance(Request::class);
        $request1->__init();
        $uriCacheKey1 = $request1->uri_cache_key;

        // 第二次请求（相同域名和路径）
        $this->simulateServer('www.streetonthedaily.com', '/test');
        /** @var Request $request2 */
        $request2 = ObjectManager::getInstance(Request::class);
        $request2->__init();
        $uriCacheKey2 = $request2->uri_cache_key;

        // 验证缓存键相同
        $this->assertEquals(
            $uriCacheKey1,
            $uriCacheKey2,
            '相同域名和路径应该生成相同的缓存键'
        );
    }

    /**
     * 测试不同路径生成不同的缓存键
     */
    public function testDifferentPathsGenerateDifferentCacheKeys(): void
    {
        // 测试路径 /test
        $this->simulateServer('www.streetonthedaily.com', '/test');
        /** @var Request $request1 */
        $request1 = ObjectManager::getInstance(Request::class);
        $request1->__init();
        $uriCacheKey1 = $request1->uri_cache_key;

        // 测试路径 /test2
        $this->simulateServer('www.streetonthedaily.com', '/test2');
        /** @var Request $request2 */
        $request2 = ObjectManager::getInstance(Request::class);
        $request2->__init();
        $uriCacheKey2 = $request2->uri_cache_key;

        // 验证缓存键不同
        $this->assertNotEquals(
            $uriCacheKey1,
            $uriCacheKey2,
            '不同路径应该生成不同的缓存键'
        );
    }

    /**
     * 测试不同 HTTP 方法生成不同的缓存键
     */
    public function testDifferentMethodsGenerateDifferentCacheKeys(): void
    {
        // GET 请求
        $this->simulateServer('www.streetonthedaily.com', '/test');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        /** @var Request $requestGet */
        $requestGet = ObjectManager::getInstance(Request::class);
        $requestGet->__init();
        $uriCacheKeyGet = $requestGet->uri_cache_key;

        // POST 请求
        $this->simulateServer('www.streetonthedaily.com', '/test');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        /** @var Request $requestPost */
        $requestPost = ObjectManager::getInstance(Request::class);
        $requestPost->__init();
        $uriCacheKeyPost = $requestPost->uri_cache_key;

        // 验证缓存键不同
        $this->assertNotEquals(
            $uriCacheKeyGet,
            $uriCacheKeyPost,
            '不同 HTTP 方法应该生成不同的缓存键'
        );
    }

    /**
     * 测试子域名生成不同的缓存键
     */
    public function testSubdomainsGenerateDifferentCacheKeys(): void
    {
        // 测试 dev 子域名
        $this->simulateServer('dev.streetonthedaily.com', '/test');
        /** @var Request $requestDev */
        $requestDev = ObjectManager::getInstance(Request::class);
        $requestDev->__init();
        $uriCacheKeyDev = $requestDev->uri_cache_key;

        // 测试 www 域名
        $this->simulateServer('www.streetonthedaily.com', '/test');
        /** @var Request $requestWww */
        $requestWww = ObjectManager::getInstance(Request::class);
        $requestWww->__init();
        $uriCacheKeyWww = $requestWww->uri_cache_key;

        // 测试非 www 域名
        $this->simulateServer('streetonthedaily.com', '/test');
        /** @var Request $requestNonWww */
        $requestNonWww = ObjectManager::getInstance(Request::class);
        $requestNonWww->__init();
        $uriCacheKeyNonWww = $requestNonWww->uri_cache_key;

        // 验证所有缓存键都不同
        $this->assertNotEquals($uriCacheKeyDev, $uriCacheKeyWww, 'dev 和 www 应该生成不同的缓存键');
        $this->assertNotEquals($uriCacheKeyDev, $uriCacheKeyNonWww, 'dev 和非 www 应该生成不同的缓存键');
        $this->assertNotEquals($uriCacheKeyWww, $uriCacheKeyNonWww, 'www 和非 www 应该生成不同的缓存键');
    }

    /**
     * 测试多级子域名生成不同的缓存键
     */
    public function testMultiLevelSubdomainsGenerateDifferentCacheKeys(): void
    {
        // 测试 api.dev 子域名
        $this->simulateServer('api.dev.streetonthedaily.com', '/test');
        /** @var Request $requestApiDev */
        $requestApiDev = ObjectManager::getInstance(Request::class);
        $requestApiDev->__init();
        $uriCacheKeyApiDev = $requestApiDev->uri_cache_key;

        // 测试 dev 子域名
        $this->simulateServer('dev.streetonthedaily.com', '/test');
        /** @var Request $requestDev */
        $requestDev = ObjectManager::getInstance(Request::class);
        $requestDev->__init();
        $uriCacheKeyDev = $requestDev->uri_cache_key;

        // 验证缓存键不同
        $this->assertNotEquals(
            $uriCacheKeyApiDev,
            $uriCacheKeyDev,
            '多级子域名应该生成不同的缓存键'
        );
    }

    /**
     * 测试带查询字符串的路径
     */
    public function testPathWithQueryString(): void
    {
        // 测试带查询字符串
        $this->simulateServer('www.streetonthedaily.com', '/test?param=value');
        /** @var Request $requestWithQuery */
        $requestWithQuery = ObjectManager::getInstance(Request::class);
        $requestWithQuery->__init();
        $uriCacheKeyWithQuery = $requestWithQuery->uri_cache_key;

        // 测试不带查询字符串
        $this->simulateServer('www.streetonthedaily.com', '/test');
        /** @var Request $requestWithoutQuery */
        $requestWithoutQuery = ObjectManager::getInstance(Request::class);
        $requestWithoutQuery->__init();
        $uriCacheKeyWithoutQuery = $requestWithoutQuery->uri_cache_key;

        // 验证缓存键不同（因为 REQUEST_URI 不同）
        $this->assertNotEquals(
            $uriCacheKeyWithQuery,
            $uriCacheKeyWithoutQuery,
            '带查询字符串和不带查询字符串应该生成不同的缓存键'
        );
    }
}

