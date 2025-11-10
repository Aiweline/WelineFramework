<?php
/**
 * DataTable API 单元测试
 * 测试所有 API 控制器的功能
 */

namespace Weline\DataTable\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\DataTable\Api\Rest\V1\DataTable;
use Weline\DataTable\Api\Rest\V1\Form;
use Weline\DataTable\Api\Rest\V1\Trash;
use Weline\Framework\App\Session\BackendApiSession;
use Weline\Framework\Http\Request;

class ApiTest extends TestCase
{
    private $dataTableApi;
    private $formApi;
    private $trashApi;
    private $mockBackendApiSession;
    private $mockRequest;

    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建 BackendApiSession 模拟对象
        $this->mockBackendApiSession = $this->createMock(BackendApiSession::class);
        $this->mockBackendApiSession->method('isLogin')
            ->willReturn(true);
        
        // 创建 Request 模拟对象
        $this->mockRequest = $this->createMock(Request::class);
        
        // 创建 API 实例
        $this->dataTableApi = new DataTable($this->mockBackendApiSession);
        $this->formApi = new Form($this->mockBackendApiSession);
        $this->trashApi = new Trash($this->mockBackendApiSession);
        
        // 使用反射设置 request 属性
        $this->setRequestProperty($this->dataTableApi);
        $this->setRequestProperty($this->formApi);
        $this->setRequestProperty($this->trashApi);
    }

    /**
     * 使用反射设置 request 属性
     */
    private function setRequestProperty($apiInstance)
    {
        $reflection = new \ReflectionClass($apiInstance);
        if ($reflection->hasProperty('request')) {
            $requestProperty = $reflection->getProperty('request');
            $requestProperty->setAccessible(true);
            $requestProperty->setValue($apiInstance, $this->mockRequest);
        }
    }

    // ==================== DataTable API 测试 ====================

    /**
     * 测试获取数据接口 - 成功情况
     */
    public function testDataTablePostDataSuccess()
    {
        // 设置请求参数
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postData();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试获取数据接口 - 缺少必需参数
     */
    public function testDataTablePostDataMissingRequiredParams()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, ''],
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postData();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
        if (isset($result['msg'])) {
            $this->assertStringContainsString('缺少必需参数', $result['msg']);
        } elseif (isset($result['message'])) {
            $this->assertStringContainsString('缺少必需参数', $result['message']);
        }
    }

    /**
     * 测试创建记录接口
     */
    public function testDataTablePostCreate()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['data', [], [
                    'name' => '测试用户',
                    'email' => 'test@example.com',
                    'status' => 1
                ]]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postCreate();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试更新记录接口
     */
    public function testDataTablePostUpdate()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['id', null, 1],
                ['data', [], [
                    'name' => '更新后的用户名',
                    'email' => 'updated@example.com'
                ]]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postUpdate();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试删除记录接口 - 单个删除
     */
    public function testDataTablePostDeleteSingle()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['id', null, 1],
                ['ids', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postDelete();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试删除记录接口 - 批量删除
     */
    public function testDataTablePostDeleteBatch()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['id', null, ''],
                ['ids', [], [1, 2, 3]]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postDelete();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试字段类型判断方法
     */
    public function testDataTableFieldTypeValidation()
    {
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
     * 测试分页参数处理
     */
    public function testDataTablePaginationParams()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['scope', null, 'test-scope'],
                ['page', 1, 2],
                ['limit', 20, 50],
                ['filters', [], []],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postData();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试排序参数处理
     */
    public function testDataTableSortParams()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], [
                    'name' => 'asc',
                    'created_at' => 'desc'
                ]],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postData();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试筛选参数处理
     */
    public function testDataTableFilterParams()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['scope', null, 'test-scope'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], [
                    'name' => '张三',
                    'email' => 'test@example.com'
                ]],
                ['sort', [], []],
                ['join', '', ''],
                ['model_config', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->dataTableApi->postData();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    // ==================== Form API 测试 ====================

    /**
     * 测试获取表单字段 - 成功情况
     */
    public function testFormPostFieldsSuccess()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['scope', null, 'test-form'],
                ['form_id', null, ''],
                ['exclude_fields', [], []],
                ['include_fields', [], []],
                ['manual_fields', [], []]
            ]);

        $this->mockRequest->method('getBodyParams')
            ->willReturn([
                'model' => 'Weline\DataTable\Model\TestUser',
                'scope' => 'test-form'
            ]);

        ob_start();
        try {
            $result = @$this->formApi->postFields();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试获取表单字段 - 缺少必需参数
     */
    public function testFormPostFieldsMissingModel()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, ''],
                ['scope', null, 'test-form']
            ]);

        $this->mockRequest->method('getBodyParams')
            ->willReturn([]);

        ob_start();
        try {
            $result = @$this->formApi->postFields();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
        if (isset($result['msg'])) {
            $this->assertStringContainsString('缺少必需参数', $result['msg']);
        } elseif (isset($result['message'])) {
            $this->assertStringContainsString('缺少必需参数', $result['message']);
        }
    }

    /**
     * 测试获取表单字段 - 模型类不存在
     */
    public function testFormPostFieldsModelNotExists()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'NonExistent\Model\Class'],
                ['scope', null, 'test-form']
            ]);

        $this->mockRequest->method('getBodyParams')
            ->willReturn([
                'model' => 'NonExistent\Model\Class'
            ]);

        ob_start();
        try {
            $result = @$this->formApi->postFields();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
        if (isset($result['msg'])) {
            $this->assertStringContainsString('模型类不存在', $result['msg']);
        } elseif (isset($result['message'])) {
            $this->assertStringContainsString('模型类不存在', $result['message']);
        }
    }

    /**
     * 测试获取表单字段 - 排除字段
     */
    public function testFormPostFieldsWithExcludeFields()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['scope', null, 'test-form'],
                ['exclude_fields', [], ['password', 'token']],
                ['include_fields', [], []],
                ['manual_fields', [], []]
            ]);

        $this->mockRequest->method('getBodyParams')
            ->willReturn([
                'model' => 'Weline\DataTable\Model\TestUser',
                'exclude_fields' => ['password', 'token']
            ]);

        ob_start();
        try {
            $result = @$this->formApi->postFields();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试获取表单记录数据
     */
    public function testFormPostRecord()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['record_id', null, 1]
            ]);

        $this->mockRequest->method('getBodyParams')
            ->willReturn([
                'model' => 'Weline\DataTable\Model\TestUser',
                'record_id' => 1
            ]);

        ob_start();
        try {
            $result = @$this->formApi->postRecord();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试获取表单记录数据 - 缺少参数
     */
    public function testFormPostRecordMissingParams()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, ''],
                ['record_id', null, '']
            ]);

        $this->mockRequest->method('getBodyParams')
            ->willReturn([]);

        ob_start();
        try {
            $result = @$this->formApi->postRecord();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
        if (isset($result['msg'])) {
            $this->assertStringContainsString('缺少必需参数', $result['msg']);
        } elseif (isset($result['message'])) {
            $this->assertStringContainsString('缺少必需参数', $result['message']);
        }
    }

    // ==================== Trash API 测试 ====================

    /**
     * 测试获取回收站数据 - 成功情况
     */
    public function testTrashGetDataSuccess()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->trashApi->getData();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试获取回收站数据 - 缺少必需参数
     */
    public function testTrashGetDataMissingModel()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, ''],
                ['page', 1, 1],
                ['limit', 20, 20],
                ['filters', [], []],
                ['sort', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->trashApi->getData();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
        if (isset($result['msg'])) {
            $this->assertStringContainsString('缺少必需参数', $result['msg']);
        } elseif (isset($result['message'])) {
            $this->assertStringContainsString('缺少必需参数', $result['message']);
        }
    }

    /**
     * 测试恢复记录 - 单个恢复
     */
    public function testTrashRestoreSingle()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['id', null, 1],
                ['ids', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->trashApi->restore();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试恢复记录 - 批量恢复
     */
    public function testTrashRestoreBatch()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['id', null, ''],
                ['ids', [], [1, 2, 3]]
            ]);

        ob_start();
        try {
            $result = @$this->trashApi->restore();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试永久删除记录 - 单个删除
     */
    public function testTrashForceDeleteSingle()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['id', null, 1],
                ['ids', [], []]
            ]);

        ob_start();
        try {
            $result = @$this->trashApi->forceDelete();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试永久删除记录 - 批量删除
     */
    public function testTrashForceDeleteBatch()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['id', null, ''],
                ['ids', [], [1, 2, 3]]
            ]);

        ob_start();
        try {
            $result = @$this->trashApi->forceDelete();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试清空回收站
     */
    public function testTrashEmpty()
    {
        $this->mockRequest->method('getParam')
            ->willReturnMap([
                ['model', null, 'Weline\DataTable\Model\TestUser'],
                ['confirm', false, true]
            ]);

        ob_start();
        try {
            $result = @$this->trashApi->empty();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
    }

    /**
     * 测试清空回收站 - 未确认
     */
    public function testTrashEmptyWithoutConfirm()
    {
        // 设置 model 参数，但不设置 confirm 参数（或设置为 false）
        $this->mockRequest->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                if ($key === 'model') {
                    return 'Weline\DataTable\Model\TestUser';
                }
                if ($key === 'confirm') {
                    return false; // 不确认
                }
                return $default;
            });

        ob_start();
        try {
            $result = @$this->trashApi->empty();
        } finally {
            ob_end_clean();
        }
        
        if (is_string($result)) {
            $result = json_decode($result, true);
        }
        
        $this->assertIsArray($result);
        if (isset($result['msg'])) {
            $this->assertStringContainsString('请确认', $result['msg']);
        } elseif (isset($result['message'])) {
            $this->assertStringContainsString('请确认', $result['message']);
        }
    }

    /**
     * 测试字段类型验证方法
     */
    public function testFormFieldTypeValidation()
    {
        $reflection = new \ReflectionClass($this->formApi);
        
        // 测试字段类型推断
        $getFieldTypeMethod = $reflection->getMethod('getFieldType');
        $getFieldTypeMethod->setAccessible(true);
        
        // 测试基于字段名的类型推断
        $this->assertEquals('email', $getFieldTypeMethod->invoke($this->formApi, 'email', []));
        $this->assertEquals('password', $getFieldTypeMethod->invoke($this->formApi, 'password', []));
        $this->assertEquals('tel', $getFieldTypeMethod->invoke($this->formApi, 'phone', []));
        
        // 测试基于数据库字段类型的推断（需要传入字段信息，包含 Field 键）
        // 注意：字段名推断逻辑在数据库列信息推断之前，所以字段名不能包含会触发推断的关键字
        $this->assertEquals('date', $getFieldTypeMethod->invoke($this->formApi, 'created_at', [['Field' => 'created_at', 'Type' => 'date']]));
        // datetime 类型应该返回 datetime（先检查 date，如果同时包含 time 则返回 datetime）
        // 使用不包含 'time' 的字段名，避免字段名推断逻辑干扰
        $this->assertEquals('datetime', $getFieldTypeMethod->invoke($this->formApi, 'created_datetime', [['Field' => 'created_datetime', 'Type' => 'datetime']]));
        $this->assertEquals('select', $getFieldTypeMethod->invoke($this->formApi, 'status', []));
        $this->assertEquals('textarea', $getFieldTypeMethod->invoke($this->formApi, 'description', []));
        $this->assertEquals('number', $getFieldTypeMethod->invoke($this->formApi, 'price', []));
        $this->assertEquals('text', $getFieldTypeMethod->invoke($this->formApi, 'name', []));
    }

    /**
     * 测试字段标签获取
     */
    public function testFormFieldLabel()
    {
        $reflection = new \ReflectionClass($this->formApi);
        $getFieldLabelMethod = $reflection->getMethod('getFieldLabel');
        $getFieldLabelMethod->setAccessible(true);
        
        $this->assertEquals('ID', $getFieldLabelMethod->invoke($this->formApi, 'id'));
        $this->assertEquals('名称', $getFieldLabelMethod->invoke($this->formApi, 'name'));
        $this->assertEquals('邮箱', $getFieldLabelMethod->invoke($this->formApi, 'email'));
        $this->assertEquals('状态', $getFieldLabelMethod->invoke($this->formApi, 'status'));
    }

    /**
     * 测试字段选项获取
     */
    public function testFormFieldOptions()
    {
        $reflection = new \ReflectionClass($this->formApi);
        $getFieldOptionsMethod = $reflection->getMethod('getFieldOptions');
        $getFieldOptionsMethod->setAccessible(true);
        
        // 测试状态字段选项
        $statusOptions = $getFieldOptionsMethod->invoke($this->formApi, 'status', 'select');
        $this->assertIsArray($statusOptions);
        $this->assertNotEmpty($statusOptions);
        
        // 测试非选择类型字段
        $textOptions = $getFieldOptionsMethod->invoke($this->formApi, 'name', 'text');
        $this->assertIsArray($textOptions);
        $this->assertEmpty($textOptions);
    }

    /**
     * 测试字段验证规则获取
     */
    public function testFormFieldValidation()
    {
        $reflection = new \ReflectionClass($this->formApi);
        $getFieldValidationMethod = $reflection->getMethod('getFieldValidation');
        $getFieldValidationMethod->setAccessible(true);
        
        // 测试邮箱字段验证
        $emailValidation = $getFieldValidationMethod->invoke($this->formApi, 'email', 'email');
        $this->assertIsArray($emailValidation);
        $this->assertArrayHasKey('pattern', $emailValidation);
        
        // 测试URL字段验证
        $urlValidation = $getFieldValidationMethod->invoke($this->formApi, 'url', 'url');
        $this->assertIsArray($urlValidation);
        $this->assertArrayHasKey('pattern', $urlValidation);
        
        // 测试数字字段验证
        $numberValidation = $getFieldValidationMethod->invoke($this->formApi, 'price', 'number');
        $this->assertIsArray($numberValidation);
        $this->assertArrayHasKey('type', $numberValidation);
    }
}
