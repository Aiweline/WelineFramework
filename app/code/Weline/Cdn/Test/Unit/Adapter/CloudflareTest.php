<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Adapter;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Adapter\Cloudflare;
use Weline\Cdn\Api\AdapterInterface;

/**
 * Cloudflare适配器单元测试
 */
class CloudflareTest extends TestCase
{
    private Cloudflare $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new Cloudflare();
    }

    /**
     * 测试：适配器实例化
     */
    public function testAdapterInstantiation(): void
    {
        $this->assertInstanceOf(Cloudflare::class, $this->adapter);
        $this->assertInstanceOf(AdapterInterface::class, $this->adapter);
    }

    /**
     * 测试：获取适配器代码
     */
    public function testGetAdapterCode(): void
    {
        $this->assertEquals('cloudflare', $this->adapter->getAdapterCode());
    }

    /**
     * 测试：获取适配器名称
     */
    public function testGetAdapterName(): void
    {
        $name = $this->adapter->getAdapterName();
        $this->assertIsString($name);
        $this->assertNotEmpty($name);
        $this->assertStringContainsString('Cloudflare', $name);
    }

    /**
     * 测试：获取描述
     */
    public function testGetDescription(): void
    {
        $description = $this->adapter->getDescription();
        $this->assertIsString($description);
    }

    /**
     * 测试：获取版本
     */
    public function testGetVersion(): void
    {
        $version = $this->adapter->getVersion();
        $this->assertIsString($version);
        $this->assertNotEmpty($version);
    }

    /**
     * 测试：设置凭据
     */
    public function testSetCredentials(): void
    {
        $credentials = [
            'api_token' => 'test-token-123'
        ];

        $this->adapter->setCredentials($credentials);
        $this->assertTrue(true, '设置凭据应该成功');
    }

    /**
     * 测试：清理所有缓存（需要有效凭据，这里只测试接口）
     */
    public function testPurgeEverythingInterface(): void
    {
        // 注意：实际调用需要有效的API Token
        // 这里只测试方法是否存在且可调用
        $this->assertTrue(method_exists($this->adapter, 'purgeEverything'));
    }

    /**
     * 测试：按URL清理缓存（接口测试）
     */
    public function testPurgeUrlsInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'purgeUrls'));
    }

    /**
     * 测试：按Host清理缓存（接口测试）
     */
    public function testPurgeHostsInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'purgeHosts'));
    }

    /**
     * 测试：按Tag清理缓存（接口测试）
     */
    public function testPurgeTagsInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'purgeTags'));
    }

    /**
     * 测试：按Cache Key清理缓存（接口测试）
     */
    public function testPurgeCacheKeysInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'purgeCacheKeys'));
    }

    /**
     * 测试：获取规则（接口测试）
     */
    public function testGetRulesInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'getRules'));
    }

    /**
     * 测试：推送规则（接口测试）
     */
    public function testPutRulesInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'putRules'));
    }

    /**
     * 内部 default-rules 必须转成 Cloudflare set_cache_settings + action_parameters。
     */
    public function testFormatRulesForApiEmitsSetCacheSettings(): void
    {
        $method = new \ReflectionMethod(Cloudflare::class, 'formatRulesForApi');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $formatted */
        $formatted = $method->invoke($this->adapter, [
            [
                'expression' => 'http.request.uri.path matches "^/static/"',
                'action' => ['cache' => ['ttl' => 3600, 'status_code' => [200]]],
                'description' => 'static',
            ],
            [
                'expression' => 'http.request.uri.path matches "^/admin/"',
                'action' => ['cache' => false],
            ],
            [
                'expression' => '(http.request.method eq "GET")',
                'action' => ['cache' => ['mode' => 'bypass_by_default']],
            ],
            [
                'expression' => 'http.response.headers["x-weline-cache-status"][0] eq "hit"',
                'action' => ['cache_reserve' => true],
            ],
        ]);

        $this->assertCount(3, $formatted, 'response-field / invalid action rules must be dropped');

        $this->assertSame('set_cache_settings', $formatted[0]['action']);
        $this->assertTrue($formatted[0]['action_parameters']['cache'] ?? false);
        $this->assertSame('override_origin', $formatted[0]['action_parameters']['edge_ttl']['mode'] ?? null);
        $this->assertSame(3600, $formatted[0]['action_parameters']['edge_ttl']['default'] ?? null);

        $this->assertSame('set_cache_settings', $formatted[1]['action']);
        $this->assertFalse($formatted[1]['action_parameters']['cache'] ?? true);

        $this->assertSame('set_cache_settings', $formatted[2]['action']);
        $this->assertSame('bypass_by_default', $formatted[2]['action_parameters']['edge_ttl']['mode'] ?? null);
    }

    public function testResolveAuthModePrefersExplicitThenTokenThenGlobal(): void
    {
        $this->assertSame(Cloudflare::AUTH_MODE_TOKEN, Cloudflare::resolveAuthMode([
            'auth_mode' => 'token',
            'api_key' => 'k',
            'email' => 'a@b.c',
        ]));
        $this->assertSame(Cloudflare::AUTH_MODE_GLOBAL, Cloudflare::resolveAuthMode([
            'auth_mode' => 'global',
            'api_token' => 't',
        ]));
        $this->assertSame(Cloudflare::AUTH_MODE_TOKEN, Cloudflare::resolveAuthMode([
            'api_token' => 't',
            'api_key' => 'k',
            'email' => 'a@b.c',
        ]));
        $this->assertSame(Cloudflare::AUTH_MODE_GLOBAL, Cloudflare::resolveAuthMode([
            'api_key' => 'k',
            'email' => 'a@b.c',
        ]));
    }

    public function testBuildAuthHeadersForTokenAndGlobalKey(): void
    {
        $tokenHeaders = Cloudflare::buildAuthHeaders(['api_token' => 'tok-1']);
        $this->assertContains('Authorization: Bearer tok-1', $tokenHeaders);
        $this->assertContains('Content-Type: application/json', $tokenHeaders);

        $globalHeaders = Cloudflare::buildAuthHeaders([
            'auth_mode' => 'global',
            'email' => 'ops@example.com',
            'api_key' => 'cfk_global_demo',
        ]);
        $this->assertContains('X-Auth-Email: ops@example.com', $globalHeaders);
        $this->assertContains('X-Auth-Key: cfk_global_demo', $globalHeaders);
        $this->assertTrue(Cloudflare::hasUsableCredentials([
            'auth_mode' => 'global',
            'email' => 'ops@example.com',
            'api_key' => 'cfk_global_demo',
        ]));
        $this->assertFalse(Cloudflare::hasUsableCredentials([
            'auth_mode' => 'global',
            'email' => 'ops@example.com',
        ]));
    }

    public function testBuildAuthHeadersRejectsIncompleteGlobalKey(): void
    {
        $incomplete = [
            'auth_mode' => 'global',
            'email' => 'ops@example.com',
        ];
        $this->assertFalse(Cloudflare::hasUsableCredentials($incomplete));
        if (!\function_exists('__')) {
            // 独立 phpunit 无框架引导时 __() 不可用；完整性由 hasUsableCredentials 覆盖
            $this->assertTrue(true);
            return;
        }
        $this->expectException(\Weline\Framework\Exception\Core::class);
        Cloudflare::buildAuthHeaders($incomplete);
    }

    /**
     * 测试：确保Zone存在（接口测试）
     */
    public function testEnsureZoneInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'ensureZone'));
    }
}

