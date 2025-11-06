<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\WarmupProviderScanner;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\File\Scan;

/**
 * WarmupProviderScanner服务单元测试
 */
class WarmupProviderScannerTest extends TestCase
{
    private WarmupProviderScanner $scanner;
    private Scan $fileScanner;
    private ObjectManager $objectManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fileScanner = ObjectManager::getInstance(Scan::class);
        $this->objectManager = ObjectManager::getInstance();
        $this->scanner = new WarmupProviderScanner($this->fileScanner, $this->objectManager);
    }

    /**
     * 测试：服务实例化
     */
    public function testServiceInstantiation(): void
    {
        $this->assertInstanceOf(WarmupProviderScanner::class, $this->scanner);
    }

    /**
     * 测试：扫描Provider
     */
    public function testScanProviders(): void
    {
        $providers = $this->scanner->scanProviders();
        
        $this->assertIsArray($providers);
        // 验证返回的是类名数组
        foreach ($providers as $provider) {
            $this->assertIsString($provider);
        }
    }

    /**
     * 测试：收集URL
     */
    public function testCollectUrls(): void
    {
        $urls = $this->scanner->collectUrls();
        
        $this->assertIsArray($urls);
        // URL应该是数组格式
        foreach ($urls as $url) {
            // 如果URL是字符串，验证格式
            if (is_string($url)) {
                $this->assertNotEmpty($url);
            }
            // 如果URL是数组，验证结构
            if (is_array($url)) {
                $this->assertArrayHasKey('url', $url);
            }
        }
    }

    /**
     * 测试：扫描Provider（无Provider时）
     */
    public function testScanProvidersNoProviders(): void
    {
        // 如果当前环境没有Provider，应该返回空数组
        $providers = $this->scanner->scanProviders();
        $this->assertIsArray($providers);
    }

    /**
     * 测试：收集URL（无Provider时）
     */
    public function testCollectUrlsNoProviders(): void
    {
        // 如果没有Provider，应该返回空数组
        $urls = $this->scanner->collectUrls();
        $this->assertIsArray($urls);
    }

    /**
     * 测试：异常处理
     */
    public function testExceptionHandling(): void
    {
        // 验证异常处理不会导致程序崩溃
        try {
            $providers = $this->scanner->scanProviders();
            $this->assertIsArray($providers);
        } catch (\Exception $e) {
            // 如果抛出异常，应该被捕获并记录
            $this->fail('扫描Provider时不应该抛出未捕获的异常');
        }
    }

    /**
     * 测试：Provider类名解析
     */
    public function testClassNameResolution(): void
    {
        // 验证类名解析逻辑
        // 实际测试需要mock文件系统或使用集成测试
        $this->markTestSkipped('需要mock文件系统或使用集成测试');
    }

    /**
     * 测试：Provider执行
     */
    public function testProviderExecution(): void
    {
        // 验证Provider的execute方法调用
        // 实际测试需要mock Provider类或使用集成测试
        $this->markTestSkipped('需要mock Provider类或使用集成测试');
    }
}

