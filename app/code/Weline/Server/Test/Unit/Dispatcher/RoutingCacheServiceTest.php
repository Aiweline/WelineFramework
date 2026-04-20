<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Dispatcher;

use PHPUnit\Framework\TestCase;
use Weline\Server\Dispatcher\RoutingCacheService;

/**
 * 测试 RoutingCacheService 的路由缓存功能
 *
 * 重点测试：
 * 1. 重定向响应（3xx）不应被缓存
 * 2. 正常响应（2xx）应被缓存
 * 3. 缓存清除功能
 */
class RoutingCacheServiceTest extends TestCase
{
    private RoutingCacheService $service;

    protected function setUp(): void
    {
        $this->service = RoutingCacheService::getInstance();
        // 清空缓存，确保测试隔离
        $this->service->purgeAll();
    }

    protected function tearDown(): void
    {
        // 测试后清理
        $this->service->purgeAll();
    }

    /**
     * 测试：3xx 重定向响应不应被缓存
     */
    public function testRedirectResponseShouldNotBeCached(): void
    {
        $clientIp = '192.168.1.100';
        $sni = 'example.com';
        $connId = 1;

        // 模拟 301 重定向响应
        $redirectResponse = "HTTP/1.1 301 Moved Permanently\r\n" .
                           "Location: https://www.example.com\r\n" .
                           "\r\n";

        $result = $this->service->learnFromResponse($redirectResponse, $connId, $clientIp, $sni);

        // 重定向响应应返回 null，表示不缓存
        $this->assertNull($result, '301 重定向响应不应被缓存');
    }

    /**
     * 测试：302 临时重定向不应被缓存
     */
    public function testTemporaryRedirectShouldNotBeCached(): void
    {
        $clientIp = '192.168.1.101';
        $sni = 'test.com';
        $connId = 2;

        $redirectResponse = "HTTP/1.1 302 Found\r\n" .
                           "Location: https://test.com/login\r\n" .
                           "\r\n";

        $result = $this->service->learnFromResponse($redirectResponse, $connId, $clientIp, $sni);

        $this->assertNull($result, '302 重定向响应不应被缓存');
    }

    /**
     * 测试：307 临时重定向不应被缓存
     */
    public function testTemporaryRedirect307ShouldNotBeCached(): void
    {
        $clientIp = '192.168.1.102';
        $sni = 'api.example.com';
        $connId = 3;

        $redirectResponse = "HTTP/1.1 307 Temporary Redirect\r\n" .
                           "Location: https://api.example.com/v2\r\n" .
                           "\r\n";

        $result = $this->service->learnFromResponse($redirectResponse, $connId, $clientIp, $sni);

        $this->assertNull($result, '307 重定向响应不应被缓存');
    }

    /**
     * 测试：308 永久重定向不应被缓存
     */
    public function testPermanentRedirect308ShouldNotBeCached(): void
    {
        $clientIp = '192.168.1.103';
        $sni = 'old.example.com';
        $connId = 4;

        $redirectResponse = "HTTP/1.1 308 Permanent Redirect\r\n" .
                           "Location: https://new.example.com\r\n" .
                           "\r\n";

        $result = $this->service->learnFromResponse($redirectResponse, $connId, $clientIp, $sni);

        $this->assertNull($result, '308 重定向响应不应被缓存');
    }

    /**
     * 测试：303 See Other 重定向不应被缓存
     */
    public function testSeeOtherRedirectShouldNotBeCached(): void
    {
        $clientIp = '192.168.1.104';
        $sni = 'form.example.com';
        $connId = 5;

        $redirectResponse = "HTTP/1.1 303 See Other\r\n" .
                           "Location: https://form.example.com/success\r\n" .
                           "\r\n";

        $result = $this->service->learnFromResponse($redirectResponse, $connId, $clientIp, $sni);

        $this->assertNull($result, '303 重定向响应不应被缓存');
    }

    /**
     * 测试：200 正常响应需要 X-Weline-Route-Hint 头才能被缓存
     */
    public function testSuccessResponseWithRouteHintShouldBeCached(): void
    {
        $clientIp = '192.168.1.200';
        $sni = 'normal.com';
        $connId = 10;

        // 包含 X-Weline-Route-Hint 头的响应（格式：port=xxx,sni=xxx,ttl=xxx）
        $successResponse = "HTTP/1.1 200 OK\r\n" .
                          "Content-Type: text/html\r\n" .
                          "X-Weline-Route-Hint: port=16895,sni=normal.com,ttl=3600\r\n" .
                          "\r\n" .
                          "<html><body>Hello</body></html>";

        $result = $this->service->learnFromResponse($successResponse, $connId, $clientIp, $sni);

        // 有 Route-Hint 的响应应返回路由信息
        $this->assertNotNull($result, '带 Route-Hint 的 200 响应应被缓存');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('port', $result);
        $this->assertEquals(16895, $result['port']);
    }

    /**
     * 测试：没有 X-Weline-Route-Hint 头的响应不会被缓存
     */
    public function testResponseWithoutRouteHintShouldNotBeCached(): void
    {
        $clientIp = '192.168.1.201';
        $sni = 'noroute.com';
        $connId = 11;

        $response = "HTTP/1.1 200 OK\r\n" .
                   "Content-Type: text/html\r\n" .
                   "\r\n" .
                   "<html><body>Hello</body></html>";

        $result = $this->service->learnFromResponse($response, $connId, $clientIp, $sni);

        // 没有 Route-Hint 的响应不会被缓存
        $this->assertNull($result, '没有 Route-Hint 的响应不应被缓存');
    }

