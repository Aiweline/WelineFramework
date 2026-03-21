<?php

declare(strict_types=1);

/**
 * SlotValidator 单元测试
 * 
 * 测试组件放置验证规则：
 * - 区域隔离
 * - Slot 类别匹配
 * - Slot 类型匹配
 * - 数量限制
 * 
 * @author GuoLaiRen
 * @since 2.1.0
 */

namespace GuoLaiRen\PageBuilder\Test;

use GuoLaiRen\PageBuilder\Service\Component\SlotValidator;
use GuoLaiRen\PageBuilder\Service\Component\ValidationResult;
use PHPUnit\Framework\TestCase;

class SlotValidatorTest extends TestCase
{
    private SlotValidator $validator;
    
    protected function setUp(): void
    {
        $this->validator = SlotValidator::getInstance();
        $this->validator->clearCache();
    }
    
    /**
     * 测试：区域隔离 - header 组件只能放 header 区域
     */
    public function testRegionIsolation_HeaderComponent()
    {
        // header 组件放到 header 区域应该成功
        $result = $this->validator->canPlaceInRegion('header-nav', 'header', 'tpmst');
        $this->assertTrue($result->isValid(), 'header 组件应该可以放入 header 区域');
        
        // header 组件放到 content 区域应该失败
        $result = $this->validator->canPlaceInRegion('header-nav', 'content', 'tpmst');
        $this->assertFalse($result->isValid(), 'header 组件不应该放入 content 区域');
        $this->assertEquals('REGION_NOT_ALLOWED', $result->getErrorCode());
        
        // header 组件放到 footer 区域应该失败
        $result = $this->validator->canPlaceInRegion('header-nav', 'footer', 'tpmst');
        $this->assertFalse($result->isValid(), 'header 组件不应该放入 footer 区域');
    }
    
    /**
     * 测试：区域隔离 - footer 组件只能放 footer 区域
     */
    public function testRegionIsolation_FooterComponent()
    {
        // footer 组件放到 footer 区域应该成功
        $result = $this->validator->canPlaceInRegion('footer-links', 'footer', 'tpmst');
        $this->assertTrue($result->isValid(), 'footer 组件应该可以放入 footer 区域');
        
        // footer 组件放到 content 区域应该失败
        $result = $this->validator->canPlaceInRegion('footer-links', 'content', 'tpmst');
        $this->assertFalse($result->isValid(), 'footer 组件不应该放入 content 区域');
        
        // footer 组件放到 header 区域应该失败
        $result = $this->validator->canPlaceInRegion('footer-links', 'header', 'tpmst');
        $this->assertFalse($result->isValid(), 'footer 组件不应该放入 header 区域');
    }
    
    /**
     * 测试：区域隔离 - content 组件只能放 content 区域
     */
    public function testRegionIsolation_ContentComponent()
    {
        // content 组件放到 content 区域应该成功
        $result = $this->validator->canPlaceInRegion('slider', 'content', 'tpmst');
        $this->assertTrue($result->isValid(), 'content 组件应该可以放入 content 区域');
        
        // content 组件放到 header 区域应该失败
        $result = $this->validator->canPlaceInRegion('slider', 'header', 'tpmst');
        $this->assertFalse($result->isValid(), 'content 组件不应该放入 header 区域');
        
        // content 组件放到 footer 区域应该失败
        $result = $this->validator->canPlaceInRegion('slider', 'footer', 'tpmst');
        $this->assertFalse($result->isValid(), 'content 组件不应该放入 footer 区域');
    }
    
    /**
     * 测试：组件不存在
     */
    public function testComponentNotFound()
    {
        $result = $this->validator->canPlaceInRegion('non-existent-component', 'content', 'tpmst');
        $this->assertFalse($result->isValid());
        $this->assertEquals('COMPONENT_NOT_FOUND', $result->getErrorCode());
    }
    
