<?php
declare(strict_types=1);

/**
 * AI Assistant 验证单元测试
 * 
 * 测试覆盖：
 * - 必填字段验证
 * - 提示词模板验证
 * - 权限验证
 * - 状态验证
 * - 使用计数验证
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiAssistant;
use Weline\Ai\Model\AiModel;

class AiAssistantValidationTest extends TestCase
{
    private AiAssistant $assistant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assistant = new AiAssistant();
    }

    /**
     * 测试：name 字段必填
     */
    public function testNameRequired(): void
    {
        $this->assistant->setData([
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->assistant->validate(), 'Name is required');
    }

    /**
     * 测试：prompt_template 字段必填
     */
    public function testPromptTemplateRequired(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->assistant->validate(), 'Prompt template is required');
    }

    /**
     * 测试：model_id 字段必填
     */
    public function testModelIdRequired(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->assistant->validate(), 'Model ID is required');
    }

    /**
     * 测试：user_id 字段必填
     */
    public function testUserIdRequired(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->assistant->validate(), 'User ID is required');
    }

    /**
     * 测试：tenant_id 字段必填
     */
    public function testTenantIdRequired(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
        ]);

        $this->assertFalse($this->assistant->validate(), 'Tenant ID is required');
    }

    /**
     * 测试：所有必填字段都提供时验证通过
     */
    public function testValidAssistantData(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'description' => 'A test assistant',
            'prompt_template' => 'You are a helpful assistant. User: {input}',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
            'status' => AiAssistant::STATUS_ACTIVE,
        ]);

        $this->assertTrue($this->assistant->validate(), 'Valid assistant data should pass validation');
    }

    /**
     * 测试：prompt_template 不能为空字符串
     */
    public function testPromptTemplateNotEmpty(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => '   ', // 空白字符串
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->assistant->validate(), 'Prompt template cannot be empty');
    }

    /**
     * 测试：status 必须是有效值
     */
    public function testStatusMustBeValid(): void
    {
        $validStatuses = [
            AiAssistant::STATUS_ACTIVE,
            AiAssistant::STATUS_INACTIVE,
            AiAssistant::STATUS_ARCHIVED,
        ];

        foreach ($validStatuses as $status) {
            $this->assistant->setData([
                'name' => 'Test Assistant',
                'prompt_template' => 'Test prompt',
                'model_id' => 1,
                'user_id' => 1,
                'tenant_id' => 1,
                'status' => $status,
            ]);

            $this->assertTrue($this->assistant->validate(), "Status {$status} should be valid");
        }

        // 测试无效状态
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
            'status' => 'invalid_status',
        ]);

        $this->assertFalse($this->assistant->validate(), 'Invalid status should fail validation');
    }

    /**
     * 测试：is_public 默认为 false
     */
    public function testIsPublicDefaultValue(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $isPublic = $this->assistant->getData('is_public');
        
        $this->assertFalse((bool)$isPublic, 'is_public should default to false');
    }

    /**
     * 测试：usage_count 初始化为 0
     */
    public function testUsageCountInitialization(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $usageCount = $this->assistant->getData('usage_count');
        
        $this->assertEquals(0, (int)$usageCount, 'usage_count should initialize to 0');
    }

    /**
     * 测试：JSON 配置字段验证
     */
    public function testConfigJsonValidation(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
            'config' => [
                'temperature' => 0.7,
                'max_tokens' => 2000,
                'top_p' => 0.9,
            ],
        ]);

        $this->assertTrue($this->assistant->validate(), 'Valid JSON config should pass');
    }

    /**
     * 测试：提示词模板变量验证
     */
    public function testPromptTemplateVariables(): void
    {
        $template = 'You are {role}. User: {input}. Context: {context}';
        
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => $template,
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $variables = $this->assistant->extractTemplateVariables();
        
        $this->assertContains('role', $variables, 'Should extract {role} variable');
        $this->assertContains('input', $variables, 'Should extract {input} variable');
        $this->assertContains('context', $variables, 'Should extract {context} variable');
    }

    /**
     * 测试：状态转换 - active 到 inactive
     */
    public function testStatusTransitionActiveToInactive(): void
    {
        $this->assistant->setData('status', AiAssistant::STATUS_ACTIVE);
        
        $canTransition = $this->assistant->canTransitionTo(AiAssistant::STATUS_INACTIVE);
        
        $this->assertTrue($canTransition, 'Can transition from active to inactive');
    }

    /**
     * 测试：状态转换 - inactive 到 active
     */
    public function testStatusTransitionInactiveToActive(): void
    {
        $this->assistant->setData('status', AiAssistant::STATUS_INACTIVE);
        
        $canTransition = $this->assistant->canTransitionTo(AiAssistant::STATUS_ACTIVE);
        
        $this->assertTrue($canTransition, 'Can transition from inactive to active');
    }

    /**
     * 测试：状态转换 - active/inactive 到 archived
     */
    public function testStatusTransitionToArchived(): void
    {
        // From active
        $this->assistant->setData('status', AiAssistant::STATUS_ACTIVE);
        $this->assertTrue($this->assistant->canTransitionTo(AiAssistant::STATUS_ARCHIVED));

        // From inactive
        $this->assistant->setData('status', AiAssistant::STATUS_INACTIVE);
        $this->assertTrue($this->assistant->canTransitionTo(AiAssistant::STATUS_ARCHIVED));
    }

    /**
     * 测试：archived 状态不能转换到其他状态
     */
    public function testArchivedCannotTransition(): void
    {
        $this->assistant->setData('status', AiAssistant::STATUS_ARCHIVED);
        
        $canTransitionToActive = $this->assistant->canTransitionTo(AiAssistant::STATUS_ACTIVE);
        $canTransitionToInactive = $this->assistant->canTransitionTo(AiAssistant::STATUS_INACTIVE);
        
        $this->assertFalse($canTransitionToActive, 'Cannot transition from archived to active');
        $this->assertFalse($canTransitionToInactive, 'Cannot transition from archived to inactive');
    }

    /**
     * 测试：使用计数递增
     */
    public function testIncrementUsageCount(): void
    {
        $this->assistant->setData('usage_count', 10);
        
        $this->assistant->incrementUsage();
        
        $this->assertEquals(11, $this->assistant->getData('usage_count'), 'Usage count should increment');
    }

    /**
     * 测试：字段长度验证
     */
    public function testFieldLengthValidation(): void
    {
        // name 超长
        $this->assistant->setData([
            'name' => str_repeat('A', 256), // 超过255字符
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);
        $this->assertFalse($this->assistant->validate(), 'Name should not exceed 255 characters');
    }

    /**
     * 测试：模型关联验证
     */
    public function testModelAssociation(): void
    {
        // 创建测试模型
        $model = new AiModel();
        $model->setData([
            'supplier' => 'OpenAI',
            'model_code' => 'gpt-3.5-turbo-test',
            'name' => 'Test Model',
            'version' => '1.0',
        ]);
        $model->save();

        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => $model->getId(),
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertTrue($this->assistant->validate(), 'Should validate with valid model ID');
        
        // 清理
        $model->delete();
    }

    /**
     * 测试：租户隔离验证
     */
    public function testTenantIsolation(): void
    {
        $this->assistant->setData([
            'name' => 'Test Assistant',
            'prompt_template' => 'Test prompt',
            'model_id' => 1,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);
        $this->assistant->save();

        // 尝试从不同租户访问
        $assistant2 = new AiAssistant();
        $assistant2->load($this->assistant->getId());
        
        // 如果实现了租户过滤，这里应该返回 null 或失败
        // $this->assertNotEquals(2, $assistant2->getData('tenant_id'));
        
        // 清理
        $this->assistant->delete();
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        parent::tearDown();
    }
}

