<?php

namespace Weline\DataTable\test;

use Weline\DataTable\Helper\TableContext;

/**
 * TableContext测试类
 * 用于验证表格上下文的渲染栈功能
 */
class TableContextTest
{
    /**
     * 测试渲染栈功能
     */
    public static function testRenderStack(): void
    {
        echo "=== TableContext 渲染栈测试 ===\n";
        
        // 清空之前的上下文
        TableContext::clearRenderStack();
        
        // 模拟d-table标签渲染
        echo "1. 渲染d-table标签...\n";
        $tableAttributes = [
            'model' => 'Weline\Demo\Model\Demo',
            'scope' => 'demo-table',
            'editable' => true,
            'searchable' => true
        ];
        TableContext::setTableContext('demo-table', $tableAttributes);
        echo "   当前渲染栈: " . json_encode(TableContext::getRenderStack()) . "\n";
        
        // 模拟t-header标签渲染
        echo "2. 渲染t-header标签...\n";
        $headerAttributes = [
            'scope' => 'demo-table-header',
            'sortable' => true
        ];
        TableContext::pushChildTag('header', 'demo-table-header', $headerAttributes);
        echo "   当前渲染栈: " . json_encode(TableContext::getRenderStack()) . "\n";
        
        // 测试继承功能
        echo "3. 测试属性继承...\n";
        $inheritedAttributes = TableContext::inheritTableAttributes(
            $headerAttributes, 
            'demo-table-header', 
            ['model', 'scope', 'sortable']
        );
        echo "   继承后的属性: " . json_encode($inheritedAttributes) . "\n";
        
        // 模拟t-header标签渲染结束
        echo "4. t-header标签渲染结束...\n";
        TableContext::popTag();
        echo "   当前渲染栈: " . json_encode(TableContext::getRenderStack()) . "\n";
        
        // 模拟t-filter标签渲染
        echo "5. 渲染t-filter标签...\n";
        $filterAttributes = [
            'scope' => 'demo-table-filter',
            'searchable' => true
        ];
        TableContext::pushChildTag('filter', 'demo-table-filter', $filterAttributes);
        echo "   当前渲染栈: " . json_encode(TableContext::getRenderStack()) . "\n";
        
        // 测试继承功能
        echo "6. 测试属性继承...\n";
        $inheritedAttributes = TableContext::inheritTableAttributes(
            $filterAttributes, 
            'demo-table-filter', 
            ['model', 'scope', 'searchable']
        );
        echo "   继承后的属性: " . json_encode($inheritedAttributes) . "\n";
        
        // 模拟t-filter标签渲染结束
        echo "7. t-filter标签渲染结束...\n";
        TableContext::popTag();
        echo "   当前渲染栈: " . json_encode(TableContext::getRenderStack()) . "\n";
        
        // 模拟d-table标签渲染结束
        echo "8. d-table标签渲染结束...\n";
        TableContext::popTag();
        echo "   当前渲染栈: " . json_encode(TableContext::getRenderStack()) . "\n";
        
        echo "=== 测试完成 ===\n";
    }
    
    /**
     * 测试多表格嵌套场景
     */
    public static function testNestedTables(): void
    {
        echo "\n=== 多表格嵌套测试 ===\n";
        
        // 清空之前的上下文
        TableContext::clearRenderStack();
        
        // 第一个表格
        echo "1. 渲染第一个d-table...\n";
        TableContext::setTableContext('table1', [
            'model' => 'Weline\Demo\Model\Demo1',
            'scope' => 'table1',
            'editable' => true
        ]);
        
        // 第一个表格的t-header
        echo "2. 渲染第一个表格的t-header...\n";
        TableContext::pushChildTag('header', 'table1-header', []);
        $inherited1 = TableContext::inheritTableAttributes([], 'table1-header');
        echo "   继承的属性: " . json_encode($inherited1) . "\n";
        TableContext::popTag();
        
        // 第二个表格（嵌套）
        echo "3. 渲染第二个d-table...\n";
        TableContext::setTableContext('table2', [
            'model' => 'Weline\Demo\Model\Demo2',
            'scope' => 'table2',
            'editable' => false
        ]);
        
        // 第二个表格的t-header
        echo "4. 渲染第二个表格的t-header...\n";
        TableContext::pushChildTag('header', 'table2-header', []);
        $inherited2 = TableContext::inheritTableAttributes([], 'table2-header');
        echo "   继承的属性: " . json_encode($inherited2) . "\n";
        TableContext::popTag();
        
        // 结束第二个表格
        echo "5. 结束第二个表格...\n";
        TableContext::popTag();
        
        // 结束第一个表格
        echo "6. 结束第一个表格...\n";
        TableContext::popTag();
        
        echo "=== 嵌套测试完成 ===\n";
    }
} 