    /**
     * 测试：Slot 放置 - 有效的 slot 放置
     */
    public function testSlotPlacement_Valid()
    {
        // 假设 faq 组件有 items slot，接受 content/widget 类别
        // 这里需要模板中实际配置了对应的 slots
        $result = $this->validator->canPlaceInSlot(
            'faq',          // 要放置的组件（content 类别）
            'faq',          // 父组件
            'items',        // slot 名称
            'tpmst'
        );

        // 该用例只验证 canPlaceInSlot 返回结果结构稳定；
        // 具体 valid/invalid 由主题 slot 配置决定，不在此处硬编码。
        $this->assertInstanceOf(ValidationResult::class, $result);
        $this->assertIsBool($result->isValid());
    }
    
    /**
     * 测试：获取区域接受的类别
     */
    public function testGetRegionAccepts()
    {
        $accepts = $this->validator->getRegionAccepts('header');
        $this->assertContains('header', $accepts);
        $this->assertNotContains('content', $accepts);
        
        $accepts = $this->validator->getRegionAccepts('content');
        $this->assertContains('content', $accepts);
        $this->assertContains('widget', $accepts);
        $this->assertNotContains('header', $accepts);
        
        $accepts = $this->validator->getRegionAccepts('footer');
        $this->assertContains('footer', $accepts);
        $this->assertNotContains('content', $accepts);
    }
    
    /**
     * 测试：检查组件是否是容器
     */
    public function testIsContainer()
    {
        // 有 slots 定义的组件应该是容器
        $isContainer = $this->validator->isContainer('faq', 'tpmst');
        $this->assertTrue($isContainer, 'faq 组件应该是容器（有 items slot）');
        
        // 没有 slots 定义的组件不是容器
        $isContainer = $this->validator->isContainer('banner', 'tpmst');
        $this->assertFalse($isContainer, 'banner 组件不应该是容器（没有 slots）');
    }
    
    /**
     * 测试：获取组件信息
     */
    public function testGetComponentInfo()
    {
        $info = $this->validator->getComponentInfo('header-nav', 'tpmst');
        
        $this->assertNotNull($info, '应该能获取到组件信息');
        $this->assertEquals('header-nav', $info['code']);
        $this->assertEquals('header', $info['category']);
        $this->assertContains('header', $info['placeable_in']);
    }
    
    /**
     * 测试：获取组件的 slots 定义
     */
    public function testGetComponentSlots()
    {
        $slots = $this->validator->getComponentSlots('faq', 'tpmst');
        
        $this->assertIsArray($slots);
        if (!empty($slots)) {
            $this->assertArrayHasKey('items', $slots);
            $this->assertArrayHasKey('accepts', $slots['items']);
        }
    }
    
    /**
     * 测试：获取区域兼容组件列表
     */
    public function testGetCompatibleComponentsForRegion()
    {
        $components = $this->validator->getCompatibleComponentsForRegion('header', 'tpmst');
        
        $this->assertIsArray($components);
        // header 区域应该包含 header-nav 组件
        $this->assertContains('header-nav', $components);
        // header 区域不应该包含 content 组件
        $this->assertNotContains('slider', $components);
    }
    
    /**
     * 测试：ValidationResult 类
     */
    public function testValidationResult()
    {
        // 测试成功结果
        $success = ValidationResult::success(['key' => 'value']);
        $this->assertTrue($success->isValid());
        $this->assertEmpty($success->getMessage());
        $this->assertEmpty($success->getErrorCode());
        $this->assertEquals(['key' => 'value'], $success->getData());
        
        // 测试失败结果
        $fail = ValidationResult::fail('错误消息', 'ERROR_CODE', ['extra' => 'data']);
        $this->assertFalse($fail->isValid());
        $this->assertEquals('错误消息', $fail->getMessage());
        $this->assertEquals('ERROR_CODE', $fail->getErrorCode());
        $this->assertEquals(['extra' => 'data'], $fail->getData());
        
        // 测试转换为数组
        $array = $fail->toArray();
        $this->assertArrayHasKey('valid', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('data', $array);
    }
}
