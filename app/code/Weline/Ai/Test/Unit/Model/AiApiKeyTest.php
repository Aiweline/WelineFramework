<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiApiKey;
use Weline\Framework\Manager\ObjectManager;

/**
 * 测试 AiApiKey 模型
 */
class AiApiKeyTest extends TestCase
{
    private AiApiKey $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = ObjectManager::getInstance(AiApiKey::class);
    }

    /**
     * 测试模型实例化
     */
    public function testModelInstantiation()
    {
        $this->assertInstanceOf(AiApiKey::class, $this->model);
    }

    /**
     * 测试模型字段常量定义
     */
    public function testFieldConstants()
    {
        $this->assertEquals('id', AiApiKey::schema_fields_ID);
        $this->assertEquals('user_id', AiApiKey::schema_fields_USER_ID);
        $this->assertEquals('tenant_id', AiApiKey::schema_fields_TENANT_ID);
        $this->assertEquals('name', AiApiKey::schema_fields_NAME);
        $this->assertEquals('token', AiApiKey::schema_fields_TOKEN);
        $this->assertEquals('status', AiApiKey::schema_fields_STATUS);
        $this->assertEquals('quota_daily', AiApiKey::schema_fields_QUOTA_DAILY);
        $this->assertEquals('quota_monthly', AiApiKey::schema_fields_QUOTA_MONTHLY);
        $this->assertEquals('usage_daily', AiApiKey::schema_fields_USAGE_DAILY);
        $this->assertEquals('usage_monthly', AiApiKey::schema_fields_USAGE_MONTHLY);
        $this->assertEquals('expires_at', AiApiKey::schema_fields_EXPIRES_AT);
        $this->assertEquals('last_used_at', AiApiKey::schema_fields_LAST_USED_AT);
        $this->assertEquals('created_at', AiApiKey::schema_fields_CREATED_AT);
        $this->assertEquals('updated_at', AiApiKey::schema_fields_UPDATED_AT);
    }

    /**
     * 测试数据设置和获取
     */
    public function testSetAndGetData()
    {
        $testData = [
            AiApiKey::schema_fields_USER_ID => 1,
            AiApiKey::schema_fields_NAME => 'Test API Key',
            AiApiKey::schema_fields_TOKEN => 'test_api_key_123',
            AiApiKey::schema_fields_STATUS => 'approved',
            AiApiKey::schema_fields_QUOTA_DAILY => 1000,
            AiApiKey::schema_fields_USAGE_DAILY => 100
        ];

        $this->model->setData($testData);

        $this->assertEquals(1, $this->model->getData(AiApiKey::schema_fields_USER_ID));
        $this->assertEquals('Test API Key', $this->model->getData(AiApiKey::schema_fields_NAME));
        $this->assertEquals('test_api_key_123', $this->model->getData(AiApiKey::schema_fields_TOKEN));
        $this->assertEquals('approved', $this->model->getData(AiApiKey::schema_fields_STATUS));
        $this->assertEquals(1000, $this->model->getData(AiApiKey::schema_fields_QUOTA_DAILY));
        $this->assertEquals(100, $this->model->getData(AiApiKey::schema_fields_USAGE_DAILY));
    }

    /**
     * 测试状态检查
     */
    public function testStatusCheck()
    {
        $this->model->setData(AiApiKey::schema_fields_STATUS, AiApiKey::STATUS_APPROVED);
        $this->assertEquals(AiApiKey::STATUS_APPROVED, $this->model->getData(AiApiKey::schema_fields_STATUS));

        $this->model->setData(AiApiKey::schema_fields_STATUS, AiApiKey::STATUS_SUSPENDED);
        $this->assertEquals(AiApiKey::STATUS_SUSPENDED, $this->model->getData(AiApiKey::schema_fields_STATUS));
    }

    /**
     * 测试配额相关方法
     */
    public function testQuotaMethods()
    {
        // 测试配额设置
        $this->model->setData(AiApiKey::schema_fields_QUOTA_DAILY, 5000);
        $this->model->setData(AiApiKey::schema_fields_USAGE_DAILY, 1500);

        $this->assertEquals(5000, $this->model->getData(AiApiKey::schema_fields_QUOTA_DAILY));
        $this->assertEquals(1500, $this->model->getData(AiApiKey::schema_fields_USAGE_DAILY));

        // 测试剩余配额计算
        $remaining = $this->model->getData(AiApiKey::schema_fields_QUOTA_DAILY) - 
                     $this->model->getData(AiApiKey::schema_fields_USAGE_DAILY);
        $this->assertEquals(3500, $remaining);
    }

    /**
     * 测试状态相关方法
     */
    public function testStatusMethods()
    {
        // 测试不同状态
        $statuses = ['pending', 'approved', 'rejected'];

        foreach ($statuses as $status) {
            $this->model->setData(AiApiKey::schema_fields_STATUS, $status);
            $this->assertEquals($status, $this->model->getData(AiApiKey::schema_fields_STATUS));
        }
    }

    /**
     * 测试过期检查
     */
    public function testExpirationCheck()
    {
        // 测试已过期
        $this->model->setData(AiApiKey::schema_fields_EXPIRES_AT, time() - 86400);
        $expiresAt = $this->model->getData(AiApiKey::schema_fields_EXPIRES_AT);
        $this->assertLessThan(time(), $expiresAt);

        // 测试未过期
        $this->model->setData(AiApiKey::schema_fields_EXPIRES_AT, time() + 86400);
        $expiresAt = $this->model->getData(AiApiKey::schema_fields_EXPIRES_AT);
        $this->assertGreaterThan(time(), $expiresAt);
    }

    /**
     * 测试密钥隐藏显示
     */
    public function testKeyMasking()
    {
        $apiKey = 'sk_test_1234567890abcdefghijklmnopqrstuvwxyz';
        $this->model->setData(AiApiKey::schema_fields_TOKEN, $apiKey);

        // 模拟密钥隐藏（显示前8位和后4位）
        $maskedKey = substr($apiKey, 0, 8) . '...' . substr($apiKey, -4);
        $expected = 'sk_test_...wxyz';

        $this->assertEquals($expected, $maskedKey);
        $this->assertEquals($apiKey, $this->model->getData(AiApiKey::schema_fields_TOKEN));
    }

    /**
     * 测试时间戳字段
     */
    public function testTimestampFields()
    {
        $currentTime = time();

        $this->model->setData(AiApiKey::schema_fields_CREATED_AT, $currentTime);
        $this->model->setData(AiApiKey::schema_fields_UPDATED_AT, $currentTime);
        $this->model->setData(AiApiKey::schema_fields_LAST_USED_AT, $currentTime);

        $this->assertEquals($currentTime, $this->model->getData(AiApiKey::schema_fields_CREATED_AT));
        $this->assertEquals($currentTime, $this->model->getData(AiApiKey::schema_fields_UPDATED_AT));
        $this->assertEquals($currentTime, $this->model->getData(AiApiKey::schema_fields_LAST_USED_AT));
    }

    protected function tearDown(): void
    {
        unset($this->model);
        parent::tearDown();
    }
}

