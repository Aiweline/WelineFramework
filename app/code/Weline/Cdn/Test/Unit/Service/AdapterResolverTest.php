<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Api\AdapterInterface;
use Weline\Cdn\Service\AdapterResolver;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * AdapterResolver服务单元测试
 */
class AdapterResolverTest extends TestCase
{
    private AdapterResolver $resolver;
    private Scan $fileScanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileScanner = ObjectManager::getInstance(Scan::class);
        $objectManager = ObjectManager::getInstance();
        $this->resolver = new AdapterResolver($this->fileScanner, $objectManager);
    }

    /**
     * 测试：服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(AdapterResolver::class, $this->resolver);
    }

    /**
     * 测试：获取所有适配器（至少包含Cloudflare）
     */
    public function testGetAllAdapters(): void
    {
        $adapters = $this->resolver->getAllAdapters();

        $this->assertIsArray($adapters);
        $this->assertNotEmpty($adapters, '应该至少包含Cloudflare适配器');
        
        // 验证Cloudflare适配器存在
        $this->assertArrayHasKey('cloudflare', $adapters, '应该包含Cloudflare适配器');
        
        // 验证适配器实现了接口
        $cloudflareAdapter = $adapters['cloudflare'];
        $this->assertInstanceOf(AdapterInterface::class, $cloudflareAdapter);
    }

    /**
     * 测试：获取Cloudflare适配器
     */
    public function testGetCloudflareAdapter(): void
    {
        $adapter = $this->resolver->getAdapter('cloudflare');

        $this->assertNotNull($adapter, 'Cloudflare适配器应该存在');
        $this->assertInstanceOf(AdapterInterface::class, $adapter);
        $this->assertEquals('cloudflare', $adapter->getAdapterCode());
    }

    /**
     * 测试：获取不存在的适配器
     */
    public function testGetNonExistentAdapter(): void
    {
        $adapter = $this->resolver->getAdapter('non_existent_adapter');

        $this->assertNull($adapter, '不存在的适配器应该返回null');
    }

    /**
     * 测试：适配器代码验证
     */
    public function testAdapterCodeValidation(): void
    {
        $adapters = $this->resolver->getAllAdapters();

        foreach ($adapters as $code => $adapter) {
            $this->assertIsString($code, '适配器代码应该是字符串');
            $this->assertNotEmpty($code, '适配器代码不应为空');
            $this->assertInstanceOf(AdapterInterface::class, $adapter, "适配器 {$code} 应该实现 AdapterInterface");
            $this->assertEquals($code, $adapter->getAdapterCode(), "适配器代码应该匹配");
        }
    }

    /**
     * 测试：适配器名称获取
     */
    public function testAdapterName(): void
    {
        $adapter = $this->resolver->getAdapter('cloudflare');

        if ($adapter) {
            $name = $adapter->getAdapterName();
            $this->assertIsString($name);
            $this->assertNotEmpty($name);
        }
    }

    /**
     * 测试：适配器描述获取
     */
    public function testAdapterDescription(): void
    {
        $adapter = $this->resolver->getAdapter('cloudflare');

        if ($adapter) {
            $description = $adapter->getDescription();
            $this->assertIsString($description);
        }
    }

    /**
     * 测试：适配器版本获取
     */
    public function testAdapterVersion(): void
    {
        $adapter = $this->resolver->getAdapter('cloudflare');

        if ($adapter) {
            $version = $adapter->getVersion();
            $this->assertIsString($version);
            $this->assertNotEmpty($version);
        }
    }
}

