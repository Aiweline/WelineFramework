<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiTenant;
use Weline\Framework\Manager\ObjectManager;

/**
 * 测试 AiTenant 模型
 */
class AiTenantTest extends TestCase
{
    private AiTenant $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(AiTenant::class);
    }

    /**
     * 测试模型实例化
     */
    public function testModelInstantiation()
    {
        $this->assertInstanceOf(AiTenant::class, $this->model);
    }

    /**
     * 测试模型字段常量定义
     */
    public function testFieldConstants()
    {
        $this->assertEquals('id', AiTenant::schema_fields_ID);
        $this->assertEquals('name', AiTenant::schema_fields_NAME);
        $this->assertEquals('domain', AiTenant::schema_fields_DOMAIN);
        $this->assertEquals('config', AiTenant::schema_fields_CONFIG);
        $this->assertEquals('quota_monthly', AiTenant::schema_fields_QUOTA_MONTHLY);
        $this->assertEquals('usage_monthly', AiTenant::schema_fields_USAGE_MONTHLY);
        $this->assertEquals('billing_plan', AiTenant::schema_fields_BILLING_PLAN);
        $this->assertEquals('status', AiTenant::schema_fields_STATUS);
        $this->assertEquals('created_at', AiTenant::schema_fields_CREATED_AT);
        $this->assertEquals('updated_at', AiTenant::schema_fields_UPDATED_AT);
    }

    /**
     * 测试数据设置和获取
     */
    public function testSetAndGetData()
    {
        $testData = [
            AiTenant::schema_fields_NAME => 'Test Tenant',
            AiTenant::schema_fields_DOMAIN => 'test.example.com',
            AiTenant::schema_fields_QUOTA_MONTHLY => 10000,
            AiTenant::schema_fields_USAGE_MONTHLY => 1500,
            AiTenant::schema_fields_BILLING_PLAN => 'enterprise',
            AiTenant::schema_fields_STATUS => 'active'
        ];

        $this->model->setData($testData);

        $this->assertEquals('Test Tenant', $this->model->getData(AiTenant::schema_fields_NAME));
        $this->assertEquals('test.example.com', $this->model->getData(AiTenant::schema_fields_DOMAIN));
        $this->assertEquals(10000, $this->model->getData(AiTenant::schema_fields_QUOTA_MONTHLY));
        $this->assertEquals(1500, $this->model->getData(AiTenant::schema_fields_USAGE_MONTHLY));
        $this->assertEquals('enterprise', $this->model->getData(AiTenant::schema_fields_BILLING_PLAN));
        $this->assertEquals('active', $this->model->getData(AiTenant::schema_fields_STATUS));
    }

    /**
     * 测试租户状态
     */
    public function testTenantStatus()
    {
        $statuses = ['active', 'suspended', 'inactive'];

        foreach ($statuses as $status) {
            $this->model->setData(AiTenant::schema_fields_STATUS, $status);
            $this->assertEquals($status, $this->model->getData(AiTenant::schema_fields_STATUS));
        }
    }

    /**
     * 测试计费方案
     */
    public function testBillingPlans()
    {
        $plans = ['free', 'basic', 'professional', 'enterprise'];

        foreach ($plans as $plan) {
            $this->model->setData(AiTenant::schema_fields_BILLING_PLAN, $plan);
            $this->assertEquals($plan, $this->model->getData(AiTenant::schema_fields_BILLING_PLAN));
        }
    }

    /**
     * 测试配额和使用量
     */
    public function testQuotaAndUsage()
    {
        $quotaMonthly = 50000;
        $usageMonthly = 12500;

        $this->model->setData(AiTenant::schema_fields_QUOTA_MONTHLY, $quotaMonthly);
        $this->model->setData(AiTenant::schema_fields_USAGE_MONTHLY, $usageMonthly);

        $this->assertEquals($quotaMonthly, $this->model->getData(AiTenant::schema_fields_QUOTA_MONTHLY));
        $this->assertEquals($usageMonthly, $this->model->getData(AiTenant::schema_fields_USAGE_MONTHLY));
        
        // 测试剩余配额
        $remaining = $quotaMonthly - $usageMonthly;
        $this->assertEquals(37500, $remaining);
    }

    /**
     * 测试域名唯一性验证（模拟）
     */
    public function testDomainUniqueness()
    {
        $domain = 'unique.example.com';
        $this->model->setData(AiTenant::schema_fields_DOMAIN, $domain);

        $this->assertEquals($domain, $this->model->getData(AiTenant::schema_fields_DOMAIN));
        
        // 验证域名格式（简单验证）
        $this->assertStringContainsString('.', $domain);
    }

    /**
     * 测试租户名称
     */
    public function testTenantName()
    {
        $name = 'Enterprise Tenant ' . time();
        $this->model->setData(AiTenant::schema_fields_NAME, $name);

        $this->assertEquals($name, $this->model->getData(AiTenant::schema_fields_NAME));
    }

    /**
     * 测试时间戳字段
     */
    public function testTimestampFields()
    {
        $currentTime = time();

        $this->model->setData(AiTenant::schema_fields_CREATED_AT, $currentTime);
        $this->model->setData(AiTenant::schema_fields_UPDATED_AT, $currentTime);

        $this->assertEquals($currentTime, $this->model->getData(AiTenant::schema_fields_CREATED_AT));
        $this->assertEquals($currentTime, $this->model->getData(AiTenant::schema_fields_UPDATED_AT));
    }

    /**
     * 测试租户激活状态判断
     */
    public function testIsActiveStatus()
    {
        // 测试活跃状态
        $this->model->setData(AiTenant::schema_fields_STATUS, 'active');
        $this->assertEquals('active', $this->model->getData(AiTenant::schema_fields_STATUS));

        // 测试暂停状态
        $this->model->setData(AiTenant::schema_fields_STATUS, 'suspended');
        $this->assertNotEquals('active', $this->model->getData(AiTenant::schema_fields_STATUS));

        // 测试非活跃状态
        $this->model->setData(AiTenant::schema_fields_STATUS, 'inactive');
        $this->assertNotEquals('active', $this->model->getData(AiTenant::schema_fields_STATUS));
    }

    protected function tearDown(): void
    {
        unset($this->model);
        parent::tearDown();
    }
}

