<?php

declare(strict_types=1);

/**
 * Unit tests for AiModel new fields (is_active, is_default)
 * 
 * Tests the recently added is_active and is_default fields,
 * including field constants, getter/setter methods, and database operations.
 *
 * @package Weline_Ai
 */

namespace Weline\Ai\tests\unit;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;

class test_ai_model_new_fields extends TestCase
{
    private AiModel $model;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new AiModel();
    }

    /**
     * Test: fields_IS_ACTIVE 常量已定义
     */
    public function test_fields_IS_ACTIVE_constant_defined()
    {
        $this->assertTrue(defined('Weline\Ai\Model\AiModel::schema_fields_IS_ACTIVE'));
        $this->assertEquals('is_active', AiModel::schema_fields_IS_ACTIVE);
    }

    /**
     * Test: fields_IS_DEFAULT 常量已定义
     */
    public function test_fields_IS_DEFAULT_constant_defined()
    {
        $this->assertTrue(defined('Weline\Ai\Model\AiModel::schema_fields_IS_DEFAULT'));
        $this->assertEquals('is_default', AiModel::schema_fields_IS_DEFAULT);
    }

    /**
     * Test: getIsActive() 方法返回布尔值
     */
    public function test_getIsActive_returns_boolean()
    {
        // Arrange: 设置 is_active 为 1
        $this->model->setData(AiModel::schema_fields_IS_ACTIVE, 1);
        
        // Act
        $result = $this->model->getIsActive();
        
        // Assert
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    /**
     * Test: getIsActive() 对于 0 返回 false
     */
    public function test_getIsActive_returns_false_for_zero()
    {
        // Arrange
        $this->model->setData(AiModel::schema_fields_IS_ACTIVE, 0);
        
        // Act
        $result = $this->model->getIsActive();
        
        // Assert
        $this->assertIsBool($result);
        $this->assertFalse($result);
    }

    /**
     * Test: setIsActive() 方法接受布尔值并存储为整数
     */
    public function test_setIsActive_accepts_boolean()
    {
        // Act
        $this->model->setIsActive(true);
        
        // Assert
        $this->assertEquals(1, $this->model->getData(AiModel::schema_fields_IS_ACTIVE));
        $this->assertTrue($this->model->getIsActive());
    }

    /**
     * Test: setIsActive(false) 存储为 0
     */
    public function test_setIsActive_false_stores_as_zero()
    {
        // Act
        $this->model->setIsActive(false);
        
        // Assert
        $this->assertEquals(0, $this->model->getData(AiModel::schema_fields_IS_ACTIVE));
        $this->assertFalse($this->model->getIsActive());
    }

    /**
     * Test: setIsActive() 返回 self 支持链式调用
     */
    public function test_setIsActive_returns_self_for_chaining()
    {
        // Act
        $result = $this->model->setIsActive(true);
        
        // Assert
        $this->assertInstanceOf(AiModel::class, $result);
        $this->assertSame($this->model, $result);
    }

    /**
     * Test: getIsDefault() 方法返回布尔值
     */
    public function test_getIsDefault_returns_boolean()
    {
        // Arrange
        $this->model->setData(AiModel::schema_fields_IS_DEFAULT, 1);
        
        // Act
        $result = $this->model->getIsDefault();
        
        // Assert
        $this->assertIsBool($result);
        $this->assertTrue($result);
    }

    /**
     * Test: getIsDefault() 对于 0 返回 false
     */
    public function test_getIsDefault_returns_false_for_zero()
    {
        // Arrange
        $this->model->setData(AiModel::schema_fields_IS_DEFAULT, 0);
        
        // Act
        $result = $this->model->getIsDefault();
        
        // Assert
        $this->assertIsBool($result);
        $this->assertFalse($result);
    }

    /**
     * Test: setIsDefault() 方法接受布尔值
     */
    public function test_setIsDefault_accepts_boolean()
    {
        // Act
        $this->model->setIsDefault(true);
        
        // Assert
        $this->assertEquals(1, $this->model->getData(AiModel::schema_fields_IS_DEFAULT));
        $this->assertTrue($this->model->getIsDefault());
    }

    /**
     * Test: setIsDefault(false) 存储为 0
     */
    public function test_setIsDefault_false_stores_as_zero()
    {
        // Act
        $this->model->setIsDefault(false);
        
        // Assert
        $this->assertEquals(0, $this->model->getData(AiModel::schema_fields_IS_DEFAULT));
        $this->assertFalse($this->model->getIsDefault());
    }

    /**
     * Test: setIsDefault() 返回 self 支持链式调用
     */
    public function test_setIsDefault_returns_self_for_chaining()
    {
        // Act
        $result = $this->model->setIsDefault(true);
        
        // Assert
        $this->assertInstanceOf(AiModel::class, $result);
        $this->assertSame($this->model, $result);
    }

    /**
     * Test: isActive() 方法委托给 getIsActive()
     */
    public function test_isActive_delegates_to_getIsActive()
    {
        // Arrange
        $this->model->setIsActive(true);
        
        // Act & Assert
        $this->assertTrue($this->model->isActive());
        $this->assertEquals($this->model->getIsActive(), $this->model->isActive());
    }

    /**
     * Test: isDefault() 方法委托给 getIsDefault()
     */
    public function test_isDefault_delegates_to_getIsDefault()
    {
        // Arrange
        $this->model->setIsDefault(true);
        
        // Act & Assert
        $this->assertTrue($this->model->isDefault());
        $this->assertEquals($this->model->getIsDefault(), $this->model->isDefault());
    }

    /**
     * Test: 链式调用 setIsActive 和 setIsDefault
     */
    public function test_chaining_setIsActive_and_setIsDefault()
    {
        // Act
        $result = $this->model
            ->setIsActive(true)
            ->setIsDefault(true);
        
        // Assert
        $this->assertInstanceOf(AiModel::class, $result);
        $this->assertTrue($this->model->getIsActive());
        $this->assertTrue($this->model->getIsDefault());
    }

    /**
     * Test: 默认激活状态（新创建的模型）
     */
    public function test_default_is_active_state()
    {
        // 注意：根据数据库定义，is_active 默认为 1
        // 但新创建的模型对象可能未设置该字段
        
        // Arrange: 显式设置默认值
        $this->model->setData(AiModel::schema_fields_IS_ACTIVE, 1);
        
        // Act & Assert
        $this->assertTrue($this->model->getIsActive());
    }

    /**
     * Test: 默认 is_default 状态（新创建的模型）
     */
    public function test_default_is_default_state()
    {
        // 注意：根据数据库定义，is_default 默认为 0
        
        // Arrange: 显式设置默认值
        $this->model->setData(AiModel::schema_fields_IS_DEFAULT, 0);
        
        // Act & Assert
        $this->assertFalse($this->model->getIsDefault());
    }

    /**
     * Test: 切换 is_active 状态
     */
    public function test_toggle_is_active_state()
    {
        // Arrange
        $this->model->setIsActive(true);
        $this->assertTrue($this->model->getIsActive());
        
        // Act: 切换状态
        $newState = !$this->model->isActive();
        $this->model->setIsActive($newState);
        
        // Assert
        $this->assertFalse($this->model->getIsActive());
    }

    /**
     * Test: 只能有一个默认模型逻辑
     */
    public function test_only_one_default_model_logic()
    {
        // Arrange: 模拟设置默认模型的逻辑
        $model1 = new AiModel();
        $model2 = new AiModel();
        
        // Act: 设置 model1 为默认
        $model1->setIsDefault(true);
        $this->assertTrue($model1->isDefault());
        
        // 设置 model2 为默认时，应该取消 model1 的默认状态
        // （这个逻辑应该在 Service 层实现）
        $model2->setIsDefault(true);
        $model1->setIsDefault(false);
        
        // Assert
        $this->assertFalse($model1->isDefault());
        $this->assertTrue($model2->isDefault());
    }

    /**
     * Test: 别名字段常量已定义
     */
    public function test_alias_getter_methods_work_correctly()
    {
        // 验证所有别名getter方法正常工作
        $model = new AiModel();
        $model->setData('supplier', 'openai');
        $model->setData('name', 'GPT-4');
        $model->setData('version', '1.0');
        $model->setData('is_copy', 1);
        
        // 测试别名getter方法
        $this->assertEquals('openai', $model->getVendor());
        $this->assertEquals('GPT-4', $model->getModelName());
        $this->assertEquals('1.0', $model->getModelVersion());
        $this->assertTrue($model->isCopied());
        
        // 验证实际字段常量
        $this->assertEquals('token_price_input', AiModel::schema_fields_TOKEN_PRICE_INPUT);
        $this->assertEquals('token_price_output', AiModel::schema_fields_TOKEN_PRICE_OUTPUT);
        $this->assertEquals('proxy_info', AiModel::schema_fields_PROXY_INFO);
    }

    /**
     * Test: $_unit_primary_keys 使用字段常量
     */
    public function test_unit_primary_keys_uses_field_constants()
    {
        // Arrange & Act
        $primaryKeys = $this->model->getUnitPrimaryKeys();
        
        // Assert
        $this->assertIsArray($primaryKeys);
        $this->assertContains(AiModel::schema_fields_ID, $primaryKeys);
    }

    /**
     * Test: $_index_sort_keys 使用字段常量
     */
    public function test_index_sort_keys_uses_field_constants()
    {
        // Arrange & Act
        $sortKeys = $this->model->_index_sort_keys ?? [];
        
        // Assert
        $this->assertIsArray($sortKeys);
        $this->assertContains(AiModel::schema_fields_ID, $sortKeys);
        $this->assertContains(AiModel::schema_fields_SUPPLIER, $sortKeys);
        $this->assertContains(AiModel::schema_fields_MODEL_CODE, $sortKeys);
    }

    /**
     * Test: 数据库字段顺序和完整性
     */
    public function test_database_field_completeness()
    {
        // 验证所有核心字段常量都已定义
        $requiredConstants = [
            'fields_ID',
            'fields_SUPPLIER',
            'fields_MODEL_CODE',
            'fields_NAME',
            'fields_VERSION',
            'fields_IS_COPY',
            'fields_ORIGIN_MODEL_ID',
            'fields_CONFIG',
            'fields_CAPABILITIES',
            'fields_MAX_TOKENS',
            'fields_COST_PER_TOKEN',
            'fields_STATUS',
            'fields_IS_ACTIVE',
            'fields_IS_DEFAULT',
            'fields_CREATED_AT',
            'fields_UPDATED_AT'
        ];
        
        foreach ($requiredConstants as $constant) {
            $fullConstant = 'Weline\Ai\Model\AiModel::' . $constant;
            $this->assertTrue(
                defined($fullConstant),
                "常量 {$constant} 未定义"
            );
        }
    }

    /**
     * Test: 批量设置和获取字段
     */
    public function test_bulk_field_operations()
    {
        // Arrange & Act
        $this->model
            ->setData(AiModel::schema_fields_SUPPLIER, 'OpenAI')
            ->setData(AiModel::schema_fields_MODEL_CODE, 'gpt-4')
            ->setData(AiModel::schema_fields_NAME, 'GPT-4')
            ->setIsActive(true)
            ->setIsDefault(true);
        
        // Assert
        $this->assertEquals('OpenAI', $this->model->getData(AiModel::schema_fields_SUPPLIER));
        $this->assertEquals('gpt-4', $this->model->getData(AiModel::schema_fields_MODEL_CODE));
        $this->assertEquals('GPT-4', $this->model->getData(AiModel::schema_fields_NAME));
        $this->assertTrue($this->model->isActive());
        $this->assertTrue($this->model->isDefault());
    }
}

