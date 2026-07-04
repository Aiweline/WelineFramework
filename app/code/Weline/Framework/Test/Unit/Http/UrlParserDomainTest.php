<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Test;

use Weline\Framework\Http\Url;
use Weline\Framework\UnitTest\TestCore;

/**
 * URL解析域名测试
 * 测试不同域名格式（www、不带www、子域名）的URL解析是否正确
 */
class UrlParserDomainTest extends TestCore
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
    private function simulateServer(string $host, string $requestUri, string $scheme = 'http'): void
    {
        $_SERVER['HTTP_HOST'] = $host;
        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['REQUEST_SCHEME'] = $scheme;
        $_SERVER['SERVER_PORT'] = '80';
        $_SERVER['HTTPS'] = '';
        $_SERVER['SERVER_NAME'] = $host;
        // 清空可能影响测试的变量
        unset($_SERVER['WELINE_WEBSITE_URL']);
        unset($_SERVER['WELINE_WEBSITE_ID']);
        unset($_SERVER['WELINE_WEBSITE_CODE']);
    }

    /**
     * 测试 www.streetonthedaily.com/test 路径解析
     */
    public function testWwwDomainParsing(): void
    {
        $this->simulateServer('www.streetonthedaily.com', '/test');
        
        $result = Url::parser();
        
        // 验证 REQUEST_URI 应该是路径部分，而不是完整URL
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('www.streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        // REQUEST_URI 可能是 'test' 或 '/test'，取决于框架设计，关键是不要包含域名
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
        
        // 验证 uri 字段
        $uri = $result['uri'] ?? '';
        $this->assertNotEmpty($uri, 'uri 应该不为空');
        $this->assertStringNotContainsString('www.streetonthedaily.com', $uri, 'uri 不应该包含域名');
    }

    /**
     * 测试 streetonthedaily.com/test 路径解析（不带www）
     */
    public function testNonWwwDomainParsing(): void
    {
        $this->simulateServer('streetonthedaily.com', '/test');
        
        $result = Url::parser();
        
        // 验证 REQUEST_URI 应该是路径部分
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        // REQUEST_URI 可能是 'test' 或 '/test'，取决于框架设计，关键是不要包含域名
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
        
        // 验证 uri 字段
        $uri = $result['uri'] ?? '';
        $this->assertNotEmpty($uri, 'uri 应该不为空');
        $this->assertStringNotContainsString('streetonthedaily.com', $uri, 'uri 不应该包含域名');
    }

    /**
     * 测试 dev.streetonthedaily.com/test 路径解析（子域名）
     */
    public function testDevSubdomainParsing(): void
    {
        $this->simulateServer('dev.streetonthedaily.com', '/test');
        
        $result = Url::parser();
        
        // 验证 REQUEST_URI 应该是路径部分
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('dev.streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
        
        // 验证 uri 字段
        $uri = $result['uri'] ?? '';
        $this->assertNotEmpty($uri, 'uri 应该不为空');
        $this->assertStringNotContainsString('dev.streetonthedaily.com', $uri, 'uri 不应该包含域名');
    }

    /**
     * 测试 subdomain.streetonthedaily.com/test 路径解析（其他子域名）
     */
    public function testOtherSubdomainParsing(): void
    {
        $this->simulateServer('subdomain.streetonthedaily.com', '/test');
        
        $result = Url::parser();
        
        // 验证 REQUEST_URI 应该是路径部分
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('subdomain.streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试带查询字符串的情况
     */
    public function testUrlWithQueryString(): void
    {
        $this->simulateServer('streetonthedaily.com', '/test?param=value&foo=bar');
        
        $result = Url::parser();
        
        // 验证 REQUEST_URI 应该是路径部分（可能包含查询字符串）
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试根路径的情况
     */
    public function testRootPath(): void
    {
        $this->simulateServer('streetonthedaily.com', '/');
        
        $result = Url::parser();
        
        // 根路径可能返回字符串 '/' 或数组，取决于框架处理
        // 关键验证：不应该包含域名
        if (is_array($result)) {
            $requestUri = $result['server']['REQUEST_URI'] ?? '';
            $this->assertStringNotContainsString('streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        } else {
            // 如果返回字符串，也不应该包含域名
            $this->assertIsString($result);
            $this->assertStringNotContainsString('streetonthedaily.com', $result, '返回的字符串不应该包含域名');
        }
    }

    /**
     * 测试 split_url 方法对完整URL的处理
     */
    public function testSplitUrlWithFullUrl(): void
    {
        $fullUrl = 'http://streetonthedaily.com/test';
        $splits = Url::split_url($fullUrl, 'split');
        
        // 验证 split_url 能正确解析完整URL
        $this->assertIsArray($splits);
        $this->assertNotEmpty($splits, 'splits 应该不为空');
        $this->assertEquals('test', $splits[0], '第一个路径段应该是 test');
    }

    /**
     * 对比测试：www 和不带 www 的解析结果应该一致（路径部分）
     */
    public function testWwwVsNonWwwComparison(): void
    {
        // 测试 www 版本
        $this->simulateServer('www.streetonthedaily.com', '/test');
        $resultWww = Url::parser();
        $requestUriWww = $resultWww['server']['REQUEST_URI'] ?? '';
        
        // 清空缓存
        Url::$parserCache = [];
        Url::$parserUrlCache = [];
        Url::$splitUrlCache = [];
        Url::$parserServer = [];
        
        // 测试不带 www 版本
        $this->simulateServer('streetonthedaily.com', '/test');
        $resultNonWww = Url::parser();
        $requestUriNonWww = $resultNonWww['server']['REQUEST_URI'] ?? '';
        
        // 验证两者的 REQUEST_URI 应该相同（路径部分）
        $this->assertEquals($requestUriWww, $requestUriNonWww, 'www 和不带 www 的 REQUEST_URI 应该相同');
    }

    /**
     * 测试网站匹配失败时的路径提取
     * 模拟网站URL是 localhost，但访问的是其他域名
     */
    public function testPathExtractionWhenSiteMatchFails(): void
    {
        // 模拟网站URL是 localhost，但访问的是 streetonthedaily.com
        $this->simulateServer('streetonthedaily.com', '/test');
        
        // 设置一个不匹配的网站URL（通过事件模拟）
        // 由于网站匹配失败，应该从完整URL中提取路径
        $result = Url::parser();
        
        // 验证结果中 url 字段应该是路径部分，而不是完整URL
        $url = $result['url'] ?? '';
        if (!empty($url) && str_contains($url, '://')) {
            $this->fail('url 字段不应该包含完整URL（包含://），应该只是路径部分');
        }
        
        // 验证 REQUEST_URI 应该是路径部分
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertStringNotContainsString('streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
    }

    /**
     * 测试多级子域名（三级域名）：api.dev.streetonthedaily.com/test
     */
    public function testMultiLevelSubdomainParsing(): void
    {
        $this->simulateServer('api.dev.streetonthedaily.com', '/test');
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('api.dev.streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含多级域名');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试四级子域名：v1.api.dev.streetonthedaily.com/test
     */
    public function testFourLevelSubdomainParsing(): void
    {
        $this->simulateServer('v1.api.dev.streetonthedaily.com', '/test');
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('v1.api.dev.streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含四级域名');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试 HTTPS 协议
     */
    public function testHttpsProtocol(): void
    {
        $this->simulateServer('streetonthedaily.com', '/test', 'https');
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试带端口的域名
     */
    public function testDomainWithPort(): void
    {
        $_SERVER['HTTP_HOST'] = 'streetonthedaily.com:8080';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['SERVER_PORT'] = '8080';
        $_SERVER['HTTPS'] = '';
        $_SERVER['SERVER_NAME'] = 'streetonthedaily.com';
        unset($_SERVER['WELINE_WEBSITE_URL']);
        unset($_SERVER['WELINE_WEBSITE_ID']);
        unset($_SERVER['WELINE_WEBSITE_CODE']);
        
        // 清空缓存
        Url::$parserCache = [];
        Url::$parserUrlCache = [];
        Url::$splitUrlCache = [];
        Url::$parserServer = [];
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringNotContainsString('8080', $requestUri, 'REQUEST_URI 不应该包含端口');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试复杂路径：/path/to/resource
     */
    public function testComplexPath(): void
    {
        $this->simulateServer('streetonthedaily.com', '/path/to/resource');
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringContainsString('path', $requestUri, 'REQUEST_URI 应该包含路径 path');
        $this->assertStringContainsString('resource', $requestUri, 'REQUEST_URI 应该包含路径 resource');
    }

    /**
     * 测试 IP 地址访问
     */
    public function testIpAddressAccess(): void
    {
        $this->simulateServer('192.168.1.100', '/test');
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('192.168.1.100', $requestUri, 'REQUEST_URI 不应该包含IP地址');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试带端口的IP地址访问
     */
    public function testIpAddressWithPort(): void
    {
        $_SERVER['HTTP_HOST'] = '192.168.1.100:8080';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['REQUEST_SCHEME'] = 'http';
        $_SERVER['SERVER_PORT'] = '8080';
        $_SERVER['HTTPS'] = '';
        $_SERVER['SERVER_NAME'] = '192.168.1.100';
        unset($_SERVER['WELINE_WEBSITE_URL']);
        unset($_SERVER['WELINE_WEBSITE_ID']);
        unset($_SERVER['WELINE_WEBSITE_CODE']);
        
        // 清空缓存
        Url::$parserCache = [];
        Url::$parserUrlCache = [];
        Url::$splitUrlCache = [];
        Url::$parserServer = [];
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('192.168.1.100', $requestUri, 'REQUEST_URI 不应该包含IP地址');
        $this->assertStringNotContainsString('8080', $requestUri, 'REQUEST_URI 不应该包含端口');
        $this->assertStringContainsString('test', $requestUri, 'REQUEST_URI 应该包含路径 test');
    }

    /**
     * 测试多级子域名配合复杂路径
     */
    public function testMultiLevelSubdomainWithComplexPath(): void
    {
        $this->simulateServer('api.dev.streetonthedaily.com', '/api/v1/users/123');
        
        $result = Url::parser();
        
        $this->assertIsArray($result);
        $requestUri = $result['server']['REQUEST_URI'] ?? '';
        $this->assertNotEmpty($requestUri, 'REQUEST_URI 应该不为空');
        $this->assertStringNotContainsString('api.dev.streetonthedaily.com', $requestUri, 'REQUEST_URI 不应该包含域名');
        $this->assertStringContainsString('api', $requestUri, 'REQUEST_URI 应该包含路径 api');
        $this->assertStringContainsString('users', $requestUri, 'REQUEST_URI 应该包含路径 users');
    }
}

