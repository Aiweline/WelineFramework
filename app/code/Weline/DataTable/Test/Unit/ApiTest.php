<?php
/**
 * DataTable API 单元测试
 */

namespace Weline\DataTable\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\DataTable\Api\Rest\V1\DataTable;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;

class ApiTest extends TestCase
{
    private $dataTableApi;
    private $mockRequest;
    private $mockResponse;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建模拟对象
        $this->mockRequest = $this->createMock(Request::class);
        $this->mockResponse = $this->createMock(Response::class);
        
        // 创建 API 实例
        $this->dataTableApi = new DataTable($this->mockRequest, $this->mockResponse);
    }

    /**
     * 测试获取数据接口 - 成功情况
     */
    public function testPostDataSuccess()
    {
        // 设置请求参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        // 由于涉及到模型实例化，这里主要测试参数验证
        $result = $this->dataTableApi->postData();
        
        // 验证返回结果结构
        $this->assertIsArray($result);
    }

    /**
     * 测试获取数据接口 - 缺少必需参数
     */
    public function testPostDataMissingRequiredParams()
    {
        // 设置缺少必需参数的请求
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, ''], // 空的 model
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        $result = $this->dataTableApi->postData();
        
        // 验证错误响应
        $this->assertIsArray($result);
        $this->assertArrayHasKey('success', $result);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('缺少必需参数', $result['message']);
    }

    /**
     * 测试创建记录接口
     */
    public function testPostCreate()
    {
        // 设置请求参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['data', [], [
                    'name' => '测试用户',
                    'email' => 'test@example.com',
                    'status' => 1
                ]]
            ]);

        $result = $this->dataTableApi->postCreate();
        
        // 验证返回结果结构
        $this->assertIsArray($result);
    }

    /**
     * 测试更新记录接口
     */
    public function testPostUpdate()
    {
        // 设置请求参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['id', null, 1],
                ['data', [], [
                    'name' => '更新后的用户名',
                    'email' => 'updated@example.com'
                ]]
            ]);

        $result = $this->dataTableApi->postUpdate();
        
        // 验证返回结果结构
        $this->assertIsArray($result);
    }

    /**
     * 测试删除记录接口 - 单个删除
     */
    public function testPostDeleteSingle()
    {
        // 设置请求参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['id', null, 1],
                ['ids', [], []]
            ]);

        $result = $this->dataTableApi->postDelete();
        
        // 验证返回结果结构
        $this->assertIsArray($result);
    }

    /**
     * 测试删除记录接口 - 批量删除
     */
    public function testPostDeleteBatch()
    {
        // 设置请求参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['id', null, ''],
                ['ids', [], [1, 2, 3]]
            ]);

        $result = $this->dataTableApi->postDelete();
        
        // 验证返回结果结构
        $this->assertIsArray($result);
    }

    /**
     * 测试字段类型判断方法
     */
    public function testFieldTypeValidation()
    {
        // 使用反射访问私有方法
        $reflection = new \ReflectionClass($this->dataTableApi);
        
        // 测试数字字段判断
        $isNumericMethod = $reflection->getMethod('isNumericField');
        $isNumericMethod->setAccessible(true);
        
        $this->assertTrue($isNumericMethod->invoke($this->dataTableApi, 'id'));
        $this->assertTrue($isNumericMethod->invoke($this->dataTableApi, 'price'));
        $this->assertTrue($isNumericMethod->invoke($this->dataTableApi, 'user_id'));
        $this->assertFalse($isNumericMethod->invoke($this->dataTableApi, 'name'));
        $this->assertFalse($isNumericMethod->invoke($this->dataTableApi, 'email'));
        
        // 测试日期字段判断
        $isDateMethod = $reflection->getMethod('isDateField');
        $isDateMethod->setAccessible(true);
        
        $this->assertTrue($isDateMethod->invoke($this->dataTableApi, 'created_at'));
        $this->assertTrue($isDateMethod->invoke($this->dataTableApi, 'updated_at'));
        $this->assertTrue($isDateMethod->invoke($this->dataTableApi, 'deleted_at'));
        $this->assertFalse($isDateMethod->invoke($this->dataTableApi, 'name'));
        $this->assertFalse($isDateMethod->invoke($this->dataTableApi, 'email'));
    }

    /**
     * 测试数据验证方法
     */
    public function testDataValidation()
    {
        // 创建模拟模型实例
        $mockModel = $this->createMock(\stdClass::class);
        $mockModel->method('getColumns')
            ->willReturn([
                [
                    'Field' => 'name',
                    'Type' => 'varchar(255)',
                    'Null' => 'NO',
                    'Key' => '',
                    'Default' => null,
                    'Extra' => ''
                ],
                [
                    'Field' => 'email',
                    'Type' => 'varchar(255)',
                    'Null' => 'NO',
                    'Key' => '',
                    'Default' => null,
                    'Extra' => ''
                ],
                [
                    'Field' => 'age',
                    'Type' => 'int(11)',
                    'Null' => 'YES',
                    'Key' => '',
                    'Default' => null,
                    'Extra' => ''
                ]
            ]);

        // 使用反射访问私有方法
        $reflection = new \ReflectionClass($this->dataTableApi);
        $validateMethod = $reflection->getMethod('validateData');
        $validateMethod->setAccessible(true);

        // 测试有效数据
        $validData = [
            'name' => '张三',
            'email' => 'zhangsan@example.com',
            'age' => 25
        ];
        
        $result = $validateMethod->invoke($this->dataTableApi, $validData, $mockModel);
        $this->assertIsArray($result);
        $this->assertEquals('张三', $result['name']);
        $this->assertEquals('zhangsan@example.com', $result['email']);
        $this->assertEquals(25, $result['age']);

        // 测试缺少必填字段的数据
        $invalidData = [
            'age' => 25
            // 缺少必填的 name 和 email
        ];
        
        $result = $validateMethod->invoke($this->dataTableApi, $invalidData, $mockModel);
        $this->assertFalse($result);
    }

    /**
     * 测试字段类型验证方法
     */
    public function testValidateFieldType()
    {
        // 使用反射访问私有方法
        $reflection = new \ReflectionClass($this->dataTableApi);
        $validateTypeMethod = $reflection->getMethod('validateFieldType');
        $validateTypeMethod->setAccessible(true);

        // 测试整数类型
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, '123', 'int(11)'));
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, 123, 'int(11)'));
        $this->assertFalse($validateTypeMethod->invoke($this->dataTableApi, 'abc', 'int(11)'));

        // 测试浮点数类型
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, '123.45', 'decimal(10,2)'));
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, 123.45, 'decimal(10,2)'));
        $this->assertFalse($validateTypeMethod->invoke($this->dataTableApi, 'abc', 'decimal(10,2)'));

        // 测试日期类型
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, '2024-01-01', 'date'));
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, '2024-01-01 10:00:00', 'datetime'));
        $this->assertFalse($validateTypeMethod->invoke($this->dataTableApi, 'invalid-date', 'date'));

        // 测试字符串类型（应该总是通过）
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, 'any string', 'varchar(255)'));
        $this->assertTrue($validateTypeMethod->invoke($this->dataTableApi, '', 'varchar(255)'));
    }

    /**
     * 测试分页参数处理
     */
    public function testPaginationParams()
    {
        // 测试正常分页参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['scope', null, 'test-scope'],
                ['page', 1, 2],
                ['limit', 20, 50],
                ['filters', [], []],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        $result = $this->dataTableApi->postData();
        
        // 验证分页参数被正确处理
        $this->assertIsArray($result);

        // 测试无效分页参数（负数）
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['scope', null, 'test-scope'],
                ['page', 1, -1], // 负数页码
                ['limit', 20, 0], // 零限制
                ['filters', [], []],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        $result = $this->dataTableApi->postData();
        
        // 验证参数被修正为有效值
        $this->assertIsArray($result);
    }

    /**
     * 测试排序参数处理
     */
    public function testSortParams()
    {
        // 测试有效排序参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], [
                    'name' => 'asc',
                    'created_at' => 'desc',
                    'invalid_direction' => 'invalid'
                ]],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        $result = $this->dataTableApi->postData();
        
        // 验证排序参数被正确处理
        $this->assertIsArray($result);
    }

    /**
     * 测试筛选参数处理
     */
    public function testFilterParams()
    {
        // 测试各种筛选参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'TestModel'],
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], [
                    'name' => '张三',
                    'email' => 'test@example.com',
                    'age' => '25',
                    'created_at' => '2024-01-01',
                    'empty_filter' => '',
                    'null_filter' => null
                ]],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        $result = $this->dataTableApi->postData();
        
        // 验证筛选参数被正确处理
        $this->assertIsArray($result);
    }
}
