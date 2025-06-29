<?php

namespace Weline\DataTable\test;

use Weline\DataTable\Helper\TableContext;
use Weline\DataTable\Taglib\Table;
use Weline\DataTable\Taglib\TableHeader;
use Weline\DataTable\Taglib\TableFilter;
use Weline\Framework\UnitTest\TestCore;

/**
 * 表格属性继承测试类
 */
class TableInheritanceTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        // 清理之前的上下文
        $allContexts = TableContext::getAllTableContexts();
        foreach (array_keys($allContexts) as $scope) {
            TableContext::clearTableContext($scope);
        }
    }

    /**
     * 测试表格上下文设置和获取
     */
    public function testTableContextSetAndGet()
    {
        $scope = 'test-table';
        $attributes = [
            'model' => 'Weline\Test\Model\Test',
            'scope' => $scope,
            'searchable' => true,
            'sortable' => true,
            'editable' => false
        ];

        TableContext::setTableContext($scope, $attributes);
        $retrieved = TableContext::getTableContext($scope);

        $this->assertEquals($attributes, $retrieved);
    }

    /**
     * 测试属性继承功能
     */
    public function testAttributeInheritance()
    {
        // 设置父表格上下文
        $tableScope = 'demo-table';
        $tableAttributes = [
            'model' => 'Weline\Demo\Model\Demo',
            'scope' => $tableScope,
            'searchable' => true,
            'sortable' => true,
            'editable' => false
        ];
        TableContext::setTableContext($tableScope, $tableAttributes);

        // 测试子标签属性继承
        $childAttributes = [
            'sortable' => false // 覆盖继承的sortable
        ];

        $inherited = TableContext::inheritTableAttributes(
            $childAttributes, 
            '', // 空scope，应该找到父表格
            ['model', 'scope', 'searchable', 'sortable']
        );

        $this->assertEquals('Weline\Demo\Model\Demo', $inherited['model']);
        $this->assertEquals($tableScope . '-child', $inherited['scope']);
        $this->assertTrue($inherited['searchable']);
        $this->assertFalse($inherited['sortable']); // 应该被覆盖
    }

    /**
     * 测试TableHeader的属性继承
     */
    public function testTableHeaderInheritance()
    {
        // 设置父表格上下文
        $tableScope = 'demo-table';
        $tableAttributes = [
            'model' => 'Weline\Demo\Model\Demo',
            'scope' => $tableScope,
            'searchable' => true,
            'sortable' => true
        ];
        TableContext::setTableContext($tableScope, $tableAttributes);

        // 模拟TableHeader的callback调用
        $headerAttributes = [
            'draggable' => false
        ];

        $inherited = TableContext::inheritTableAttributes(
            $headerAttributes, 
            '', 
            ['model', 'scope', 'sortable']
        );

        $this->assertEquals('Weline\Demo\Model\Demo', $inherited['model']);
        $this->assertEquals($tableScope . '-header', $inherited['scope']);
        $this->assertTrue($inherited['sortable']);
        $this->assertFalse($inherited['draggable']);
    }

    /**
     * 测试TableFilter的属性继承
     */
    public function testTableFilterInheritance()
    {
        // 设置父表格上下文
        $tableScope = 'demo-table';
        $tableAttributes = [
            'model' => 'Weline\Demo\Model\Demo',
            'scope' => $tableScope,
            'searchable' => true,
            'sortable' => true
        ];
        TableContext::setTableContext($tableScope, $tableAttributes);

        // 模拟TableFilter的callback调用
        $filterAttributes = [
            'advanced' => true
        ];

        $inherited = TableContext::inheritTableAttributes(
            $filterAttributes, 
            '', 
            ['model', 'scope', 'searchable']
        );

        $this->assertEquals('Weline\Demo\Model\Demo', $inherited['model']);
        $this->assertEquals($tableScope . '-filter', $inherited['scope']);
        $this->assertTrue($inherited['searchable']);
        $this->assertTrue($inherited['advanced']);
    }

    /**
     * 测试必需属性验证
     */
    public function testRequiredAttributesValidation()
    {
        $this->expectException(\Weline\Framework\App\Exception::class);
        
        TableContext::validateRequiredAttributes(
            ['model' => '', 'scope' => ''], 
            ['model', 'scope'], 
            'TestTag'
        );
    }

    /**
     * 测试上下文清理
     */
    public function testContextCleanup()
    {
        $scope = 'test-cleanup';
        $attributes = ['model' => 'Test\Model', 'scope' => $scope];
        
        TableContext::setTableContext($scope, $attributes);
        $this->assertNotNull(TableContext::getTableContext($scope));
        
        TableContext::clearTableContext($scope);
        $this->assertNull(TableContext::getTableContext($scope));
    }

    protected function tearDown(): void
    {
        // 清理测试上下文
        $allContexts = TableContext::getAllTableContexts();
        foreach (array_keys($allContexts) as $scope) {
            TableContext::clearTableContext($scope);
        }
        parent::tearDown();
    }
} 