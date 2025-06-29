<?php

namespace Weline\DataTable\Test;

use Weline\Framework\UnitTest\TestCore;
use Weline\DataTable\Taglib\Field;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\DataTable\Helper\TableContext;

class FieldValidationTest extends TestCore
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // 设置必要的环境变量，避免调用initRequest()导致的Session问题
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
     * 测试字段验证功能 - 字段存在的情况
     */
    public function testFieldExistsValidation()
    {
        // 使用真实存在的model类
        $modelClass = 'Weline\Currency\Model\Currency';
        
        // 设置测试配置
        $config = [
            'parent_tag' => 't-header',
            'parent_attributes' => [
                'model' => $modelClass
            ]
        ];

        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFieldExists');
        $method->setAccessible(true);

        // 应该不抛出异常，因为currency_id字段存在于Currency模型中
        $this->expectNotToPerformAssertions();
        $method->invoke(null, 'currency_id', $config);
    }

    /**
     * 测试字段验证功能 - 字段不存在的情况
     */
    public function testFieldNotExistsValidation()
    {
        // 使用真实存在的model类
        $modelClass = 'Weline\Currency\Model\Currency';
        
        // 设置测试配置
        $config = [
            'parent_tag' => 't-header',
            'parent_attributes' => [
                'model' => $modelClass
            ]
        ];

        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFieldExists');
        $method->setAccessible(true);

        // 应该抛出异常，因为non_existent_field字段不存在于Currency模型中
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('字段"non_existent_field"在model"Weline\Currency\Model\Currency"中不存在！');
        
        $method->invoke(null, 'non_existent_field', $config);
    }

    /**
     * 测试从TableContext获取model的情况
     */
    public function testFieldValidationWithTableContext()
    {
        // 设置表格上下文
        $scope = 'test-scope';
        $modelClass = 'Weline\Currency\Model\Currency';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 设置测试配置
        $config = [
            'parent_tag' => 't-header',
            'attributes' => [
                'scope' => $scope
            ]
        ];

        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFieldExists');
        $method->setAccessible(true);

        // 应该不抛出异常，因为code字段存在于Currency模型中
        $this->expectNotToPerformAssertions();
        $method->invoke(null, 'code', $config);
    }

    /**
     * 测试无法确定model类名的情况
     */
    public function testFieldValidationWithoutModel()
    {
        // 设置测试配置，不提供model信息
        $config = [
            'parent_tag' => 't-header',
            'attributes' => []
        ];

        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFieldExists');
        $method->setAccessible(true);

        // 应该抛出异常
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('无法确定model类名，请确保field标签位于d-table标签内或指定了model属性！');
        
        $method->invoke(null, 'name', $config);
    }

    /**
     * 测试完整的field标签回调函数 - 字段存在的情况
     */
    public function testFieldCallbackWithValidation()
    {
        // 设置表格上下文
        $scope = 'test-scope';
        $modelClass = 'Weline\Currency\Model\Currency';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 获取field标签的回调函数
        $callback = Field::callback();

        // 设置测试数据
        $tag_key = 'test-field';
        $config = [
            'parent_tag' => 't-header',
            'attributes' => [
                'scope' => $scope
            ]
        ];
        $tag_data = [2 => '测试字段'];
        $attrs = ['name' => 'currency_id', 'sortable' => 'true'];

        // 应该正常执行，不抛出异常
        $result = $callback($tag_key, $config, $tag_data, $attrs);
        
        // 验证返回的是HTML字符串
        $this->assertIsString($result);
        $this->assertStringContainsString('data-field="currency_id"', $result);
    }

    /**
     * 测试field标签回调函数抛出异常的情况
     */
    public function testFieldCallbackWithInvalidField()
    {
        // 设置表格上下文
        $scope = 'test-scope';
        $modelClass = 'Weline\Currency\Model\Currency';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 获取field标签的回调函数
        $callback = Field::callback();

        // 设置测试数据，使用不存在的字段
        $tag_key = 'test-field';
        $config = [
            'parent_tag' => 't-header',
            'attributes' => [
                'scope' => $scope
            ]
        ];
        $tag_data = [2 => '测试字段'];
        $attrs = ['name' => 'non_existent_field', 'sortable' => 'true'];

        // 应该抛出异常
        $this->expectException(Exception::class);
        $callback($tag_key, $config, $tag_data, $attrs);
    }

    /**
     * 测试Currency模型的所有有效字段
     */
    public function testCurrencyModelValidFields()
    {
        $modelClass = 'Weline\Currency\Model\Currency';
        
        // 设置测试配置
        $config = [
            'parent_tag' => 't-header',
            'parent_attributes' => [
                'model' => $modelClass
            ]
        ];

        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFieldExists');
        $method->setAccessible(true);

        // 测试Currency模型中定义的所有字段
        $validFields = ['currency_id', 'code', 'name', 'rate', 'symbol', 'position', 'format', 'status'];
        
        foreach ($validFields as $field) {
            $this->expectNotToPerformAssertions();
            $method->invoke(null, $field, $config);
        }
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