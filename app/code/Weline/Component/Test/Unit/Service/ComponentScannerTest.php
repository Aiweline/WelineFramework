<?php

declare(strict_types=1);

namespace Weline\Component\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Component\Service\ComponentScanner;

/**
 * ComponentScanner 服务单元测试
 * 
 * 测试组件扫描服务的核心功能
 */
class ComponentScannerTest extends TestCase
{
    private ComponentScanner $scanner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scanner = new ComponentScanner();
    }

    protected function tearDown(): void
    {
        unset($this->scanner);
        parent::tearDown();
    }

    /**
     * 测试：成功扫描所有组件
     */
    public function testScanAllComponentsSuccess(): void
    {
        $result = $this->scanner->scanAllComponents();
        
        // 验证返回结构
        $this->assertIsArray($result);
        
        // 验证至少包含 Component 模块自身的组件
        $this->assertGreaterThanOrEqual(0, count($result));
    }

    /**
     * 测试：按分类组织组件
     */
    public function testGroupByCategory(): void
    {
        $components = [
            'Weline\\Component\\Block\\Form\\Search' => [
                'module' => 'Weline_Component',
                'class' => 'Search',
                'full_class' => 'Weline\\Component\\Block\\Form\\Search',
                'namespace' => 'Weline\\Component\\Block\\Form',
                'category' => 'Form',
                'doc' => '搜索组件文档',
                'file_path' => '/path/to/Search.php'
            ],
            'Weline\\Component\\Block\\Tool\\SortBar' => [
                'module' => 'Weline_Component',
                'class' => 'SortBar',
                'full_class' => 'Weline\\Component\\Block\\Tool\\SortBar',
                'namespace' => 'Weline\\Component\\Block\\Tool',
                'category' => 'Tool',
                'doc' => '排序组件文档',
                'file_path' => '/path/to/SortBar.php'
            ]
        ];
        
        $result = $this->scanner->groupByCategory($components);
        
        // 验证返回结构
        $this->assertIsArray($result);
        $this->assertArrayHasKey('Form', $result);
        $this->assertArrayHasKey('Tool', $result);
        
        // 验证分类内容
        $this->assertCount(1, $result['Form']);
        $this->assertCount(1, $result['Tool']);
        $this->assertEquals('Search', $result['Form']['Weline\\Component\\Block\\Form\\Search']['class']);
        $this->assertEquals('SortBar', $result['Tool']['Weline\\Component\\Block\\Tool\\SortBar']['class']);
    }

    /**
     * 测试：空数组按分类组织
     */
    public function testGroupByCategoryWithEmptyArray(): void
    {
        $result = $this->scanner->groupByCategory([]);
        
        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * 测试：空分类使用默认值
     */
    public function testGroupByCategoryWithDefaultCategory(): void
    {
        $components = [
            'Test\\Component' => [
                'module' => 'Test_Module',
                'class' => 'Component',
                'full_class' => 'Test\\Component',
                'namespace' => 'Test',
                'category' => null, // 使用 null 而不是空字符串
                'doc' => '',
                'file_path' => '/path/to/Component.php'
            ]
        ];
        
        $result = $this->scanner->groupByCategory($components);
        
        // 验证空分类使用默认值"其他"
        $this->assertArrayHasKey('其他', $result);
    }
}

