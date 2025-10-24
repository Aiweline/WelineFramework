<?php
declare(strict_types=1);

/**
 * AI 模型验证单元测试
 * 
 * 测试覆盖：
 * - 必填字段验证
 * - 数据类型验证
 * - 业务规则验证
 * - 状态转换验证
 * - 拷贝模型验证
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiModel;

class AiModelValidationTest extends TestCase
{
    private AiModel $aiModel;

    protected function setUp(): void
    {
        parent::setUp();
        // 使用 ObjectManager 或直接实例化（根据框架要求）
        $this->aiModel = new AiModel();
    }

    /**
     * 测试：supplier 字段必填
     */
    public function testSupplierRequired(): void
    {
        $this->aiModel->setData([
            'model_code' => 'test-model',
            'name' => 'Test Model',
            'version' => '1.0',
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Supplier is required');
    }

    /**
     * 测试：model_code 字段必填
     */
    public function testModelCodeRequired(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'name' => 'Test Model',
            'version' => '1.0',
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Model code is required');
    }

    /**
     * 测试：name 字段必填
     */
    public function testNameRequired(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'test-model',
            'version' => '1.0',
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Name is required');
    }

    /**
     * 测试：version 字段必填
     */
    public function testVersionRequired(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'test-model',
            'name' => 'Test Model',
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Version is required');
    }

    /**
     * 测试：所有必填字段都提供时验证通过
     */
    public function testValidModelData(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo',
            'name' => 'GPT-3.5 Turbo',
            'version' => '1.0',
            'is_copy' => false,
            'status' => AiModel::STATUS_ACTIVE,
        ]);

        $this->assertTrue($this->aiModel->validate(), 'Valid model data should pass validation');
    }

    /**
     * 测试：拷贝模型必须指定 origin_model_id
     */
    public function testCopyModelRequiresOriginId(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo-copy',
            'name' => 'GPT-3.5 Turbo Copy',
            'version' => '1.0',
            'is_copy' => true,
            'origin_model_id' => null, // 应该失败
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Copy model requires origin_model_id');
    }

    /**
     * 测试：原始模型不能有 origin_model_id
     */
    public function testOriginalModelCannotHaveOriginId(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-4',
            'name' => 'GPT-4',
            'version' => '1.0',
            'is_copy' => false,
            'origin_model_id' => 123, // 应该失败
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Original model cannot have origin_model_id');
    }

    /**
     * 测试：cost_per_token 必须 >= 0
     */
    public function testCostPerTokenMustBeNonNegative(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo',
            'name' => 'GPT-3.5 Turbo',
            'version' => '1.0',
            'cost_per_token' => -0.01, // 应该失败
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Cost per token must be >= 0');
    }

    /**
     * 测试：max_tokens 必须 > 0
     */
    public function testMaxTokensMustBePositive(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo',
            'name' => 'GPT-3.5 Turbo',
            'version' => '1.0',
            'max_tokens' => 0, // 应该失败
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Max tokens must be > 0');
    }

    /**
     * 测试：status 必须是有效值
     */
    public function testStatusMustBeValid(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo',
            'name' => 'GPT-3.5 Turbo',
            'version' => '1.0',
            'status' => 'invalid_status', // 应该失败
        ]);

        $this->assertFalse($this->aiModel->validate(), 'Status must be valid');
    }

    /**
     * 测试：JSON 字段格式验证
     */
    public function testJsonFieldsValidation(): void
    {
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo',
            'name' => 'GPT-3.5 Turbo',
            'version' => '1.0',
            'config' => ['temperature' => 0.7, 'top_p' => 0.9],
            'capabilities' => ['chat' => true, 'completion' => true],
        ]);

        $this->assertTrue($this->aiModel->validate(), 'Valid JSON fields should pass');
    }

    /**
     * 测试：状态转换 - active 到 deprecated
     */
    public function testStatusTransitionActiveTodeprecated(): void
    {
        $this->aiModel->setData('status', AiModel::STATUS_ACTIVE);
        
        $canTransition = $this->aiModel->canTransitionTo(AiModel::STATUS_DEPRECATED);
        
        $this->assertTrue($canTransition, 'Can transition from active to deprecated');
    }

    /**
     * 测试：状态转换 - deprecated 到 maintenance
     */
    public function testStatusTransitionDeprecatedToMaintenance(): void
    {
        $this->aiModel->setData('status', AiModel::STATUS_DEPRECATED);
        
        $canTransition = $this->aiModel->canTransitionTo(AiModel::STATUS_MAINTENANCE);
        
        $this->assertTrue($canTransition, 'Can transition from deprecated to maintenance');
    }

    /**
     * 测试：supplier 和 model_code 组合唯一性检查
     */
    public function testSupplierModelCodeUniqueness(): void
    {
        $model1 = new AiModel();
        $model1->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo-unique-test',
            'name' => 'Test Model 1',
            'version' => '1.0',
        ]);
        $model1->save();

        $model2 = new AiModel();
        $model2->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo-unique-test', // 相同的组合
            'name' => 'Test Model 2',
            'version' => '1.0',
        ]);

        $this->assertFalse($model2->validate(), 'Supplier and model_code combination must be unique');
        
        // 清理
        $model1->delete();
    }

    /**
     * 测试：字段长度验证
     */
    public function testFieldLengthValidation(): void
    {
        // supplier 超长
        $this->aiModel->setData([
            'supplier' => str_repeat('A', 101), // 超过100字符
            'model_code' => 'test',
            'name' => 'Test',
            'version' => '1.0',
        ]);
        $this->assertFalse($this->aiModel->validate(), 'Supplier should not exceed 100 characters');

        // model_code 超长
        $this->aiModel->setData([
            'supplier' => 'OpenAI',
            'model_code' => str_repeat('A', 101), // 超过100字符
            'name' => 'Test',
            'version' => '1.0',
        ]);
        $this->assertFalse($this->aiModel->validate(), 'Model code should not exceed 100 characters');
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        // $this->aiModel->getCollection()->where('model_code', 'like', '%test%')->delete();
        parent::tearDown();
    }
}

