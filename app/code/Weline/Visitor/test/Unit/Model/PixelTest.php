<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;

/**
 * 像素模型单元测试
 */
class PixelTest extends TestCore
{
    private Pixel $pixel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pixel = ObjectManager::getInstance(Pixel::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->pixel->getId()) {
            try {
                $this->pixel->delete();
            } catch (\Exception $e) {
                // 忽略删除错误
            }
        }
        parent::tearDown();
    }

    /**
     * 测试：保存像素数据
     */
    public function testSavePixelData()
    {
        $data = [
            'url' => 'https://example.com/test',
            'module' => 'Weline_Frontend',
            'name' => 'test_event',
            'event' => 'click',
            'value' => 100,
            'lang' => 'zh-CN',
            'currency' => 'RMB',
            'website_id' => 1,
            'referer' => 'https://example.com',
            'user_id' => 123,
            'user_agent' => 'Mozilla/5.0 Test',
            'ip' => '192.168.1.1',
            'browser_info' => json_encode(['test' => 'data'])
        ];

        $this->pixel->setData($data)->save();
        
        $this->assertNotEmpty($this->pixel->getId(), '像素ID应该不为空');
        $this->assertEquals('https://example.com/test', $this->pixel->getUrl());
        $this->assertEquals('click', $this->pixel->getEvent());
        $this->assertEquals(100, $this->pixel->getValue());
        $this->assertEquals(1, $this->pixel->getWebsiteId());
    }

    /**
     * 测试：根据站点ID获取像素数据
     */
    public function testGetPixelsByWebsiteId()
    {
        // 创建测试数据
        $websiteId = 999;
        $data = [
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => $websiteId,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent'
        ];
        
        $pixel = ObjectManager::getInstance(Pixel::class);
        $pixel->setData($data)->save();
        $pixelId = $pixel->getId();

        // 测试查询
        $pixels = Pixel::getPixelsByWebsiteId($websiteId);
        
        $this->assertIsArray($pixels);
        $found = false;
        foreach ($pixels as $p) {
            if ($p->getId() == $pixelId) {
                $found = true;
                $this->assertEquals($websiteId, $p->getWebsiteId());
                break;
            }
        }
        $this->assertTrue($found, '应该能找到创建的像素数据');

        // 清理
        $pixel->load($pixelId)->delete();
    }

    /**
     * 测试：统计站点像素数量
     */
    public function testCountPixelsByWebsiteId()
    {
        $websiteId = 998;
        
        // 创建测试数据
        $pixel = ObjectManager::getInstance(Pixel::class);
        $pixel->setData([
            'url' => 'https://example.com/test1',
            'event' => 'click',
            'website_id' => $websiteId,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent'
        ])->save();
        $pixelId1 = $pixel->getId();

        $pixel2 = ObjectManager::getInstance(Pixel::class);
        $pixel2->setData([
            'url' => 'https://example.com/test2',
            'event' => 'view',
            'website_id' => $websiteId,
            'ip' => '192.168.1.2',
            'user_agent' => 'Test Agent'
        ])->save();
        $pixelId2 = $pixel2->getId();

        // 测试统计
        $count = Pixel::countPixelsByWebsiteId($websiteId);
        
        $this->assertGreaterThanOrEqual(2, $count, '站点像素数量应该至少为2');

        // 清理
        $pixel->load($pixelId1)->delete();
        $pixel2->load($pixelId2)->delete();
    }

    /**
     * 测试：获取站点摘要信息
     */
    public function testGetWebsiteSummary()
    {
        $websiteId = 997;
        
        // 创建测试数据
        $pixel = ObjectManager::getInstance(Pixel::class);
        $pixel->setData([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => $websiteId,
            'value' => 100,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent'
        ])->save();
        $pixelId = $pixel->getId();

        // 测试摘要
        $summary = Pixel::getWebsiteSummary($websiteId);
        
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('total_count', $summary);
        $this->assertArrayHasKey('un_deal_count', $summary);
        $this->assertArrayHasKey('event_counts', $summary);
        $this->assertGreaterThanOrEqual(1, $summary['total_count']);

        // 清理
        $pixel->load($pixelId)->delete();
    }

    /**
     * 测试：IP地址正确获取和保存
     */
    public function testIpAddressCollection()
    {
        $testIps = [
            '192.168.1.1',
            '10.0.0.1',
            '172.16.0.1',
            '2001:0db8:85a3:0000:0000:8a2e:0370:7334', // IPv6
        ];

        foreach ($testIps as $testIp) {
            $pixel = ObjectManager::getInstance(Pixel::class);
            $pixel->setData([
                'url' => 'https://example.com/test',
                'event' => 'click',
                'website_id' => 1,
                'ip' => $testIp,
                'user_agent' => 'Test Agent'
            ])->save();
            $pixelId = $pixel->getId();

            $this->assertEquals($testIp, $pixel->getIp(), "IP地址 {$testIp} 应该正确保存");

            // 清理
            $pixel->load($pixelId)->delete();
        }
    }

    /**
     * 测试：浏览器、语言、网站ID、货币信息收集
     */
    public function testBrowserLanguageWebsiteCurrencyCollection()
    {
        $testData = [
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => 888,
            'ip' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'lang' => 'zh-CN',
            'currency' => 'RMB',
            'browser_info' => json_encode([
                'browser' => 'Chrome',
                'version' => '91.0.4472.124',
                'os' => 'Windows 10'
            ])
        ];

        $this->pixel->setData($testData)->save();
        $pixelId = $this->pixel->getId();

        // 验证浏览器信息
        $this->assertNotEmpty($this->pixel->getUserAgent(), '用户代理应该不为空');
        $this->assertStringContainsString('Chrome', $this->pixel->getUserAgent(), '应该包含浏览器信息');

        // 验证语言
        $this->assertEquals('zh-CN', $this->pixel->getLang(), '语言应该正确保存');

        // 验证网站ID
        $this->assertEquals(888, $this->pixel->getWebsiteId(), '网站ID应该正确保存');

        // 验证货币
        $this->assertEquals('RMB', $this->pixel->getCurrency(), '货币应该正确保存');

        // 验证浏览器详细信息
        $browserInfo = json_decode($this->pixel->getBrowserInfo(), true);
        $this->assertIsArray($browserInfo, '浏览器信息应该是数组');
        $this->assertEquals('Chrome', $browserInfo['browser'], '浏览器名称应该正确');

        // 清理
        $this->pixel->load($pixelId)->delete();
    }

    /**
     * 测试：像素数据正常收集和保存（完整数据）
     */
    public function testCompletePixelDataCollection()
    {
        $completeData = [
            'url' => 'https://example.com/product/123',
            'module' => 'Weline_Frontend',
            'name' => 'product_view',
            'event' => 'view',
            'value' => 299.99,
            'lang' => 'en-US',
            'currency' => 'USD',
            'website_id' => 777,
            'referer' => 'https://example.com/search?q=product',
            'user_id' => 456,
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
            'ip' => '203.0.113.1',
            'browser_info' => json_encode([
                'browser' => 'Safari',
                'version' => '14.1',
                'os' => 'macOS',
                'screen' => ['width' => 1920, 'height' => 1080]
            ])
        ];

        $this->pixel->setData($completeData)->save();
        $pixelId = $this->pixel->getId();

        // 验证所有字段
        $this->assertEquals($completeData['url'], $this->pixel->getUrl());
        $this->assertEquals($completeData['module'], $this->pixel->getModule());
        $this->assertEquals($completeData['name'], $this->pixel->getName());
        $this->assertEquals($completeData['event'], $this->pixel->getEvent());
        $this->assertEquals($completeData['value'], $this->pixel->getValue());
        $this->assertEquals($completeData['lang'], $this->pixel->getLang());
        $this->assertEquals($completeData['currency'], $this->pixel->getCurrency());
        $this->assertEquals($completeData['website_id'], $this->pixel->getWebsiteId());
        $this->assertEquals($completeData['referer'], $this->pixel->getReferer());
        $this->assertEquals($completeData['user_id'], $this->pixel->getUserId());
        $this->assertEquals($completeData['user_agent'], $this->pixel->getUserAgent());
        $this->assertEquals($completeData['ip'], $this->pixel->getIp());

        // 清理
        $this->pixel->load($pixelId)->delete();
    }
}

