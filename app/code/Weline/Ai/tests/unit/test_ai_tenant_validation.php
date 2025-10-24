<?php
declare(strict_types=1);

/**
 * AI Tenant 验证单元测试
 * 
 * 测试覆盖：
 * - 必填字段验证
 * - 域名唯一性验证
 * - 配额验证
 * - 状态验证
 * - 计费计划验证
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiTenant;

class AiTenantValidationTest extends TestCase
{
    private AiTenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = new AiTenant();
    }

    /**
     * 测试：name 字段必填
     */
    public function testNameRequired(): void
    {
        $this->tenant->setData([
            'status' => AiTenant::STATUS_ACTIVE,
        ]);

        $this->assertFalse($this->tenant->validate(), 'Name is required');
    }

    /**
     * 测试：所有必填字段都提供时验证通过
     */
    public function testValidTenantData(): void
    {
        $this->tenant->setData([
            'name' => 'Test Tenant',
            'domain' => 'test.example.com',
            'status' => AiTenant::STATUS_ACTIVE,
            'billing_plan' => AiTenant::PLAN_FREE,
        ]);

        $this->assertTrue($this->tenant->validate(), 'Valid tenant data should pass validation');
    }

    /**
     * 测试：domain 必须唯一
     */
    public function testDomainUniqueness(): void
    {
        $domain = 'unique-test-' . time() . '.example.com';

        $tenant1 = new AiTenant();
        $tenant1->setData([
            'name' => 'Tenant 1',
            'domain' => $domain,
        ]);
        $tenant1->save();

        $tenant2 = new AiTenant();
        $tenant2->setData([
            'name' => 'Tenant 2',
            'domain' => $domain, // 相同的域名
        ]);

        $this->assertFalse($tenant2->validate(), 'Domain must be unique');
        
        // 清理
        $tenant1->delete();
    }

    /**
     * 测试：domain 格式验证
     */
    public function testDomainFormatValidation(): void
    {
        // 有效域名
        $validDomains = [
            'example.com',
            'test.example.com',
            'sub.test.example.com',
            'example-test.com',
        ];

        foreach ($validDomains as $domain) {
            $this->tenant->setData([
                'name' => 'Test Tenant',
                'domain' => $domain,
            ]);
            $this->assertTrue($this->tenant->validate(), "Domain {$domain} should be valid");
        }

        // 无效域名
        $invalidDomains = [
            'invalid domain',
            'test..example.com',
            '-example.com',
            'example-.com',
        ];

        foreach ($invalidDomains as $domain) {
            $this->tenant->setData([
                'name' => 'Test Tenant',
                'domain' => $domain,
            ]);
            $this->assertFalse($this->tenant->validate(), "Domain {$domain} should be invalid");
        }
    }

    /**
     * 测试：quota_monthly 必须 > 0
     */
    public function testQuotaMonthlyMustBePositive(): void
    {
        $this->tenant->setData([
            'name' => 'Test Tenant',
            'quota_monthly' => 0, // 应该失败
        ]);

        $this->assertFalse($this->tenant->validate(), 'Quota monthly must be > 0 if set');
    }

    /**
     * 测试：billing_plan 必须是有效值
     */
    public function testBillingPlanMustBeValid(): void
    {
        $validPlans = [
            AiTenant::PLAN_FREE,
            AiTenant::PLAN_BASIC,
            AiTenant::PLAN_PREMIUM,
            AiTenant::PLAN_ENTERPRISE,
        ];

        foreach ($validPlans as $plan) {
            $this->tenant->setData([
                'name' => 'Test Tenant',
                'billing_plan' => $plan,
            ]);
            $this->assertTrue($this->tenant->validate(), "Plan {$plan} should be valid");
        }

        // 测试无效计划
        $this->tenant->setData([
            'name' => 'Test Tenant',
            'billing_plan' => 'invalid_plan',
        ]);
        $this->assertFalse($this->tenant->validate(), 'Invalid billing plan should fail validation');
    }

    /**
     * 测试：status 必须是有效值
     */
    public function testStatusMustBeValid(): void
    {
        $validStatuses = [
            AiTenant::STATUS_ACTIVE,
            AiTenant::STATUS_SUSPENDED,
            AiTenant::STATUS_CANCELLED,
        ];

        foreach ($validStatuses as $status) {
            $this->tenant->setData([
                'name' => 'Test Tenant',
                'status' => $status,
            ]);
            $this->assertTrue($this->tenant->validate(), "Status {$status} should be valid");
        }

        // 测试无效状态
        $this->tenant->setData([
            'name' => 'Test Tenant',
            'status' => 'invalid_status',
        ]);
        $this->assertFalse($this->tenant->validate(), 'Invalid status should fail validation');
    }

    /**
     * 测试：usage_monthly 初始化为 0
     */
    public function testUsageMonthlyInitialization(): void
    {
        $this->tenant->setData([
            'name' => 'Test Tenant',
        ]);

        $usage = $this->tenant->getData('usage_monthly');
        
        $this->assertEquals(0, (int)$usage, 'usage_monthly should initialize to 0');
    }

    /**
     * 测试：JSON 配置字段验证
     */
    public function testConfigJsonValidation(): void
    {
        $this->tenant->setData([
            'name' => 'Test Tenant',
            'config' => [
                'features' => ['ai_chat', 'ai_assistant'],
                'limits' => ['max_users' => 100],
                'settings' => ['timezone' => 'UTC'],
            ],
        ]);

        $this->assertTrue($this->tenant->validate(), 'Valid JSON config should pass');
    }

    /**
     * 测试：状态转换 - active 到 suspended
     */
    public function testStatusTransitionActiveToSuspended(): void
    {
        $this->tenant->setData('status', AiTenant::STATUS_ACTIVE);
        
        $canTransition = $this->tenant->canTransitionTo(AiTenant::STATUS_SUSPENDED);
        
        $this->assertTrue($canTransition, 'Can transition from active to suspended');
    }

    /**
     * 测试：状态转换 - suspended 到 active
     */
    public function testStatusTransitionSuspendedToActive(): void
    {
        $this->tenant->setData('status', AiTenant::STATUS_SUSPENDED);
        
        $canTransition = $this->tenant->canTransitionTo(AiTenant::STATUS_ACTIVE);
        
        $this->assertTrue($canTransition, 'Can transition from suspended to active');
    }

    /**
     * 测试：状态转换 - active 到 cancelled
     */
    public function testStatusTransitionActiveToCancelled(): void
    {
        $this->tenant->setData('status', AiTenant::STATUS_ACTIVE);
        
        $canTransition = $this->tenant->canTransitionTo(AiTenant::STATUS_CANCELLED);
        
        $this->assertTrue($canTransition, 'Can transition from active to cancelled');
    }

    /**
     * 测试：cancelled 状态不能恢复
     */
    public function testCancelledCannotRestore(): void
    {
        $this->tenant->setData('status', AiTenant::STATUS_CANCELLED);
        
        $canTransitionToActive = $this->tenant->canTransitionTo(AiTenant::STATUS_ACTIVE);
        $canTransitionToSuspended = $this->tenant->canTransitionTo(AiTenant::STATUS_SUSPENDED);
        
        $this->assertFalse($canTransitionToActive, 'Cannot transition from cancelled to active');
        $this->assertFalse($canTransitionToSuspended, 'Cannot transition from cancelled to suspended');
    }

    /**
     * 测试：检查配额 - 有剩余配额
     */
    public function testHasQuotaWithRemaining(): void
    {
        $this->tenant->setData([
            'quota_monthly' => 1000,
            'usage_monthly' => 500,
        ]);

        $this->assertTrue($this->tenant->hasQuota(), 'Should have quota when usage < limit');
    }

    /**
     * 测试：检查配额 - 配额耗尽
     */
    public function testHasQuotaExceeded(): void
    {
        $this->tenant->setData([
            'quota_monthly' => 1000,
            'usage_monthly' => 1000,
        ]);

        $this->assertFalse($this->tenant->hasQuota(), 'Should not have quota when limit reached');
    }

    /**
     * 测试：使用量递增
     */
    public function testIncrementUsage(): void
    {
        $this->tenant->setData('usage_monthly', 100);
        
        $this->tenant->incrementUsage(10);
        
        $this->assertEquals(110, $this->tenant->getData('usage_monthly'), 'Usage should increment by amount');
    }

    /**
     * 测试：计费计划升级
     */
    public function testBillingPlanUpgrade(): void
    {
        $this->tenant->setData('billing_plan', AiTenant::PLAN_FREE);
        
        $canUpgrade = $this->tenant->canUpgradeTo(AiTenant::PLAN_BASIC);
        
        $this->assertTrue($canUpgrade, 'Can upgrade from free to basic');

        $canUpgradeToPremium = $this->tenant->canUpgradeTo(AiTenant::PLAN_PREMIUM);
        
        $this->assertTrue($canUpgradeToPremium, 'Can upgrade from free to premium');
    }

    /**
     * 测试：计费计划降级
     */
    public function testBillingPlanDowngrade(): void
    {
        $this->tenant->setData('billing_plan', AiTenant::PLAN_ENTERPRISE);
        
        $canDowngrade = $this->tenant->canDowngradeTo(AiTenant::PLAN_PREMIUM);
        
        $this->assertTrue($canDowngrade, 'Can downgrade from enterprise to premium');

        $canDowngradeToFree = $this->tenant->canDowngradeTo(AiTenant::PLAN_FREE);
        
        $this->assertTrue($canDowngradeToFree, 'Can downgrade from enterprise to free');
    }

    /**
     * 测试：字段长度验证
     */
    public function testFieldLengthValidation(): void
    {
        // name 超长
        $this->tenant->setData([
            'name' => str_repeat('A', 256), // 超过255字符
        ]);
        $this->assertFalse($this->tenant->validate(), 'Name should not exceed 255 characters');

        // domain 超长
        $this->tenant->setData([
            'name' => 'Test Tenant',
            'domain' => str_repeat('a', 244) . '.example.com', // 超过255字符
        ]);
        $this->assertFalse($this->tenant->validate(), 'Domain should not exceed 255 characters');
    }

    /**
     * 测试：租户激活检查
     */
    public function testIsActive(): void
    {
        $this->tenant->setData('status', AiTenant::STATUS_ACTIVE);
        $this->assertTrue($this->tenant->isActive(), 'Should be active');

        $this->tenant->setData('status', AiTenant::STATUS_SUSPENDED);
        $this->assertFalse($this->tenant->isActive(), 'Should not be active when suspended');

        $this->tenant->setData('status', AiTenant::STATUS_CANCELLED);
        $this->assertFalse($this->tenant->isActive(), 'Should not be active when cancelled');
    }

    /**
     * 测试：获取配额使用率
     */
    public function testGetQuotaUsagePercentage(): void
    {
        $this->tenant->setData([
            'quota_monthly' => 1000,
            'usage_monthly' => 500,
        ]);

        $percentage = $this->tenant->getQuotaUsagePercentage();
        
        $this->assertEquals(50.0, $percentage, 'Usage percentage should be 50%');
    }

    /**
     * 测试：无配额限制时的使用率
     */
    public function testGetQuotaUsagePercentageWithoutLimit(): void
    {
        $this->tenant->setData([
            'quota_monthly' => null,
            'usage_monthly' => 500,
        ]);

        $percentage = $this->tenant->getQuotaUsagePercentage();
        
        $this->assertEquals(0.0, $percentage, 'Usage percentage should be 0 when no limit set');
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        parent::tearDown();
    }
}

