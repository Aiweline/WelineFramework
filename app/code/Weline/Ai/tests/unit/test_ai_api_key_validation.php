<?php
declare(strict_types=1);

/**
 * AI API Key 验证单元测试
 * 
 * 测试覆盖：
 * - 必填字段验证
 * - 配额验证
 * - 过期时间验证
 * - 状态转换验证
 * - 令牌唯一性验证
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Service\AiApiKeyService;
use Weline\Ai\Service\SecretStoreService;

class AiApiKeyValidationTest extends TestCase
{
    private AiApiKey $apiKey;
    private AiApiKeyService $apiKeyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiKey = new AiApiKey();
        // $this->apiKeyService = new AiApiKeyService(
        //     new AiApiKey(),
        //     new SecretStoreService(new \Weline\Framework\App\Env())
        // );
    }

    /**
     * 测试：name 字段必填
     */
    public function testNameRequired(): void
    {
        $this->apiKey->setData([
            'token' => 'sk-test-token',
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->apiKey->validate(), 'Name is required');
    }

    /**
     * 测试：token 字段必填
     */
    public function testTokenRequired(): void
    {
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'user_id' => 1,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->apiKey->validate(), 'Token is required');
    }

    /**
     * 测试：user_id 字段必填
     */
    public function testUserIdRequired(): void
    {
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-test-token',
            'tenant_id' => 1,
        ]);

        $this->assertFalse($this->apiKey->validate(), 'User ID is required');
    }

    /**
     * 测试：tenant_id 字段必填
     */
    public function testTenantIdRequired(): void
    {
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-test-token',
            'user_id' => 1,
        ]);

        $this->assertFalse($this->apiKey->validate(), 'Tenant ID is required');
    }

    /**
     * 测试：所有必填字段都提供时验证通过
     */
    public function testValidApiKeyData(): void
    {
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-test-token-' . time(),
            'user_id' => 1,
            'tenant_id' => 1,
            'status' => AiApiKey::STATUS_APPROVED,
        ]);

        $this->assertTrue($this->apiKey->validate(), 'Valid API key data should pass validation');
    }

    /**
     * 测试：quota_daily 必须 > 0
     */
    public function testQuotaDailyMustBePositive(): void
    {
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-test-token',
            'user_id' => 1,
            'tenant_id' => 1,
            'quota_daily' => 0, // 应该失败
        ]);

        $this->assertFalse($this->apiKey->validate(), 'Quota daily must be > 0 if set');
    }

    /**
     * 测试：quota_monthly 必须 > 0
     */
    public function testQuotaMonthlyMustBePositive(): void
    {
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-test-token',
            'user_id' => 1,
            'tenant_id' => 1,
            'quota_monthly' => -100, // 应该失败
        ]);

        $this->assertFalse($this->apiKey->validate(), 'Quota monthly must be > 0 if set');
    }

    /**
     * 测试：expires_at 必须在未来
     */
    public function testExpiresAtMustBeInFuture(): void
    {
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-test-token',
            'user_id' => 1,
            'tenant_id' => 1,
            'expires_at' => date('Y-m-d H:i:s', strtotime('-1 day')), // 过去的时间
        ]);

        $this->assertFalse($this->apiKey->validate(), 'Expires at must be in the future');
    }

    /**
     * 测试：status 必须是有效值
     */
    public function testStatusMustBeValid(): void
    {
        $validStatuses = [
            AiApiKey::STATUS_PENDING,
            AiApiKey::STATUS_APPROVED,
            AiApiKey::STATUS_SUSPENDED,
            AiApiKey::STATUS_REVOKED,
        ];

        foreach ($validStatuses as $status) {
            $this->apiKey->setData([
                'name' => 'Test API Key',
                'token' => 'sk-test-token-' . $status,
                'user_id' => 1,
                'tenant_id' => 1,
                'status' => $status,
            ]);

            $this->assertTrue($this->apiKey->validate(), "Status {$status} should be valid");
        }

        // 测试无效状态
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-test-token-invalid',
            'user_id' => 1,
            'tenant_id' => 1,
            'status' => 'invalid_status',
        ]);

        $this->assertFalse($this->apiKey->validate(), 'Invalid status should fail validation');
    }

    /**
     * 测试：状态转换 - pending 到 approved
     */
    public function testStatusTransitionPendingToApproved(): void
    {
        $this->apiKey->setData('status', AiApiKey::STATUS_PENDING);
        
        $canTransition = $this->apiKey->canTransitionTo(AiApiKey::STATUS_APPROVED);
        
        $this->assertTrue($canTransition, 'Can transition from pending to approved');
    }

    /**
     * 测试：状态转换 - approved 到 suspended
     */
    public function testStatusTransitionApprovedToSuspended(): void
    {
        $this->apiKey->setData('status', AiApiKey::STATUS_APPROVED);
        
        $canTransition = $this->apiKey->canTransitionTo(AiApiKey::STATUS_SUSPENDED);
        
        $this->assertTrue($canTransition, 'Can transition from approved to suspended');
    }

    /**
     * 测试：状态转换 - suspended 到 approved
     */
    public function testStatusTransitionSuspendedToApproved(): void
    {
        $this->apiKey->setData('status', AiApiKey::STATUS_SUSPENDED);
        
        $canTransition = $this->apiKey->canTransitionTo(AiApiKey::STATUS_APPROVED);
        
        $this->assertTrue($canTransition, 'Can transition from suspended to approved');
    }

    /**
     * 测试：状态转换 - approved 到 revoked (不可逆)
     */
    public function testStatusTransitionApprovedToRevoked(): void
    {
        $this->apiKey->setData('status', AiApiKey::STATUS_APPROVED);
        
        $canTransition = $this->apiKey->canTransitionTo(AiApiKey::STATUS_REVOKED);
        
        $this->assertTrue($canTransition, 'Can transition from approved to revoked');
    }

    /**
     * 测试：状态转换 - revoked 不能转换到其他状态
     */
    public function testRevokedCannotTransition(): void
    {
        $this->apiKey->setData('status', AiApiKey::STATUS_REVOKED);
        
        $canTransitionToApproved = $this->apiKey->canTransitionTo(AiApiKey::STATUS_APPROVED);
        $canTransitionToSuspended = $this->apiKey->canTransitionTo(AiApiKey::STATUS_SUSPENDED);
        
        $this->assertFalse($canTransitionToApproved, 'Cannot transition from revoked to approved');
        $this->assertFalse($canTransitionToSuspended, 'Cannot transition from revoked to suspended');
    }

    /**
     * 测试：检查配额 - 有剩余配额
     */
    public function testHasQuotaWithRemaining(): void
    {
        $this->apiKey->setData([
            'quota_daily' => 100,
            'usage_daily' => 50,
            'quota_monthly' => 1000,
            'usage_monthly' => 500,
        ]);

        $this->assertTrue($this->apiKey->hasQuota(), 'Should have quota when usage < limit');
    }

    /**
     * 测试：检查配额 - 日配额耗尽
     */
    public function testHasQuotaDailyExceeded(): void
    {
        $this->apiKey->setData([
            'quota_daily' => 100,
            'usage_daily' => 100,
            'quota_monthly' => 1000,
            'usage_monthly' => 500,
        ]);

        $this->assertFalse($this->apiKey->hasQuota(), 'Should not have quota when daily limit reached');
    }

    /**
     * 测试：检查配额 - 月配额耗尽
     */
    public function testHasQuotaMonthlyExceeded(): void
    {
        $this->apiKey->setData([
            'quota_daily' => 100,
            'usage_daily' => 50,
            'quota_monthly' => 1000,
            'usage_monthly' => 1000,
        ]);

        $this->assertFalse($this->apiKey->hasQuota(), 'Should not have quota when monthly limit reached');
    }

    /**
     * 测试：令牌唯一性
     */
    public function testTokenUniqueness(): void
    {
        $token = 'sk-test-unique-token-' . time();

        $apiKey1 = new AiApiKey();
        $apiKey1->setData([
            'name' => 'Test API Key 1',
            'token' => $token,
            'user_id' => 1,
            'tenant_id' => 1,
        ]);
        $apiKey1->save();

        $apiKey2 = new AiApiKey();
        $apiKey2->setData([
            'name' => 'Test API Key 2',
            'token' => $token, // 相同的令牌
            'user_id' => 2,
            'tenant_id' => 1,
        ]);

        $this->assertFalse($apiKey2->validate(), 'Token must be unique');
        
        // 清理
        $apiKey1->delete();
    }

    /**
     * 测试：字段长度验证
     */
    public function testFieldLengthValidation(): void
    {
        // name 超长
        $this->apiKey->setData([
            'name' => str_repeat('A', 256), // 超过255字符
            'token' => 'sk-test-token',
            'user_id' => 1,
            'tenant_id' => 1,
        ]);
        $this->assertFalse($this->apiKey->validate(), 'Name should not exceed 255 characters');

        // token 超长
        $this->apiKey->setData([
            'name' => 'Test API Key',
            'token' => 'sk-' . str_repeat('A', 250), // 超过255字符
            'user_id' => 1,
            'tenant_id' => 1,
        ]);
        $this->assertFalse($this->apiKey->validate(), 'Token should not exceed 255 characters');
    }

    /**
     * 测试：使用量递增
     */
    public function testIncrementUsage(): void
    {
        $this->apiKey->setData([
            'usage_daily' => 10,
            'usage_monthly' => 100,
        ]);

        $this->apiKey->incrementUsage();

        $this->assertEquals(11, $this->apiKey->getData('usage_daily'), 'Daily usage should increment');
        $this->assertEquals(101, $this->apiKey->getData('usage_monthly'), 'Monthly usage should increment');
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        parent::tearDown();
    }
}

