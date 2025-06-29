<?php

namespace Weline\DataTable\Test;

use Weline\Framework\UnitTest\TestCore;
use Weline\DataTable\Taglib\Field;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\DataTable\Helper\TableContext;

class FieldContextTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // 设置必要的环境变量
        if (!isset($_SERVER['SERVER_PROTOCOL'])) {
            $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        }
        if (!isset($_SERVER['HTTP_HOST'])) {
            $_SERVER['HTTP_HOST'] = 'localhost';
        }
        if (!isset($_SERVER['REQUEST_URI'])) {
            $_SERVER['REQUEST_URI'] = '/';
        }
        if (!isset($_SERVER['REQUEST_METHOD'])) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
        }
        if (!isset($_SERVER['SERVER_PORT'])) {
            $_SERVER['SERVER_PORT'] = '80';
        }
        if (!isset($_SERVER['SERVER_NAME'])) {
            $_SERVER['SERVER_NAME'] = 'localhost';
        }
        
        // 清理测试上下文
        TableContext::clearRenderStack();
        $allContexts = TableContext::getAllTableContexts();
        foreach (array_keys($allContexts) as $scope) {
            TableContext::clearTableContext($scope);
        }
    }

    /**
     * 测试field标签在t-header中的上下文识别
     */
    public function testFieldInTHeaderContext()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 模拟t-header在渲染栈中
        TableContext::pushRenderStack('t-header', [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 使用反射测试getFieldContext方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('getFieldContext');
        $method->setAccessible(true);

        // 设置field标签配置
        $config = [
            'parent' => ['t-header'],
            'attributes' => []
        ];

        $context = $method->invoke(null, $config);

        // 验证上下文识别结果
        $this->assertEquals('t-header', $context['parent_tag_type']);
        $this->assertTrue($context['found_in_stack']);
        $this->assertEquals($scope, $context['parent_attributes']['scope']);
        $this->assertNotNull($context['table_context']);
    }

    /**
     * 测试field标签在t-filter中的上下文识别
     */
    public function testFieldInTFilterContext()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 模拟t-filter在渲染栈中
        TableContext::pushRenderStack('t-filter', [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 使用反射测试getFieldContext方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('getFieldContext');
        $method->setAccessible(true);

        // 设置field标签配置
        $config = [
            'parent' => ['t-filter'],
            'attributes' => []
        ];

        $context = $method->invoke(null, $config);

        // 验证上下文识别结果
        $this->assertEquals('t-filter', $context['parent_tag_type']);
        $this->assertTrue($context['found_in_stack']);
        $this->assertEquals($scope, $context['parent_attributes']['scope']);
        $this->assertNotNull($context['table_context']);
    }

    /**
     * 测试field标签从d-table继承model的情况
     */
    public function testFieldInheritsModelFromDTable()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 模拟d-table在渲染栈中
        TableContext::pushRenderStack('d-table', [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 模拟t-header在渲染栈中（没有model属性）
        TableContext::pushRenderStack('t-header', [
            'scope' => $scope
        ]);

        // 使用反射测试getFieldContext方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('getFieldContext');
        $method->setAccessible(true);

        // 设置field标签配置
        $config = [
            'parent' => ['t-header'],
            'attributes' => []
        ];

        $context = $method->invoke(null, $config);

        // 验证上下文识别结果
        $this->assertEquals('t-header', $context['parent_tag_type']);
        $this->assertTrue($context['found_in_stack']);
        $this->assertEquals($scope, $context['parent_attributes']['scope']);
        $this->assertNotNull($context['table_context']);
    }

    /**
     * 测试field标签的完整渲染流程
     */
    public function testFieldCompleteRenderingFlow()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 模拟d-table在渲染栈中
        TableContext::pushRenderStack('d-table', [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 模拟t-header在渲染栈中
        TableContext::pushRenderStack('t-header', [
            'scope' => $scope
        ]);

        // 获取field标签的回调函数
        $callback = Field::callback();

        // 设置测试数据
        $tag_key = 'test-field';
        $config = [
            'parent' => ['t-header'],
            'attributes' => []
        ];
        $tag_data = [2 => 'ID'];
        $attrs = ['name' => 'store_id', 'sortable' => 'true'];

        // 应该正常执行，不抛出异常
        $result = $callback($tag_key, $config, $tag_data, $attrs);
        
        // 验证返回的是HTML字符串
        $this->assertIsString($result);
        $this->assertStringContainsString('data-field="store_id"', $result);
        $this->assertStringContainsString('data-sort-field="sort.store_id"', $result);
    }

    /**
     * 测试field标签在t-filter中的完整渲染流程
     */
    public function testFilterFieldCompleteRenderingFlow()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 模拟d-table在渲染栈中
        TableContext::pushRenderStack('d-table', [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 模拟t-filter在渲染栈中
        TableContext::pushRenderStack('t-filter', [
            'scope' => $scope
        ]);

        // 获取field标签的回调函数
        $callback = Field::callback();

        // 设置测试数据
        $tag_key = 'test-field';
        $config = [
            'parent' => ['t-filter'],
            'attributes' => []
        ];
        $tag_data = [2 => '状态'];
        $attrs = ['name' => 'status', 'type' => 'select', 'options' => '1:启用,0:禁用'];

        // 应该正常执行，不抛出异常
        $result = $callback($tag_key, $config, $tag_data, $attrs);
        
        // 验证返回的是HTML字符串
        $this->assertIsString($result);
        $this->assertStringContainsString('data-field="status"', $result);
        $this->assertStringContainsString('name="filter[status]"', $result);
        $this->assertStringContainsString('<option value="1">启用</option>', $result);
        $this->assertStringContainsString('<option value="0">禁用</option>', $result);
    }

    /**
     * 测试上下文识别失败的情况
     */
    public function testFieldContextIdentificationFailure()
    {
        // 不设置任何上下文
        TableContext::clearRenderStack();

        // 使用反射测试getFieldContext方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('getFieldContext');
        $method->setAccessible(true);

        // 设置field标签配置
        $config = [
            'parent' => [],
            'attributes' => []
        ];

        $context = $method->invoke(null, $config);

        // 验证上下文识别结果
        $this->assertEquals('', $context['parent_tag_type']);
        $this->assertFalse($context['found_in_stack']);
        $this->assertEmpty($context['parent_attributes']);
        $this->assertNull($context['table_context']);
    }

    /**
     * 测试渲染栈顺序对上下文识别的影响
     */
    public function testRenderStackOrderImpact()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 先添加t-filter，再添加t-header
        TableContext::pushRenderStack('t-filter', [
            'scope' => $scope,
            'model' => $modelClass
        ]);
        TableContext::pushRenderStack('t-header', [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 使用反射测试getFieldContext方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('getFieldContext');
        $method->setAccessible(true);

        // 设置field标签配置（不指定parent）
        $config = [
            'parent' => [],
            'attributes' => []
        ];

        $context = $method->invoke(null, $config);

        // 应该识别到t-header（栈顶的）
        $this->assertEquals('t-header', $context['parent_tag_type']);
        $this->assertTrue($context['found_in_stack']);
    }

    /**
     * 测试field标签在t-header中使用options属性的错误处理
     */
    public function testFieldOptionsInTHeaderError()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 模拟t-header在渲染栈中
        TableContext::pushChildTag('header', $scope, [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 获取field标签的回调函数
        $callback = Field::callback();

        // 设置测试数据 - 在t-header中使用options属性（这是错误的）
        $tag_key = 'test-field';
        $config = [
            'parent' => ['t-header'],
            'attributes' => []
        ];
        $tag_data = [2 => '状态'];
        $attrs = ['name' => 'status', 'options' => '1:启用,0:禁用'];

        // 应该抛出异常
        $this->expectException(\Weline\Framework\App\Exception::class);
        $this->expectExceptionMessageMatches('/field标签（字段：status）在t-header上下文中不支持options属性/');
        
        $callback($tag_key, $config, $tag_data, $attrs);
    }

    /**
     * 测试field标签在t-filter中使用sortable属性的错误处理
     */
    public function testFieldSortableInTFilterError()
    {
        // 设置表格上下文
        $scope = 'store-listing';
        $modelClass = 'WeShop\Store\Model\Store';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 模拟t-filter在渲染栈中
        TableContext::pushChildTag('filter', $scope, [
            'scope' => $scope,
            'model' => $modelClass
        ]);

        // 获取field标签的回调函数
        $callback = Field::callback();

        // 设置测试数据 - 在t-filter中使用sortable属性（这是错误的）
        $tag_key = 'test-field';
        $config = [
            'parent' => ['t-filter'],
            'attributes' => []
        ];
        $tag_data = [2 => '状态'];
        $attrs = ['name' => 'status', 'sortable' => 'true'];

        // 应该抛出异常
        $this->expectException(\Weline\Framework\App\Exception::class);
        $this->expectExceptionMessageMatches('/field标签（字段：status）在t-filter上下文中不支持sortable属性/');
        
        $callback($tag_key, $config, $tag_data, $attrs);
    }

    protected function tearDown(): void
    {
        // 清理测试上下文
        TableContext::clearRenderStack();
        $allContexts = TableContext::getAllTableContexts();
        foreach (array_keys($allContexts) as $scope) {
            TableContext::clearTableContext($scope);
        }
        parent::tearDown();
    }
} 