<?php
/**
 * DataTable 标签系统手动验证测试
 * 
 * 此测试用于验证DataTable模块中各个标签的基本功能：
 * 1. 标签注册
 * 2. 属性继承
 * 3. 上下文管理
 * 4. 自动生成功能
 * 5. 字段验证
 */

namespace Weline\DataTable\Test\Manual;

use Weline\DataTable\Taglib\Table;
use Weline\DataTable\Taglib\TableHeader;
use Weline\DataTable\Taglib\TableFilter;
use Weline\DataTable\Taglib\Field;
use Weline\DataTable\Taglib\Form;
use Weline\DataTable\Helper\TableContext;

class TagVerificationTest
{
    /**
     * 运行所有测试
     */
    public static function runAllTests()
    {
        echo "开始 DataTable 标签系统验证测试...\n\n";
        
        $results = [
            'tag_registration' => self::testTagRegistration(),
            'table_tag' => self::testTableTag(),
            'attribute_inheritance' => self::testAttributeInheritance(),
            'context_management' => self::testContextManagement(),
            'field_validation' => self::testFieldValidation(),
            'auto_generation' => self::testAutoGeneration()
        ];
        
        self::printResults($results);
        
        return $results;
    }
    
    /**
     * 测试标签注册
     */
    private static function testTagRegistration()
    {
        echo "=== 测试标签注册 ===\n";
        
        $tags = [
            'd-table' => Table::class,
            't-header' => TableHeader::class,
            't-filter' => TableFilter::class,
            'field' => Field::class,
            'd-form' => Form::class
        ];
        
        $passed = 0;
        $total = count($tags);
        
        foreach ($tags as $tagName => $className) {
            try {
                // 检查类是否存在
                if (!class_exists($className)) {
                    echo "❌ {$tagName}: 类 {$className} 不存在\n";
                    continue;
                }
                
                // 检查必需方法
                $requiredMethods = ['name', 'tag', 'attr', 'callback'];
                $missingMethods = [];
                
                foreach ($requiredMethods as $method) {
                    if (!method_exists($className, $method)) {
                        $missingMethods[] = $method;
                    }
                }
                
                if (!empty($missingMethods)) {
                    echo "❌ {$tagName}: 缺少方法 " . implode(', ', $missingMethods) . "\n";
                } else {
                    $tagName_check = $className::name();
                    $tag_check = $className::tag();
                    $attr_check = $className::attr();
                    
                    if ($tagName_check === $tagName && $tag_check === true && is_array($attr_check)) {
                        echo "✅ {$tagName}: 标签注册正常\n";
                        $passed++;
                    } else {
                        echo "❌ {$tagName}: 标签配置异常\n";
                    }
                }
                
            } catch (\Exception $e) {
                echo "❌ {$tagName}: 测试异常 - " . $e->getMessage() . "\n";
            }
        }
        
        echo "标签注册测试结果: {$passed}/{$total} 通过\n\n";
        return $passed === $total;
    }
    
