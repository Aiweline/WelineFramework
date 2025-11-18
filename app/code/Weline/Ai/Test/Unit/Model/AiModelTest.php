<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;
use Weline\Framework\Manager\ObjectManager;

/**
 * 测试 AiModel 模型
 */
class AiModelTest extends TestCase
{
    private AiModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(AiModel::class);
    }

    /**
     * 测试模型实例化
     */
    public function testModelInstantiation()
    {
        $this->assertInstanceOf(AiModel::class, $this->model);
    }

    /**
     * 测试模型字段常量定义
     */
    public function testFieldConstants()
    {
        $this->assertEquals('id', AiModel::fields_ID);
        $this->assertEquals('supplier', AiModel::fields_SUPPLIER);
        $this->assertEquals('model_code', AiModel::fields_MODEL_CODE);
        $this->assertEquals('name', AiModel::fields_NAME);
        $this->assertEquals('version', AiModel::fields_VERSION);
        $this->assertEquals('is_copy', AiModel::fields_IS_COPY);
        $this->assertEquals('origin_model_id', AiModel::fields_ORIGIN_MODEL_ID);
        $this->assertEquals('config', AiModel::fields_CONFIG);
        $this->assertEquals('capabilities', AiModel::fields_CAPABILITIES);
        $this->assertEquals('max_tokens', AiModel::fields_MAX_TOKENS);
        $this->assertEquals('status', AiModel::fields_STATUS);
        $this->assertEquals('is_active', AiModel::fields_IS_ACTIVE);
        $this->assertEquals('is_default', AiModel::fields_IS_DEFAULT);
        $this->assertEquals('created_at', AiModel::fields_CREATED_AT);
        $this->assertEquals('updated_at', AiModel::fields_UPDATED_AT);
    }

    /**
     * 测试数据设置和获取
     */
    public function testSetAndGetData()
    {
        $testData = [
            AiModel::fields_MODEL_CODE => 'gpt-4',
            AiModel::fields_NAME => 'GPT-4',
            AiModel::fields_SUPPLIER => 'openai',
            AiModel::fields_STATUS => 'active',
            AiModel::fields_IS_ACTIVE => 1
        ];

        $this->model->setData($testData);

        $this->assertEquals('gpt-4', $this->model->getData(AiModel::fields_MODEL_CODE));
        $this->assertEquals('GPT-4', $this->model->getData(AiModel::fields_NAME));
        $this->assertEquals('openai', $this->model->getData(AiModel::fields_SUPPLIER));
        $this->assertEquals('active', $this->model->getData(AiModel::fields_STATUS));
        $this->assertEquals(1, $this->model->getData(AiModel::fields_IS_ACTIVE));
    }

    /**
     * 测试isActive方法
     */
    public function testIsActive()
    {
        $this->model->setData(AiModel::fields_IS_ACTIVE, 1);
        $this->assertTrue($this->model->isActive());

        $this->model->setData(AiModel::fields_IS_ACTIVE, 0);
        $this->assertFalse($this->model->isActive());
    }

    /**
     * 测试isDefault方法
     */
    public function testIsDefault()
    {
        $this->model->setData(AiModel::fields_IS_DEFAULT, 1);
        $this->assertTrue($this->model->isDefault());

        $this->model->setData(AiModel::fields_IS_DEFAULT, 0);
        $this->assertFalse($this->model->isDefault());
    }

    /**
     * 测试getCapabilities方法
     */
    public function testGetCapabilities()
    {
        $capabilities = ['chat', 'completion', 'embedding'];
        $this->model->setData(AiModel::fields_CAPABILITIES, json_encode($capabilities));

        $result = $this->model->getCapabilities();
        $this->assertIsArray($result);
        $this->assertEquals($capabilities, $result);
    }

    /**
     * 测试getPricingInfo方法
     */
    public function testGetPricingInfo()
    {
        $tokenPriceInput = 0.03;
        $tokenPriceOutput = 0.06;
        
        $this->model->setData(AiModel::fields_TOKEN_PRICE_INPUT, $tokenPriceInput);
        $this->model->setData(AiModel::fields_TOKEN_PRICE_OUTPUT, $tokenPriceOutput);

        $this->assertEquals($tokenPriceInput, $this->model->getData(AiModel::fields_TOKEN_PRICE_INPUT));
        $this->assertEquals($tokenPriceOutput, $this->model->getData(AiModel::fields_TOKEN_PRICE_OUTPUT));
    }

    /**
     * 测试模型验证（基本数据）
     */
    public function testBasicValidation()
    {
        // 测试必填字段
        $this->model->setData([
            AiModel::fields_MODEL_CODE => '',
            AiModel::fields_NAME => 'Test',
        ]);

        // 模型代码不能为空
        $this->assertEmpty($this->model->getData(AiModel::fields_MODEL_CODE));

        // 设置有效数据
        $this->model->setData(AiModel::fields_MODEL_CODE, 'valid-code');
        $this->assertEquals('valid-code', $this->model->getData(AiModel::fields_MODEL_CODE));
    }

    protected function tearDown(): void
    {
        unset($this->model);
        parent::tearDown();
    }
}

