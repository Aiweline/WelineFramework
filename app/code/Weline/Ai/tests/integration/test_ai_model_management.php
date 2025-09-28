<?php
declare(strict_types=1);

/**
 * AI模型管理集成测试
 * 
 * 测试场景: AI模型收集、注册、管理功能
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Ai\Service\ModelCollector;
use Weline\Ai\Service\DefaultModelManager;

class AiModelManagementIntegrationTest extends TestCase
{
    private AiModel $aiModel;
    private ModelCollector $modelCollector;
    private DefaultModelManager $defaultModelManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->aiModel = new AiModel();
        $this->modelCollector = new ModelCollector();
        $this->defaultModelManager = new DefaultModelManager();
    }

    /**
     * 测试模型自动收集功能
     */
    public function testModelAutoCollection(): void
    {
        // 执行模型收集
        $result = $this->modelCollector->collectAllModels();
        
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['count']);
        
        // 验证收集到的模型
        $models = $this->aiModel->getCollection()
            ->where('vendor', 'openai')
            ->fetch();
        
        $this->assertNotEmpty($models);
        
        // 验证模型信息完整性
        foreach ($models as $model) {
            $this->assertNotEmpty($model->getVendor());
            $this->assertNotEmpty($model->getModelCode());
            $this->assertNotEmpty($model->getModelName());
            $this->assertIsNumeric($model->getTokenPriceInput());
            $this->assertIsNumeric($model->getTokenPriceOutput());
        }
    }

    /**
     * 测试模型注册功能
     */
    public function testModelRegistration(): void
    {
        $modelData = [
            'vendor' => 'test-vendor',
            'model_code' => 'test-model-001',
            'model_name' => 'Test Model',
            'model_version' => '1.0.0',
            'token_price_input' => 0.001,
            'token_price_output' => 0.002,
            'is_active' => 1
        ];
        
        $model = new AiModel();
        $model->setData($modelData);
        $result = $model->save();
        
        $this->assertTrue($result);
        $this->assertGreaterThan(0, $model->getId());
        
        // 验证模型已保存
        $savedModel = $this->aiModel->load($model->getId());
        $this->assertEquals('test-vendor', $savedModel->getVendor());
        $this->assertEquals('test-model-001', $savedModel->getModelCode());
    }

    /**
     * 测试默认模型设置
     */
    public function testDefaultModelSetting(): void
    {
        // 设置默认模型
        $result = $this->defaultModelManager->setDefaultModel('gpt-3.5-turbo', 'text');
        
        $this->assertTrue($result);
        
        // 验证默认模型设置
        $defaultModel = $this->defaultModelManager->getDefaultModel('text');
        $this->assertEquals('gpt-3.5-turbo', $defaultModel);
    }

    /**
     * 测试模型状态切换
     */
    public function testModelStatusToggle(): void
    {
        // 创建测试模型
        $model = new AiModel();
        $model->setData([
            'vendor' => 'test-vendor',
            'model_code' => 'test-toggle-model',
            'model_name' => 'Toggle Test Model',
            'is_active' => 1
        ]);
        $model->save();
        
        // 测试停用模型
        $result = $model->toggleStatus();
        $this->assertTrue($result);
        $this->assertEquals(0, $model->getIsActive());
        
        // 测试激活模型
        $result = $model->toggleStatus();
        $this->assertTrue($result);
        $this->assertEquals(1, $model->getIsActive());
    }

    /**
     * 测试模型配置管理
     */
    public function testModelConfiguration(): void
    {
        $config = [
            'api_key' => 'test-api-key',
            'base_url' => 'https://api.test.com',
            'timeout' => 30,
            'retry_times' => 3
        ];
        
        $model = new AiModel();
        $model->setData([
            'vendor' => 'test-vendor',
            'model_code' => 'test-config-model',
            'model_name' => 'Config Test Model'
        ]);
        $model->setConfig($config);
        $model->save();
        
        // 验证配置保存
        $savedModel = $this->aiModel->load($model->getId());
        $savedConfig = $savedModel->getConfig();
        
        $this->assertEquals($config, $savedConfig);
    }

    /**
     * 测试模型价格设置
     */
    public function testModelPricing(): void
    {
        $model = new AiModel();
        $model->setData([
            'vendor' => 'test-vendor',
            'model_code' => 'test-pricing-model',
            'model_name' => 'Pricing Test Model',
            'token_price_input' => 0.001,
            'token_price_output' => 0.002
        ]);
        $model->save();
        
        // 验证价格设置
        $savedModel = $this->aiModel->load($model->getId());
        $this->assertEquals(0.001, $savedModel->getTokenPriceInput());
        $this->assertEquals(0.002, $savedModel->getTokenPriceOutput());
    }

    /**
     * 测试模型删除保护
     */
    public function testModelDeletionProtection(): void
    {
        // 创建并设置为默认模型
        $model = new AiModel();
        $model->setData([
            'vendor' => 'test-vendor',
            'model_code' => 'test-protected-model',
            'model_name' => 'Protected Test Model',
            'is_default' => 1
        ]);
        $model->save();
        
        // 尝试删除默认模型
        $result = $model->delete();
        
        // 应该失败，因为模型被保护
        $this->assertFalse($result);
        
        // 验证模型仍然存在
        $existingModel = $this->aiModel->load($model->getId());
        $this->assertNotNull($existingModel);
    }

    /**
     * 测试模型版本管理
     */
    public function testModelVersionManagement(): void
    {
        $model = new AiModel();
        $model->setData([
            'vendor' => 'test-vendor',
            'model_code' => 'test-version-model',
            'model_name' => 'Version Test Model',
            'model_version' => '1.0.0'
        ]);
        $model->save();
        
        // 更新版本
        $model->setModelVersion('1.1.0');
        $result = $model->save();
        
        $this->assertTrue($result);
        
        // 验证版本更新
        $savedModel = $this->aiModel->load($model->getId());
        $this->assertEquals('1.1.0', $savedModel->getModelVersion());
    }

    /**
     * 测试模型查询功能
     */
    public function testModelQuerying(): void
    {
        // 创建多个测试模型
        $vendors = ['openai', 'google', 'anthropic'];
        foreach ($vendors as $vendor) {
            $model = new AiModel();
            $model->setData([
                'vendor' => $vendor,
                'model_code' => "test-{$vendor}-model",
                'model_name' => "Test {$vendor} Model",
                'is_active' => 1
            ]);
            $model->save();
        }
        
        // 测试按供应商查询
        $openaiModels = $this->aiModel->getCollection()
            ->where('vendor', 'openai')
            ->fetch();
        
        $this->assertNotEmpty($openaiModels);
        
        // 测试按激活状态查询
        $activeModels = $this->aiModel->getCollection()
            ->where('is_active', 1)
            ->fetch();
        
        $this->assertGreaterThanOrEqual(3, count($activeModels));
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->aiModel->getCollection()
            ->where('vendor', 'test-vendor')
            ->delete();
        
        parent::tearDown();
    }
}