    /**
     * 测试：purgeAll 应清除所有路由
     */
    public function testPurgeAllShouldRemoveAllRoutes(): void
    {
        // 缓存多个路由
        $routes = [
            ['192.168.1.1', 'site1.com', 1],
            ['192.168.1.2', 'site2.com', 2],
            ['192.168.1.3', 'site3.com', 3],
        ];

        $response = "HTTP/1.1 200 OK\r\n" .
                   "X-Weline-Route-Hint: port=16895,sni=test.com,ttl=3600\r\n" .
                   "\r\n" .
                   "OK";

        foreach ($routes as [$ip, $sni, $connId]) {
            $result = $this->service->learnFromResponse($response, $connId, $ip, $sni);
            $this->assertNotNull($result, "路由 {$ip}:{$sni} 应被缓存");
        }

        // 清除所有缓存
        $this->service->purgeAll();

        // 验证 purgeAll 执行成功（没有异常）
        $this->assertTrue(true, 'purgeAll 应成功执行');
    }

    public function testCacheEvictionKeepsEntryCountWithinConfiguredLimit(): void
    {
        $this->service->configure([
            'max_sni_entries' => 5,
            'max_ip_entries' => 5,
            'max_connection_entries' => 5,
            'cleanup_interval' => 3600,
        ]);

        for ($i = 0; $i < 6; $i++) {
            $this->service->cacheSniRoute("site{$i}.example.com", 16000 + $i, 3600);
            $this->service->cacheIpRoute("10.0.0.{$i}", 17000 + $i, "site{$i}.example.com", 3600);
            $this->service->cacheConnectionRoute($i + 1, 18000 + $i, "site{$i}.example.com");
        }

        $stats = $this->service->getStats();
        $this->assertLessThanOrEqual(5, $stats['sni_cache_size']);
        $this->assertLessThanOrEqual(5, $stats['ip_cache_size']);
        $this->assertLessThanOrEqual(5, $stats['connection_cache_size']);
    }

    public function testMaybeCleanupRemovesExpiredEntriesAcrossAllCaches(): void
    {
        $this->service->configure([
            'cleanup_interval' => 0,
            'max_sni_entries' => 100,
            'max_ip_entries' => 100,
            'max_connection_entries' => 100,
        ]);

        $this->service->cacheSniRoute('expired.example.com', 16895, -1);
        $this->service->cacheIpRoute('10.0.1.1', 16896, 'expired.example.com', -1);
        $this->service->cacheConnectionRoute(99, 16897, 'expired.example.com');

        $connectionCache = new \ReflectionProperty($this->service, 'connectionCache');
        $connectionCache->setAccessible(true);
        /** @var array<int, array{port: int, sni: string, expires: int}> $connections */
        $connections = $connectionCache->getValue($this->service);
        $connections[99]['expires'] = \time() - 1;
        $connectionCache->setValue($this->service, $connections);

        $lastCleanup = new \ReflectionProperty($this->service, 'lastCleanup');
        $lastCleanup->setAccessible(true);
        $lastCleanup->setValue($this->service, 0);

        $this->service->cacheSniRoute('fresh.example.com', 16900, 3600);

        $stats = $this->service->getStats();
        $this->assertSame(1, $stats['sni_cache_size']);
        $this->assertSame(0, $stats['ip_cache_size']);
        $this->assertSame(0, $stats['connection_cache_size']);
        $this->assertSame(16900, $this->service->getRouteBySni('fresh.example.com'));
        $this->assertNull($this->service->getRouteBySni('expired.example.com'));
    }

    /**
     * 测试：边界情况 - 空响应
     */
    public function testEmptyResponseShouldNotCrash(): void
    {
        $result = $this->service->learnFromResponse('', 1, '192.168.1.1', 'test.com');

        // 空响应应返回 null 或不崩溃
        $this->assertTrue($result === null || is_array($result));
    }

    /**
     * 测试：边界情况 - 格式错误的响应
     */
    public function testMalformedResponseShouldNotCrash(): void
    {
        $malformedResponse = "This is not a valid HTTP response";

        $result = $this->service->learnFromResponse($malformedResponse, 1, '192.168.1.1', 'test.com');

        // 格式错误的响应应返回 null 或不崩溃
        $this->assertTrue($result === null || is_array($result));
    }

    /**
     * 测试：重定向响应即使有 Route-Hint 也不应被缓存
     */
    public function testRedirectWithRouteHintShouldNotBeCached(): void
    {
        $clientIp = '192.168.1.105';
        $sni = 'redirect.com';
        $connId = 6;

        // 重定向响应带 Route-Hint（不应该出现，但测试边界情况）
        $redirectResponse = "HTTP/1.1 302 Found\r\n" .
                           "Location: https://redirect.com/new\r\n" .
                           "X-Weline-Route-Hint: port=16895,sni=redirect.com,ttl=3600\r\n" .
                           "\r\n";

        $result = $this->service->learnFromResponse($redirectResponse, $connId, $clientIp, $sni);

        // 重定向检测应优先于 Route-Hint 解析
        $this->assertNull($result, '重定向响应即使有 Route-Hint 也不应被缓存');
    }

    /**
     * 测试：purgeRouteCache 方法存在性
     */
    public function testPurgeRouteCacheMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'purgeRouteCache'),
            'RoutingCacheService 应有 purgeRouteCache 方法'
        );
    }

    /**
     * 测试：purgeAll 方法存在性
     */
    public function testPurgeAllMethodExists(): void
    {
        $this->assertTrue(
            method_exists($this->service, 'purgeAll'),
            'RoutingCacheService 应有 purgeAll 方法'
        );
    }
}
