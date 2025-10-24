<?php
declare(strict_types=1);

/**
 * 多租户数据隔离集成测试
 * 
 * 测试场景: 多租户数据隔离和权限管理
 */

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiTenant;
use Weline\Ai\Model\AiTenantUser;
use Weline\Ai\Service\MultiTenantManager;

class TenantIsolationIntegrationTest extends TestCase
{
    private AiTenant $tenantModel;
    private AiTenantUser $tenantUserModel;
    private MultiTenantManager $tenantManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantModel = new AiTenant();
        $this->tenantUserModel = new AiTenantUser();
        $this->tenantManager = new MultiTenantManager();
    }

    /**
     * 测试租户数据隔离
     */
    public function testTenantDataIsolation(): void
    {
        // 创建两个租户
        $tenant1 = $this->createTestTenant('tenant-1', 'Tenant One');
        $tenant2 = $this->createTestTenant('tenant-2', 'Tenant Two');
        
        // 为每个租户创建用户
        $user1 = $this->createTenantUser($tenant1->getId(), 101, 'admin');
        $user2 = $this->createTenantUser($tenant2->getId(), 102, 'member');
        
        // 验证租户1只能看到自己的用户
        $tenant1Users = $this->tenantUserModel->getCollection()
            ->where('tenant_id', $tenant1->getId())
            ->fetch();
        
        $this->assertCount(1, $tenant1Users);
        $this->assertEquals(101, $tenant1Users[0]->getUserId());
        
        // 验证租户2只能看到自己的用户
        $tenant2Users = $this->tenantUserModel->getCollection()
            ->where('tenant_id', $tenant2->getId())
            ->fetch();
        
        $this->assertCount(1, $tenant2Users);
        $this->assertEquals(102, $tenant2Users[0]->getUserId());
    }

    /**
     * 测试租户权限控制
     */
    public function testTenantPermissionControl(): void
    {
        $tenant = $this->createTestTenant('permission-tenant', 'Permission Test Tenant');
        $adminUser = $this->createTenantUser($tenant->getId(), 201, 'admin');
        $memberUser = $this->createTenantUser($tenant->getId(), 202, 'member');
        
        // 测试管理员权限
        $adminPermissions = $this->tenantManager->getUserPermissions($adminUser->getId());
        $this->assertContains('manage_users', $adminPermissions);
        $this->assertContains('manage_models', $adminPermissions);
        
        // 测试成员权限
        $memberPermissions = $this->tenantManager->getUserPermissions($memberUser->getId());
        $this->assertNotContains('manage_users', $memberPermissions);
        $this->assertContains('use_ai', $memberPermissions);
    }

    /**
     * 测试租户资源配额
     */
    public function testTenantResourceQuota(): void
    {
        $tenant = $this->createTestTenant('quota-tenant', 'Quota Test Tenant');
        
        // 设置资源配额
        $quota = [
            'api_calls' => 1000,
            'tokens' => 100000,
            'storage' => '1GB'
        ];
        
        $tenant->setResourceQuota($quota);
        $tenant->save();
        
        // 验证配额设置
        $savedTenant = $this->tenantModel->load($tenant->getId());
        $savedQuota = $savedTenant->getResourceQuota();
        
        $this->assertEquals($quota, $savedQuota);
    }

    /**
     * 测试租户状态管理
     */
    public function testTenantStatusManagement(): void
    {
        $tenant = $this->createTestTenant('status-tenant', 'Status Test Tenant');
        
        // 测试激活状态
        $this->assertEquals('active', $tenant->getStatus());
        
        // 测试暂停租户
        $result = $this->tenantManager->suspendTenant($tenant->getId());
        $this->assertTrue($result);
        
        $suspendedTenant = $this->tenantModel->load($tenant->getId());
        $this->assertEquals('suspended', $suspendedTenant->getStatus());
        
        // 测试恢复租户
        $result = $this->tenantManager->activateTenant($tenant->getId());
        $this->assertTrue($result);
        
        $activatedTenant = $this->tenantModel->load($tenant->getId());
        $this->assertEquals('active', $activatedTenant->getStatus());
    }

    /**
     * 创建测试租户
     */
    private function createTestTenant(string $code, string $name): AiTenant
    {
        $tenant = new AiTenant();
        $tenant->setData([
            'tenant_name' => $name,
            'tenant_code' => $code,
            'tenant_type' => 'enterprise',
            'status' => 'active',
            'plan_type' => 'professional'
        ]);
        $tenant->save();
        
        return $tenant;
    }

    /**
     * 创建租户用户
     */
    private function createTenantUser(int $tenantId, int $userId, string $role): AiTenantUser
    {
        $tenantUser = new AiTenantUser();
        $tenantUser->setData([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'role' => $role,
            'is_active' => 1
        ]);
        $tenantUser->save();
        
        return $tenantUser;
    }

    protected function tearDown(): void
    {
        // 清理测试数据
        $this->tenantUserModel->getCollection()
            ->where('tenant_id', 'IN', [
                $this->tenantModel->getCollection()
                    ->where('tenant_code', 'LIKE', 'tenant-%')
                    ->getFieldValues('id')
            ])
            ->delete();
        
        $this->tenantModel->getCollection()
            ->where('tenant_code', 'LIKE', 'tenant-%')
            ->delete();
        
        parent::tearDown();
    }
}
