<?php

namespace Weline\DataTable\Test;

use Weline\Framework\UnitTest\TestCore;
use Weline\DataTable\Taglib\Field;
use Weline\Framework\App\Exception;
use Weline\Framework\Manager\ObjectManager;
use Weline\DataTable\Helper\TableContext;

class FieldErrorMessagesTest extends TestCore
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
     * 测试在t-header中使用options属性的错误提示
     */
    public function testHeaderFieldWithOptionsAttribute()
    {
        // 设置表格上下文
        $scope = 'test-scope';
        $modelClass = 'Weline\Currency\Model\Currency';
        $tableContext = [
            'model' => $modelClass,
            'scope' => $scope
        ];
        TableContext::setTableContext($scope, $tableContext);

        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateHeaderFieldAttributes');
        $method->setAccessible(true);

        // 应该抛出异常，因为在t-header中使用了options属性
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：status）在t-header上下文中不支持options属性！该属性仅用于t-filter中的过滤器字段。请将options属性移除，或将该field标签移动到t-filter标签内。');
        
        $method->invoke(null, ['name' => 'status', 'options' => '1:启用,0:禁用']);
    }

    /**
     * 测试在t-filter中使用sortable属性的错误提示
     */
    public function testFilterFieldWithSortableAttribute()
    {
        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFilterFieldAttributes');
        $method->setAccessible(true);

        // 应该抛出异常，因为在t-filter中使用了sortable属性
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：name）在t-filter上下文中不支持sortable属性！该属性仅用于t-header中的表格头部字段。请将sortable属性移除，或将该field标签移动到t-header标签内。');
        
        $method->invoke(null, ['name' => 'name', 'sortable' => 'true']);
    }

    /**
     * 测试select类型缺少options属性的错误提示
     */
    public function testSelectFieldWithoutOptions()
    {
        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFilterFieldAttributes');
        $method->setAccessible(true);

        // 应该抛出异常，因为select类型缺少options属性
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：status）在t-filter上下文中，select类型的字段必须指定options属性！请添加options属性，格式：value:label,value2:label2。');
        
        $method->invoke(null, ['name' => 'status', 'type' => 'select']);
    }

    /**
     * 测试options格式错误的错误提示
     */
    public function testInvalidOptionsFormat()
    {
        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFilterFieldAttributes');
        $method->setAccessible(true);

        // 应该抛出异常，因为options格式错误
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：status）在t-filter上下文中，select类型的options属性格式错误：1启用。正确格式：value:label,value2:label2。请检查options属性格式。');
        
        $method->invoke(null, ['name' => 'status', 'type' => 'select', 'options' => '1启用,0禁用']);
    }

    /**
     * 测试Model类不存在的错误提示
     */
    public function testNonExistentModelClass()
    {
        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFieldExists');
        $method->setAccessible(true);

        // 应该抛出异常，因为Model类不存在
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签验证失败：Model类"Weline\Demo\Model\NonExistentModel"不存在！请检查类名是否正确，确保Model类已正确加载。常见Model类：Weline\Demo\Model\Demo、WeShop\Product\Model\Product等。');
        
        $method->invoke(null, 'name', 'Weline\Demo\Model\NonExistentModel');
    }

    /**
     * 测试字段不存在的错误提示
     */
    public function testNonExistentField()
    {
        // 使用真实存在的model类
        $modelClass = 'Weline\Currency\Model\Currency';
        
        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFieldExists');
        $method->setAccessible(true);

        // 应该抛出异常，因为字段不存在
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：non_existent_field）在Model类"Weline\Currency\Model\Currency"中不存在！可用字段：');
        
        $method->invoke(null, 'non_existent_field', $modelClass);
    }

    /**
     * 测试type值无效的错误提示
     */
    public function testInvalidTypeValue()
    {
        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateFilterFieldAttributes');
        $method->setAccessible(true);

        // 应该抛出异常，因为type值无效
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：name）在t-filter上下文中，type属性值无效：invalid_type。有效值：text, select, date, number, checkbox。请检查type属性值是否正确。');
        
        $method->invoke(null, ['name' => 'name', 'type' => 'invalid_type']);
    }

    /**
     * 测试在t-header中使用type属性的错误提示
     */
    public function testHeaderFieldWithTypeAttribute()
    {
        // 使用反射测试private方法
        $reflection = new \ReflectionClass(Field::class);
        $method = $reflection->getMethod('validateHeaderFieldAttributes');
        $method->setAccessible(true);

        // 应该抛出异常，因为在t-header中使用了无效的type属性
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：name）在t-header上下文中不支持type属性，或type值无效：invalid_type。在t-header中，field标签主要用于定义表格列，不需要指定type属性。');
        
        $method->invoke(null, ['name' => 'name', 'type' => 'invalid_type']);
    }

    /**
     * 测试完整的field标签回调函数错误提示
     */
    public function testFieldCallbackErrorMessages()
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

        // 设置测试数据，在t-header中使用options属性
        $tag_key = 'test-field';
        $config = [
            'parent' => ['t-header'],
            'attributes' => [
                'scope' => $scope
            ]
        ];
        $tag_data = [2 => '状态'];
        $attrs = ['name' => 'status', 'options' => '1:启用,0:禁用'];

        // 应该抛出异常
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('field标签（字段：status）在t-header上下文中不支持options属性！该属性仅用于t-filter中的过滤器字段。请将options属性移除，或将该field标签移动到t-filter标签内。');
        
        $callback($tag_key, $config, $tag_data, $attrs);
    }

    /**
     * 测试错误提示的完整性
     */
    public function testErrorMessageCompleteness()
    {
        $errorMessages = [
            'header_with_options' => 'field标签（字段：status）在t-header上下文中不支持options属性！该属性仅用于t-filter中的过滤器字段。请将options属性移除，或将该field标签移动到t-filter标签内。',
            'filter_with_sortable' => 'field标签（字段：name）在t-filter上下文中不支持sortable属性！该属性仅用于t-header中的表格头部字段。请将sortable属性移除，或将该field标签移动到t-header标签内。',
            'select_without_options' => 'field标签（字段：status）在t-filter上下文中，select类型的字段必须指定options属性！请添加options属性，格式：value:label,value2:label2。',
            'invalid_options_format' => 'field标签（字段：status）在t-filter上下文中，select类型的options属性格式错误：1启用。正确格式：value:label,value2:label2。请检查options属性格式。',
            'non_existent_model' => 'field标签验证失败：Model类"Weline\Demo\Model\NonExistentModel"不存在！请检查类名是否正确，确保Model类已正确加载。常见Model类：Weline\Demo\Model\Demo、WeShop\Product\Model\Product等。',
            'invalid_type' => 'field标签（字段：name）在t-filter上下文中，type属性值无效：invalid_type。有效值：text, select, date, number, checkbox。请检查type属性值是否正确。'
        ];

        // 验证每个错误消息都包含必要的元素
        foreach ($errorMessages as $test => $message) {
            $this->assertStringContainsString('field标签', $message, "错误消息应该包含'field标签'");
            $this->assertStringContainsString('字段：', $message, "错误消息应该包含具体的字段名");
            $this->assertStringContainsString('上下文', $message, "错误消息应该包含上下文信息");
            $this->assertStringContainsString('请', $message, "错误消息应该包含解决方案建议");
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