<?php

declare(strict_types=1);

/**
 * ComponentRenderer 单元测试
 * 
 * 测试单组件渲染功能：
 * - 单组件渲染
 * - 可视化模式包装器
 * - 占位符生成
 * 
 * @author GuoLaiRen
 * @since 2.1.0
 */

namespace GuoLaiRen\PageBuilder\Test;

use GuoLaiRen\PageBuilder\Service\Component\ComponentRenderer;
use GuoLaiRen\PageBuilder\Service\Component\RenderResult;
use PHPUnit\Framework\TestCase;

class ComponentRendererTest extends TestCase
{
    private ComponentRenderer $renderer;
    
    protected function setUp(): void
    {
        $this->renderer = ComponentRenderer::getInstance();
        $this->renderer->clearCache();
    }
    
    /**
     * 测试：RenderResult 成功结果
     */
    public function testRenderResult_Success()
    {
        $result = RenderResult::success('<div>HTML</div>', ['instance_id' => 'test-123']);
        
        $this->assertTrue($result->isSuccess());
        $this->assertEquals('<div>HTML</div>', $result->getHtml());
        $this->assertEmpty($result->getMessage());
        $this->assertEmpty($result->getErrorCode());
        $this->assertEquals(['instance_id' => 'test-123'], $result->getData());
    }
    
    /**
     * 测试：RenderResult 失败结果
     */
    public function testRenderResult_Fail()
    {
        $result = RenderResult::fail('渲染失败', 'RENDER_ERROR', ['reason' => 'test']);
        
        $this->assertFalse($result->isSuccess());
        $this->assertEmpty($result->getHtml());
        $this->assertEquals('渲染失败', $result->getMessage());
        $this->assertEquals('RENDER_ERROR', $result->getErrorCode());
        $this->assertEquals(['reason' => 'test'], $result->getData());
    }
    
    /**
     * 测试：RenderResult 转换为数组
     */
    public function testRenderResult_ToArray()
    {
        $result = RenderResult::success('<div>Test</div>', ['id' => '123']);
        $array = $result->toArray();
        
        $this->assertArrayHasKey('success', $array);
        $this->assertArrayHasKey('html', $array);
        $this->assertArrayHasKey('message', $array);
        $this->assertArrayHasKey('error_code', $array);
        $this->assertArrayHasKey('data', $array);
        
        $this->assertTrue($array['success']);
        $this->assertEquals('<div>Test</div>', $array['html']);
    }
    
    /**
     * 测试：生成占位符
     */
    public function testGeneratePlaceholder()
    {
        $placeholder = $this->renderer->generatePlaceholder('test-instance', 'content');
        
        $this->assertStringContainsString('vb-component-loading', $placeholder);
        $this->assertStringContainsString('test-instance', $placeholder);
        $this->assertStringContainsString('content', $placeholder);
        $this->assertStringContainsString('加载中', $placeholder);
    }
    
    /**
     * 测试：渲染不存在的组件
     */
    public function testRenderSingle_ComponentNotFound()
    {
        $result = $this->renderer->renderSingle(
            'non-existent-component',
            'test-instance',
            'tpmst',
            [],
            []
        );
        
        $this->assertFalse($result->isSuccess());
        $this->assertEquals('TEMPLATE_NOT_FOUND', $result->getErrorCode());
    }
    
    /**
     * 测试：批量渲染
     */
    public function testRenderBatch()
    {
        $components = [
            ['code' => 'slider', 'instance_id' => 'id-1', 'config' => []],
            ['code' => 'faq', 'instance_id' => 'id-2', 'config' => []],
        ];
        
        $results = $this->renderer->renderBatch($components, 'tpmst', ['visual_mode' => false]);
        
        $this->assertIsArray($results);
        $this->assertArrayHasKey('id-1', $results);
        $this->assertArrayHasKey('id-2', $results);
        
        foreach ($results as $instanceId => $result) {
            $this->assertInstanceOf(RenderResult::class, $result);
        }
    }
    
    /**
     * 测试：预览渲染
     */
    public function testRenderPreview()
    {
        $result = $this->renderer->renderPreview('slider', 'tpmst', []);
        
        $this->assertInstanceOf(RenderResult::class, $result);
        // 预览渲染可能成功也可能失败（取决于组件是否存在）
        // 但应该返回有效的 RenderResult 对象
    }
}
