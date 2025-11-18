<?php

declare(strict_types=1);

namespace Weline\Visitor\test\Unit\Model;

use Weline\Framework\UnitTest\TestCore;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\Pixel;
use Weline\Visitor\Model\PixelAdditional;

/**
 * 像素附加数据模型单元测试
 */
class PixelAdditionalTest extends TestCore
{
    private PixelAdditional $pixelAdditional;
    private Pixel $pixel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pixelAdditional = ObjectManager::getInstance(PixelAdditional::class);
        $this->pixel = ObjectManager::getInstance(Pixel::class);
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        if ($this->pixelAdditional->getId()) {
            try {
                $this->pixelAdditional->delete();
            } catch (\Exception $e) {
                // 忽略删除错误
            }
        }
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
     * 测试：保存像素附加数据
     */
    public function testSavePixelAdditional()
    {
        // 先创建像素数据
        $this->pixel->setData([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => 1,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent'
        ])->save();
        
        $pixelId = $this->pixel->getId();
        $this->assertNotEmpty($pixelId, '像素ID应该不为空');

        // 创建附加数据
        $eventData = [
            'testId' => 'test_001',
            'variant' => 'A',
            'customField' => 'customValue'
        ];

        $this->pixelAdditional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData))
            ->save();

        $this->assertNotEmpty($this->pixelAdditional->getId(), '附加数据ID应该不为空');
        $this->assertEquals($pixelId, $this->pixelAdditional->getPixelId());
        
        $savedData = json_decode($this->pixelAdditional->getTotalEventData(), true);
        $this->assertEquals('test_001', $savedData['testId']);
        $this->assertEquals('A', $savedData['variant']);
    }

    /**
     * 测试：根据像素ID获取附加数据
     */
    public function testGetByPixelId()
    {
        // 创建像素和附加数据
        $this->pixel->setData([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => 1,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent'
        ])->save();
        
        $pixelId = $this->pixel->getId();
        
        $eventData = ['testId' => 'test_002', 'variant' => 'B'];
        $this->pixelAdditional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData))
            ->save();

        // 测试查询
        $found = PixelAdditional::getByPixelId($pixelId);
        
        $this->assertNotNull($found, '应该能找到附加数据');
        $this->assertEquals($pixelId, $found->getPixelId());
        
        $data = json_decode($found->getTotalEventData(), true);
        $this->assertEquals('test_002', $data['testId']);
    }

    /**
     * 测试：获取A/B测试数据
     */
    public function testGetAbTestDataByPixelId()
    {
        // 创建像素和附加数据
        $this->pixel->setData([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => 1,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent'
        ])->save();
        
        $pixelId = $this->pixel->getId();
        
        $eventData = [
            'testId' => 'ab_test_001',
            'variant' => 'A',
            'otherData' => 'ignored'
        ];
        $this->pixelAdditional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData))
            ->save();

        // 测试获取A/B测试数据
        $abTestData = PixelAdditional::getAbTestDataByPixelId($pixelId);
        
        $this->assertNotNull($abTestData, '应该能获取A/B测试数据');
        $this->assertArrayHasKey('testId', $abTestData);
        $this->assertArrayHasKey('variant', $abTestData);
        $this->assertEquals('ab_test_001', $abTestData['testId']);
        $this->assertEquals('A', $abTestData['variant']);
    }

    /**
     * 测试：获取事件数据（数组格式）
     */
    public function testGetEventDataByPixelId()
    {
        // 创建像素和附加数据
        $this->pixel->setData([
            'url' => 'https://example.com/test',
            'event' => 'click',
            'website_id' => 1,
            'ip' => '192.168.1.1',
            'user_agent' => 'Test Agent'
        ])->save();
        
        $pixelId = $this->pixel->getId();
        
        $eventData = [
            'customField1' => 'value1',
            'customField2' => 'value2',
            'nested' => ['key' => 'value']
        ];
        $this->pixelAdditional->setPixelId($pixelId)
            ->setTotalEventData(json_encode($eventData))
            ->save();

        // 测试获取事件数据
        $data = PixelAdditional::getEventDataByPixelId($pixelId);
        
        $this->assertNotNull($data, '应该能获取事件数据');
        $this->assertIsArray($data);
        $this->assertEquals('value1', $data['customField1']);
        $this->assertEquals('value2', $data['customField2']);
        $this->assertIsArray($data['nested']);
    }
}

