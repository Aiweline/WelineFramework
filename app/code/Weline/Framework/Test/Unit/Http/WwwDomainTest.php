<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Test;

use Weline\Framework\Http\Url;
use Weline\Framework\UnitTest\TestCore;

/**
 * WWW 和非 WWW 域名测试
 * 测试生产环境中 www 和非 www 域名的访问情况
 */
class WwwDomainTest extends TestCore
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
        parent::tearDown();
    }

    /**
     * 模拟服务器环境
     */
    private function simulateServer(string $host, string $requestUri, string $scheme = 'http', int $port = 80): void
    {
        $_SERVER['HTTP_HOST'] = $host . ($port != 80 && $port != 443 ? ':' . $port : '');
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['REQUEST_SCHEME'] = $scheme;
        $_SERVER['SERVER_PORT'] = (string)$port;
        $_SERVER['HTTPS'] = ($scheme === 'https') ? 'on' : '';
        $_SERVER['SERVER_NAME'] = $host;
        // 清空可能影响测试的变量
        unset($_SERVER['WELINE_WEBSITE_URL']);
        unset($_SERVER['WELINE_WEBSITE_ID']);
        unset($_SERVER['WELINE_WEBSITE_CODE']);
    }

    /**
     * 模拟网站配置（通过事件）
     */
    private function setupWebsiteConfig(string $siteUrl): void
    {
        // 模拟网站配置
        Url::$parserSites = [
            $siteUrl => [
                'website_id' => 1,
                'name' => '测试网站',
                'code' => 'test',
                'url' => $siteUrl,
                'default_currency' => 'CNY',
                'default_language' => 'zh_Hans_CN',
                'default_timezone' => 'Asia/Shanghai',
            ]
        ];
    }

    /**
     * 一次性模拟多个网站配置
     *
     * @param array<string, array<string, mixed>> $sites
     */
    private function setupWebsiteConfigs(array $sites): void
    {
        Url::$parserSites = $sites;
    }

    /**
     * 测试场景1：网站配置为 example.com，访问 www.example.com
     * 这是生产环境常见的问题场景
     */
    public function testWwwAccessWhenSiteConfiguredAsNonWww(): void
    {
        $siteUrl = 'http://example.com';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 www.example.com
        $this->simulateServer('www.example.com', '/test');
        
        $result = Url::parser();
        
        // 验证应该能够正确匹配网站
        $this->assertIsArray($result);
        
        // 关键验证：应该能够匹配到网站配置
        $this->assertArrayHasKey('website', $result, '应该能够匹配到网站配置');
        $this->assertNotEmpty($result['website'], '网站配置不应该为空');
        
        // 当前 parser 契约：website_url 跟随当前请求 host 变体
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals('http://www.example.com', $websiteUrl, '网站 URL 应该跟随当前请求的 host 变体');
        
        // 验证 REQUEST_URI 应该是路径部分
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('www.example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
        
        // 验证网站代码已设置
        $websiteCode = $result['server']['WELINE_WEBSITE_CODE'] ?? '';
        $this->assertEquals('test', $websiteCode, '网站代码应该正确设置');
    }

    /**
     * 测试场景2：网站配置为 www.example.com，访问 example.com
     */
    public function testNonWwwAccessWhenSiteConfiguredAsWww(): void
    {
        $siteUrl = 'http://www.example.com';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 example.com
        $this->simulateServer('example.com', '/test');
        
        $result = Url::parser();
        
        // 验证应该能够正确匹配网站
        $this->assertIsArray($result);
        
        // 关键验证：应该能够匹配到网站配置
        $this->assertArrayHasKey('website', $result, '应该能够匹配到网站配置');
        $this->assertNotEmpty($result['website'], '网站配置不应该为空');
        
        // 当前 parser 契约：website_url 跟随当前请求 host 变体
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals('http://example.com', $websiteUrl, '网站 URL 应该跟随当前请求的 host 变体');
        
        // 验证 REQUEST_URI 应该是路径部分
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        
        // 验证网站代码已设置
        $websiteCode = $result['server']['WELINE_WEBSITE_CODE'] ?? '';
        $this->assertEquals('test', $websiteCode, '网站代码应该正确设置');
    }

    /**
     * 测试场景3：网站配置为 example.com，访问 example.com（正常情况）
     */
    public function testExactMatch(): void
    {
        $siteUrl = 'http://example.com';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 example.com
        $this->simulateServer('example.com', '/test');
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertNotEmpty($websiteUrl, '应该能够匹配到网站');
    }

    /**
     * 测试场景4：HTTPS 协议下的 www 和非 www 匹配
     */
    public function testHttpsWwwMatching(): void
    {
        $siteUrl = 'https://example.com';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 www.example.com (HTTPS)
        $this->simulateServer('www.example.com', '/test', 'https', 443);
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertNotEmpty($websiteUrl, 'HTTPS 下应该能够匹配到网站');
    }

    /**
     * 测试场景5：带端口的域名匹配
     */
    public function testDomainWithPortMatching(): void
    {
        $siteUrl = 'http://example.com:8080';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 www.example.com:8080
        $this->simulateServer('www.example.com', '/test', 'http', 8080);
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertNotEmpty($websiteUrl, '带端口时应该能够匹配到网站');
    }

    /**
     * 测试场景6：子域名不应该被误匹配
     */
    public function testSubdomainShouldNotMatch(): void
    {
        $siteUrl = 'http://example.com';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 api.example.com（子域名，不应该匹配）
        $this->simulateServer('api.example.com', '/test');
        
        $result = Url::parser();
        
        // 子域名不应该匹配到主域名配置
        // 这个测试验证我们的规范化逻辑不会过度匹配
        $this->assertIsArray($result);
        
        // 子域名不应该匹配到主域名配置
        // 如果匹配失败，result 中可能没有 'website' 键，或者 website_url 不匹配
        if (isset($result['website'])) {
            // 如果匹配了，验证不是误匹配
            $matchedUrl = $result['website_url'] ?? '';
            $this->assertNotEquals($siteUrl, $matchedUrl, '子域名不应该匹配到主域名配置');
        }
    }
    
    /**
     * 测试场景8：测试域名规范化函数 normalizeHost
     */
    public function testNormalizeHostFunction(): void
    {
        if (!method_exists(Url::class, 'normalizeHost')) {
            $this->markTestSkipped('normalizeHost 方法已移除，跳过测试。');
        }

        $normalizeHost = [Url::class, 'normalizeHost'];

        // 测试移除 www
        $this->assertEquals('example.com', call_user_func($normalizeHost, 'www.example.com', true));
        $this->assertEquals('example.com', call_user_func($normalizeHost, 'example.com', true));
        
        // 测试添加 www
        $this->assertEquals('www.example.com', call_user_func($normalizeHost, 'example.com', false));
        $this->assertEquals('www.example.com', call_user_func($normalizeHost, 'www.example.com', false));
        
        // 测试带端口的情况
        $this->assertEquals('example.com', call_user_func($normalizeHost, 'www.example.com:8080', true));
    }
    
    /**
     * 测试场景9：测试 isHostMatch 函数
     */
    public function testIsHostMatchFunction(): void
    {
        if (!method_exists(Url::class, 'isHostMatch')) {
            $this->markTestSkipped('isHostMatch 方法已移除，跳过测试。');
        }

        $isHostMatch = [Url::class, 'isHostMatch'];

        // www 和非 www 应该匹配
        $this->assertTrue(call_user_func($isHostMatch, 'http://www.example.com/test', 'http://example.com'));
        $this->assertTrue(call_user_func($isHostMatch, 'http://example.com/test', 'http://www.example.com'));
        
        // 相同域名应该匹配
        $this->assertTrue(call_user_func($isHostMatch, 'http://example.com/test', 'http://example.com'));
        
        // 不同协议不应该匹配
        $this->assertFalse(call_user_func($isHostMatch, 'http://example.com/test', 'https://example.com'));
        
        // 不同端口不应该匹配
        $this->assertFalse(call_user_func($isHostMatch, 'http://example.com:8080/test', 'http://example.com:9981'));
        
        // 子域名不应该匹配
        $this->assertFalse(call_user_func($isHostMatch, 'http://api.example.com/test', 'http://example.com'));
    }
    
    /**
     * 测试场景10：实际生产环境场景 - 端口 9981
     */
    public function testProductionPort9981(): void
    {
        $siteUrl = 'http://example.com:9981';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 www.example.com:9981
        $this->simulateServer('www.example.com', '/test', 'http', 9981);
        
        $result = Url::parser();
        
        // 验证应该能够正确匹配网站
        $this->assertIsArray($result);
        $this->assertArrayHasKey('website', $result, '应该能够匹配到网站配置');
        
        // 当前 parser 契约：website_url 跟随当前请求 host 变体
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals('http://www.example.com:9981', $websiteUrl, '网站 URL 应该跟随当前请求的 host 变体（包含端口）');
    }

    /**
     * 测试场景7：对比 www 和非 www 的缓存键生成
     */
    public function testCacheKeyGeneration(): void
    {
        $siteUrl = 'http://example.com';
        $this->setupWebsiteConfig($siteUrl);
        
        // 测试 www 域名的缓存键
        $this->simulateServer('www.example.com', '/test');
        $result1 = Url::parser();
        $cacheKey1 = $result1['server']['WELINE_WEBSITE_CODE'] ?? $result1['website_url'] ?? '';
        
        // 清空缓存
        Url::$parserCache = [];
        Url::$parserUrlCache = [];
        Url::$splitUrlCache = [];
        Url::$parserServer = [];
        
        // 重新设置网站配置
        $this->setupWebsiteConfig($siteUrl);
        
        // 测试非 www 域名的缓存键
        $this->simulateServer('example.com', '/test');
        $result2 = Url::parser();
        $cacheKey2 = $result2['server']['WELINE_WEBSITE_CODE'] ?? $result2['website_url'] ?? '';
        
        // 如果网站代码相同，缓存键应该相同（或者根据业务需求不同）
        // 这里主要验证不会因为域名格式不同而导致缓存问题
        $this->assertNotEmpty($cacheKey1, 'www 域名的缓存键应该不为空');
        $this->assertNotEmpty($cacheKey2, '非 www 域名的缓存键应该不为空');
        
        // 验证网站代码应该相同（因为匹配的是同一个网站配置）
        $websiteCode1 = $result1['server']['WELINE_WEBSITE_CODE'] ?? '';
        $websiteCode2 = $result2['server']['WELINE_WEBSITE_CODE'] ?? '';
        $this->assertEquals($websiteCode1, $websiteCode2, 'www 和非 www 应该使用相同的网站代码');
    }
    
    /**
     * 测试场景11：真实域名测试 - example.com
     * 测试 hosts 文件中配置的 example.com 域名
     */
    public function testRealDomainExampleCom(): void
    {
        $siteUrl = 'http://example.com:9981';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 example.com:9981
        $this->simulateServer('example.com', '/test', 'http', 9981);
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('website', $result, '应该能够匹配到网站配置');
        
        // 验证网站 URL 匹配
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals($siteUrl, $websiteUrl, '网站 URL 应该匹配配置的 URL');
        
        // 验证 REQUEST_URI 不包含域名
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertStringNotContainsString('example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
    }
    
    /**
     * 测试场景12：真实域名测试 - test.example.com
     * 测试 hosts 文件中配置的 test.example.com 域名
     */
    public function testRealDomainTestExampleCom(): void
    {
        $siteUrl = 'http://test.example.com:9981';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 test.example.com:9981
        $this->simulateServer('test.example.com', '/test', 'http', 9981);
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('website', $result, '应该能够匹配到网站配置');
        
        // 验证网站 URL 匹配
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals($siteUrl, $websiteUrl, '网站 URL 应该匹配配置的 URL');
        
        // 验证 REQUEST_URI 不包含域名
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertStringNotContainsString('test.example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
    }
    
    /**
     * 测试场景13：真实域名测试 - www.test.example.com
     * 测试 hosts 文件中配置的 www.test.example.com 域名
     */
    public function testRealDomainWwwTestExampleCom(): void
    {
        $siteUrl = 'http://test.example.com:9981';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 www.test.example.com:9981（www 版本应该能匹配到非 www 配置）
        $this->simulateServer('www.test.example.com', '/test', 'http', 9981);
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('website', $result, 'www.test.example.com 应该能够匹配到 test.example.com 的网站配置');
        
        // 当前 parser 契约：website_url 跟随当前请求 host 变体
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals('http://www.test.example.com:9981', $websiteUrl, 'website_url 应该跟随当前 www 请求 host');
        
        // 验证 REQUEST_URI 不包含域名
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertStringNotContainsString('www.test.example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringNotContainsString('test.example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
    }
    
    /**
     * 测试场景14：真实域名测试 - test.example.com 配置，访问 www.test.example.com
     * 这是生产环境常见的问题场景
     */
    public function testRealDomainWwwAccessWhenSiteConfiguredAsNonWww(): void
    {
        $siteUrl = 'http://test.example.com:9981';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 www.test.example.com:9981
        $this->simulateServer('www.test.example.com', '/test', 'http', 9981);
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('website', $result, 'www.test.example.com 应该能够匹配到 test.example.com 的网站配置');
        
        // 当前 parser 契约：website_url 跟随当前请求 host 变体
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals('http://www.test.example.com:9981', $websiteUrl, '网站 URL 应该跟随当前请求的 host 变体');
        
        // 验证网站代码已设置
        $websiteCode = $result['server']['WELINE_WEBSITE_CODE'] ?? '';
        $this->assertEquals('test', $websiteCode, '网站代码应该正确设置');
        
        // 验证 REQUEST_URI 不包含域名
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertStringNotContainsString('www.test.example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
    }
    
    /**
     * 测试场景15：真实域名测试 - www.test.example.com 配置，访问 test.example.com
     */
    public function testRealDomainNonWwwAccessWhenSiteConfiguredAsWww(): void
    {
        $siteUrl = 'http://www.test.example.com:9981';
        $this->setupWebsiteConfig($siteUrl);
        
        // 模拟访问 test.example.com:9981
        $this->simulateServer('test.example.com', '/test', 'http', 9981);
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('website', $result, 'test.example.com 应该能够匹配到 www.test.example.com 的网站配置');
        
        // 当前 parser 契约：website_url 跟随当前请求 host 变体
        $websiteUrl = $result['website_url'] ?? '';
        $this->assertEquals('http://test.example.com:9981', $websiteUrl, '网站 URL 应该跟随当前请求的 host 变体');
        
        // 验证网站代码已设置
        $websiteCode = $result['server']['WELINE_WEBSITE_CODE'] ?? '';
        $this->assertEquals('test', $websiteCode, '网站代码应该正确设置');
        
        // 验证 REQUEST_URI 不包含域名
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertStringNotContainsString('test.example.com', $requestUri, 'REQUEST_URI 不应该包含域名');
    }

    /**
     * 测试场景16：模板测试页面在多个域名下的访问
     */
    public function testTemplateTestPagePreviewRoutes(): void
    {
        $this->setupWebsiteConfigs([
            'http://127.0.0.1:9981' => [
                'website_id' => 1,
                'name' => '本地站点',
                'code' => 'local',
                'url' => 'http://127.0.0.1:9981',
                'default_currency' => 'CNY',
                'default_language' => 'zh_Hans_CN',
                'default_timezone' => 'Asia/Shanghai',
            ],
            'http://example.com:9981' => [
                'website_id' => 2,
                'name' => '示例站点',
                'code' => 'example',
                'url' => 'http://example.com:9981',
                'default_currency' => 'CNY',
                'default_language' => 'zh_Hans_CN',
                'default_timezone' => 'Asia/Shanghai',
            ],
            'http://test.example.com:9981' => [
                'website_id' => 3,
                'name' => '测试站点',
                'code' => 'test',
                'url' => 'http://test.example.com:9981',
                'default_currency' => 'CNY',
                'default_language' => 'zh_Hans_CN',
                'default_timezone' => 'Asia/Shanghai',
            ],
            'http://www.test.example.com:9981' => [
                'website_id' => 4,
                'name' => '测试站点 WWW',
                'code' => 'test_www',
                'url' => 'http://www.test.example.com:9981',
                'default_currency' => 'CNY',
                'default_language' => 'zh_Hans_CN',
                'default_timezone' => 'Asia/Shanghai',
            ],
        ]);

        $testCases = [
            [
                'host' => '127.0.0.1',
                'expectedUrl' => 'http://127.0.0.1:9981',
                'expectedCode' => 'local',
            ],
            [
                'host' => 'example.com',
                'expectedUrl' => 'http://example.com:9981',
                'expectedCode' => 'example',
            ],
            [
                'host' => 'test.example.com',
                'expectedUrl' => 'http://test.example.com:9981',
                'expectedCode' => 'test',
            ],
            [
                'host' => 'www.test.example.com',
                'expectedUrl' => 'http://www.test.example.com:9981',
                'expectedCode' => 'test_www',
            ],
        ];

        foreach ($testCases as $case) {
            Url::$parserMatchs = [];
            Url::$parserCache = [];
            Url::$parserUrlCache = [];
            Url::$splitUrlCache = [];
            Url::$parserServer = [];

            $this->simulateServer($case['host'], '/template-test-page?preview=1', 'http', 9981);
            $result = Url::parser();

            $this->assertIsArray($result, sprintf('域名 %s 的解析结果应该是数组', $case['host']));
            $this->assertArrayHasKey('website', $result, sprintf('域名 %s 应该能够匹配到网站配置', $case['host']));
            $this->assertEquals(
                $case['expectedUrl'],
                $result['website_url'] ?? '',
                sprintf('域名 %s 的 website_url 应该匹配到配置值', $case['host'])
            );
            $this->assertEquals(
                $case['expectedCode'],
                $result['server']['WELINE_WEBSITE_CODE'] ?? '',
                sprintf('域名 %s 的站点代码应该正确设置', $case['host'])
            );
            $this->assertEquals(
                '/template-test-page',
                $result['server']['REQUEST_URI'] ?? '',
                sprintf('域名 %s 的 REQUEST_URI 应该收敛为纯路由 path', $case['host'])
            );
            $this->assertEquals(
                '/template-test-page',
                $result['server']['ORIGIN_REQUEST_URI'] ?? '',
                sprintf('域名 %s 的 ORIGIN_REQUEST_URI 当前也会收敛为纯路由 path', $case['host'])
            );
            $this->assertEquals(
                'template-test-page',
                $result['uri'] ?? '',
                sprintf('域名 %s 的 URI 应该是去掉查询串后的纯路由', $case['host'])
            );
        }
    }
}