    /**
     * 测试表格标签基本功能
     */
    private static function testTableTag()
    {
        echo "=== 测试表格标签基本功能 ===\n";
        
        $callback = Table::callback();
        $testModel = 'Test\\Model';
        $testScope = 'test-scope';
        
        $passed = 0;
        $total = 3;
        
        try {
            // 测试缺少必需参数
            try {
                $result = $callback('d-table', [], ['', '', ''], []);
                echo "❌ 表格标签: 缺少必需参数时应该抛出异常\n";
            } catch (\Exception $e) {
                echo "✅ 表格标签: 正确验证必需参数 - " . $e->getMessage() . "\n";
                $passed++;
            }
            
            // 测试基本参数
            $attributes = [
                'model' => $testModel,
                'scope' => $testScope
            ];
            
            $result = $callback('d-table', [], ['', '', ''], $attributes);
            
            if (is_string($result) && !empty($result)) {
                echo "✅ 表格标签: 基本功能正常，生成 " . strlen($result) . " 字符的HTML\n";
                $passed++;
            } else {
                echo "❌ 表格标签: 基本功能异常\n";
            }
            
            // 测试多模型配置
            $attributes['model'] = 'User as u, Order as o';
            $attributes['join'] = 'left u.id = o.user_id';
            
            $result = $callback('d-table', [], ['', '', ''], $attributes);
            
            if (is_string($result) && strpos($result, 'modelConfig') !== false) {
                echo "✅ 表格标签: 多模型配置正常\n";
                $passed++;
            } else {
                echo "❌ 表格标签: 多模型配置异常\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ 表格标签: 测试异常 - " . $e->getMessage() . "\n";
        }
        
        echo "表格标签测试结果: {$passed}/{$total} 通过\n\n";
        return $passed === $total;
    }
    
    /**
     * 测试属性继承
     */
    private static function testAttributeInheritance()
    {
        echo "=== 测试属性继承 ===\n";
        
        $passed = 0;
        $total = 3;
        
        try {
            // 清理之前的上下文
            TableContext::clearAll();
            
            // 设置父表格上下文
            $parentContext = [
                'type' => 'd-table',
                'scope' => 'parent-scope',
                'model' => 'Test\\Model',
                'sortable' => true,
                'searchable' => true
            ];
            
            TableContext::pushChildTag('d-table', 'parent-scope', $parentContext);
            
            // 测试属性继承
            $childAttributes = ['scope' => 'child-scope'];
            $inheritedAttributes = TableContext::inheritTableAttributes(
                $childAttributes, 
                'child-scope-header', 
                ['model', 'sortable', 'searchable']
            );
            
            if (isset($inheritedAttributes['model']) && $inheritedAttributes['model'] === 'Test\\Model') {
                echo "✅ 属性继承: model继承正常\n";
                $passed++;
            } else {
                echo "❌ 属性继承: model继承异常\n";
            }
            
            if (isset($inheritedAttributes['sortable']) && $inheritedAttributes['sortable'] === true) {
                echo "✅ 属性继承: sortable继承正常\n";
                $passed++;
            } else {
                echo "❌ 属性继承: sortable继承异常\n";
            }
            
            // 测试scope自动生成
            if (isset($inheritedAttributes['scope']) && strpos($inheritedAttributes['scope'], 'child-scope-header') !== false) {
                echo "✅ 属性继承: scope自动生成正常\n";
                $passed++;
            } else {
                echo "❌ 属性继承: scope自动生成异常\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ 属性继承: 测试异常 - " . $e->getMessage() . "\n";
        } finally {
            TableContext::clearAll();
        }
        
        echo "属性继承测试结果: {$passed}/{$total} 通过\n\n";
        return $passed === $total;
    }
    
    /**
     * 测试上下文管理
     */
    private static function testContextManagement()
    {
        echo "=== 测试上下文管理 ===\n";
        
        $passed = 0;
        $total = 4;
        
        try {
            // 清理环境
            TableContext::clearAll();
            
            // 测试设置上下文
            $testContext = [
                'model' => 'Test\\Model',
                'scope' => 'test-scope',
                'sortable' => true
            ];
            
            TableContext::setTableContext('test-scope', $testContext);
            echo "✅ 上下文管理: 设置上下文正常\n";
            $passed++;
            
            // 测试获取上下文
            $retrievedContext = TableContext::getTableContext('test-scope');
            if ($retrievedContext && $retrievedContext['model'] === 'Test\\Model') {
                echo "✅ 上下文管理: 获取上下文正常\n";
                $passed++;
            } else {
                echo "❌ 上下文管理: 获取上下文异常\n";
            }
            
            // 测试推入和弹出标签
            TableContext::pushChildTag('t-header', 'test-header-scope', ['type' => 't-header']);
            echo "✅ 上下文管理: 推入子标签正常\n";
            $passed++;
            
            TableContext::popTag();
            echo "✅ 上下文管理: 弹出子标签正常\n";
            $passed++;
            
        } catch (\Exception $e) {
            echo "❌ 上下文管理: 测试异常 - " . $e->getMessage() . "\n";
        } finally {
            TableContext::clearAll();
        }
        
        echo "上下文管理测试结果: {$passed}/{$total} 通过\n\n";
        return $passed === $total;
    }
    
    /**
     * 测试字段验证
     */
    private static function testFieldValidation()
    {
        echo "=== 测试字段验证 ===\n";
        
        $callback = Field::callback();
        $passed = 0;
        $total = 2;
        
        try {
            // 设置上下文
            TableContext::pushChildTag('t-filter', 'test-scope', [
                'type' => 't-filter',
                'scope' => 'test-scope'
            ]);
            
            // 测试缺少belong属性
            try {
                $result = $callback('field', [], ['', '', ''], ['name' => 'test_field']);
                echo "❌ 字段验证: 缺少belong属性时应该抛出异常\n";
            } catch (\Exception $e) {
                echo "✅ 字段验证: 正确验证belong属性 - " . substr($e->getMessage(), 0, 50) . "...\n";
                $passed++;
            }
            
            // 测试有效字段
            $attributes = [
                'belong' => 't-filter',
                'name' => 'test_field',
                'type' => 'text'
            ];
            
            $result = $callback('field', [], ['', '', ''], $attributes);
            
            if (is_string($result) && !empty($result)) {
                echo "✅ 字段验证: 基本字段验证正常\n";
                $passed++;
            } else {
                echo "❌ 字段验证: 基本字段验证异常\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ 字段验证: 测试异常 - " . $e->getMessage() . "\n";
        } finally {
            TableContext::clearAll();
        }
        
        echo "字段验证测试结果: {$passed}/{$total} 通过\n\n";
        return $passed === $total;
    }
    
    /**
     * 测试自动生成功能
     */
    private static function testAutoGeneration()
    {
        echo "=== 测试自动生成功能 ===\n";
        
        $callback = Table::callback();
        $passed = 0;
        $total = 2;
        
        try {
            // 测试空内容自动生成
            $attributes = [
                'model' => 'Test\\Model',
                'scope' => 'test-auto'
            ];
            
            $result = $callback('d-table', [], ['', '', ''], $attributes);
            
            if (is_string($result)) {
                // 检查是否包含自动生成的标签
                if (strpos($result, 't-filter') !== false && strpos($result, 't-header') !== false) {
                    echo "✅ 自动生成: 表头和过滤器自动生成正常\n";
                    $passed++;
                } else {
                    echo "❌ 自动生成: 缺少自动生成的标签\n";
                }
                
                // 检查是否包含JavaScript初始化
                if (strpos($result, 'DataTableManager') !== false) {
                    echo "✅ 自动生成: JavaScript初始化正常\n";
                    $passed++;
                } else {
                    echo "❌ 自动生成: 缺少JavaScript初始化\n";
                }
            } else {
                echo "❌ 自动生成: 生成结果异常\n";
            }
            
        } catch (\Exception $e) {
            echo "❌ 自动生成: 测试异常 - " . $e->getMessage() . "\n";
        }
        
        echo "自动生成测试结果: {$passed}/{$total} 通过\n\n";
        return $passed === $total;
    }
    
    /**
     * 打印测试结果
     */
    private static function printResults($results)
    {
        echo "================================\n";
        echo "DataTable 标签系统验证测试结果\n";
        echo "================================\n\n";
        
        $totalPassed = 0;
        $totalTests = count($results);
        
        foreach ($results as $testName => $result) {
            $status = $result ? '✅ 通过' : '❌ 失败';
            echo str_pad($testName, 30) . ": {$status}\n";
            if ($result) $totalPassed++;
        }
        
        echo "\n总体结果: {$totalPassed}/{$totalTests} 测试通过\n";
        
        if ($totalPassed === $totalTests) {
            echo "🎉 所有测试通过！DataTable标签系统功能正常。\n";
        } else {
            echo "⚠️  有测试失败，需要检查相关功能。\n";
        }
    }
}

// 如果直接运行此文件，执行测试
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    TagVerificationTest::runAllTests();
}
