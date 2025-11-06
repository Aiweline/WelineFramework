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
     * 测试：确保Zone存在（接口测试）
     */
    public function testEnsureZoneInterface(): void
    {
        $this->assertTrue(method_exists($this->adapter, 'ensureZone'));
    }
}

