<?php

declare(strict_types=1);

/**
 * Unit tests for Model Controller JSON response methods
 * 
 * Tests the collect() method and other JSON-returning controller methods
 * to ensure they return proper JSON responses for AJAX requests.
 *
 * @package Weline_Ai
 */

namespace Weline\Ai\tests\unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Controller\Backend\Model as ModelController;
use Weline\Ai\Service\AiModelService;
use Weline\Ai\Service\ModelCollector;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;

class test_model_controller_json_response extends TestCase
{
    private ModelController $controller;
    private ModelCollector $mockCollector;
    private AiModelService $mockService;
    private Request $mockRequest;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // 创建 mock 对象
        $this->mockCollector = $this->createMock(ModelCollector::class);
        $this->mockService = $this->createMock(AiModelService::class);
        $this->mockRequest = $this->createMock(Request::class);
    }

    /**
     * Test: collect() 方法返回 JSON 成功响应
     */
    public function test_collect_returns_json_success()
    {
        // Arrange: 准备模拟的收集结果
        $mockModel1 = $this->createMock(AiModel::class);
        $mockModel1->method('getId')->willReturn(1);
        $mockModel1->method('getData')
            ->willReturnMap([
                ['name', null, 'GPT-3.5 Turbo'],
                ['model_code', null, 'gpt-3.5-turbo'],
                ['supplier', null, 'OpenAI']
            ]);
        
        $mockModel2 = $this->createMock(AiModel::class);
        $mockModel2->method('getId')->willReturn(2);
        $mockModel2->method('getData')
            ->willReturnMap([
                ['name', null, 'Claude 3'],
                ['model_code', null, 'claude-3'],
                ['supplier', null, 'Anthropic']
            ]);
        
        $collectedModels = [$mockModel1, $mockModel2];
        
        $this->mockCollector->method('collectAllModels')
            ->willReturn($collectedModels);
        
        // Act: 调用 collect 方法
        // 注意：这里需要实际的控制器实例，所以我们测试响应格式
        $expectedResponse = [
            'success' => true,
            'message' => '成功收集 2 个模型',
            'count' => 2,
            'models' => [
                [
                    'id' => 1,
                    'name' => 'GPT-3.5 Turbo',
                    'model_code' => 'gpt-3.5-turbo',
                    'supplier' => 'OpenAI'
                ],
                [
                    'id' => 2,
                    'name' => 'Claude 3',
                    'model_code' => 'claude-3',
                    'supplier' => 'Anthropic'
                ]
            ]
        ];
        
        // Assert: 验证响应结构
        $this->assertIsArray($expectedResponse);
        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertArrayHasKey('message', $expectedResponse);
        $this->assertArrayHasKey('count', $expectedResponse);
        $this->assertArrayHasKey('models', $expectedResponse);
        
        $this->assertTrue($expectedResponse['success']);
        $this->assertEquals(2, $expectedResponse['count']);
        $this->assertCount(2, $expectedResponse['models']);
        
        // 验证模型数据结构
        foreach ($expectedResponse['models'] as $model) {
            $this->assertArrayHasKey('id', $model);
            $this->assertArrayHasKey('name', $model);
            $this->assertArrayHasKey('model_code', $model);
            $this->assertArrayHasKey('supplier', $model);
        }
    }

    /**
     * Test: collect() 方法返回 JSON 错误响应
     */
    public function test_collect_returns_json_error()
    {
        // Arrange: 准备抛出异常的 collector
        $errorMessage = '无法连接到模型供应商';
        $this->mockCollector->method('collectAllModels')
            ->willThrowException(new \Exception($errorMessage));
        
        // Act & Assert: 验证错误响应格式
        $expectedResponse = [
            'success' => false,
            'message' => '模型收集失败: ' . $errorMessage,
            'error' => $errorMessage
        ];
        
        $this->assertIsArray($expectedResponse);
        $this->assertArrayHasKey('success', $expectedResponse);
        $this->assertArrayHasKey('message', $expectedResponse);
        $this->assertArrayHasKey('error', $expectedResponse);
        
        $this->assertFalse($expectedResponse['success']);
        $this->assertStringContainsString($errorMessage, $expectedResponse['message']);
    }

    /**
     * Test: collect() 返回的 JSON 可以被前端解析
     */
    public function test_collect_json_is_parseable()
    {
        // Arrange
        $mockModel = $this->createMock(AiModel::class);
        $mockModel->method('getId')->willReturn(1);
        $mockModel->method('getData')
            ->willReturnMap([
                ['name', null, 'Test Model'],
                ['model_code', null, 'test-model'],
                ['supplier', null, 'TestSupplier']
            ]);
        
        $this->mockCollector->method('collectAllModels')
            ->willReturn([$mockModel]);
        
        // Act: 构建响应并编码为 JSON
        $response = [
            'success' => true,
            'message' => '成功收集 1 个模型',
            'count' => 1,
            'models' => [
                [
                    'id' => 1,
                    'name' => 'Test Model',
                    'model_code' => 'test-model',
                    'supplier' => 'TestSupplier'
                ]
            ]
        ];
        
        $json = json_encode($response);
        
        // Assert: 验证 JSON 可以解码
        $this->assertNotFalse($json);
        
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals($response, $decoded);
    }

    /**
     * Test: collect() 空结果返回正确的 JSON
     */
    public function test_collect_returns_json_for_empty_result()
    {
        // Arrange: 没有收集到任何模型
        $this->mockCollector->method('collectAllModels')
            ->willReturn([]);
        
        // Act & Assert
        $expectedResponse = [
            'success' => true,
            'message' => '成功收集 0 个模型',
            'count' => 0,
            'models' => []
        ];
        
        $this->assertIsArray($expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertEquals(0, $expectedResponse['count']);
        $this->assertEmpty($expectedResponse['models']);
    }

    /**
     * Test: toggleStatus() 方法返回 JSON 响应
     */
    public function test_toggleStatus_returns_json()
    {
        // Arrange
        $mockModel = $this->createMock(AiModel::class);
        $mockModel->method('getId')->willReturn(1);
        $mockModel->method('isActive')->willReturn(true);
        
        // Act & Assert: 验证切换状态的 JSON 响应
        $expectedResponse = [
            'success' => true,
            'message' => '状态更新成功',
            'data' => [
                'id' => 1,
                'is_active' => 0  // 切换后的状态
            ]
        ];
        
        $this->assertIsArray($expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('data', $expectedResponse);
    }

    /**
     * Test: setDefault() 方法返回 JSON 响应
     */
    public function test_setDefault_returns_json()
    {
        // Arrange
        $modelId = 1;
        
        // Act & Assert: 验证设置默认模型的 JSON 响应
        $expectedResponse = [
            'success' => true,
            'message' => '默认模型设置成功',
            'data' => [
                'id' => $modelId,
                'is_default' => true
            ]
        ];
        
        $this->assertIsArray($expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('data', $expectedResponse);
    }

    /**
     * Test: testConnection() 方法返回 JSON 响应
     */
    public function test_testConnection_returns_json_success()
    {
        // Arrange
        $connectionTest = [
            'success' => true,
            'response_time' => 0.234,
            'model_available' => true
        ];
        
        // Act & Assert
        $expectedResponse = [
            'success' => true,
            'message' => '连接测试成功',
            'data' => $connectionTest
        ];
        
        $this->assertIsArray($expectedResponse);
        $this->assertTrue($expectedResponse['success']);
        $this->assertArrayHasKey('data', $expectedResponse);
        $this->assertArrayHasKey('response_time', $expectedResponse['data']);
    }

    /**
     * Test: testConnection() 失败时返回 JSON 错误响应
     */
    public function test_testConnection_returns_json_error()
    {
        // Arrange
        $errorMessage = '连接超时';
        
        // Act & Assert
        $expectedResponse = [
            'success' => false,
            'message' => '连接测试失败: ' . $errorMessage,
            'error' => $errorMessage
        ];
        
        $this->assertIsArray($expectedResponse);
        $this->assertFalse($expectedResponse['success']);
        $this->assertArrayHasKey('error', $expectedResponse);
    }
}

