<?php

declare(strict_types=1);

namespace Weline\Ai\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Service\AiApiKeyService;
use Weline\Ai\Service\SecretStoreService;
use Weline\Framework\Database\Api\Connection\QueryInterface;

/**
 * AiApiKeyService 单元测试
 * 
 * 测试范围：
 * - API密钥创建
 * - 令牌生成
 * - 密钥验证
 * - 使用量跟踪
 */
class test_api_key_service extends TestCase
{
    private AiApiKeyService $service;
    private AiApiKey $apiKeyModel;
    private SecretStoreService $secretStore;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock SecretStoreService
        $this->secretStore = $this->createMock(SecretStoreService::class);
        
        // Mock AiApiKey Model
        $this->apiKeyModel = $this->createMock(AiApiKey::class);
        
        // 创建Service实例
        $this->service = new AiApiKeyService(
            $this->apiKeyModel,
            $this->secretStore
        );
    }

    /**
     * 测试：创建API密钥
     */
    public function testCreateApiKey()
    {
        // 模拟令牌生成
        $rawToken = 'test_token_' . time();
        $encryptedToken = 'encrypted_' . $rawToken;
        $tokenHash = 'hash_' . $rawToken;

        $this->secretStore->expects($this->once())
            ->method('generateSecureToken')
            ->with(32)
            ->willReturn($rawToken);

        $this->secretStore->expects($this->once())
            ->method('encryptApiKey')
            ->with($rawToken)
            ->willReturn($encryptedToken);

        $this->secretStore->expects($this->once())
            ->method('hashApiKey')
            ->with($rawToken)
            ->willReturn($tokenHash);

        // 模拟Model行为
        $clonedModel = $this->createMock(AiApiKey::class);
        $this->apiKeyModel->expects($this->once())
            ->method('__clone')
            ->willReturn($clonedModel);

        $clonedModel->expects($this->once())
            ->method('setData')
            ->with($this->callback(function($data) use ($encryptedToken, $tokenHash) {
                return $data['name'] === 'Test Key' 
                    && $data['token'] === $encryptedToken
                    && $data['token_hash'] === $tokenHash
                    && $data['user_id'] === 1
                    && $data['tenant_id'] === 1;
            }));

        $clonedModel->expects($this->once())
            ->method('save');

        // 执行创建
        $result = $this->service->createApiKey('Test Key', 1, 1);

        $this->assertInstanceOf(AiApiKey::class, $result);
    }

    /**
     * 测试：创建API密钥（带选项）
     */
    public function testCreateApiKeyWithOptions()
    {
        $options = [
            'status' => 'pending',
            'quota_daily' => 100.0,
            'quota_monthly' => 3000.0,
            'expires_at' => time() + 86400 * 30
        ];

        // 基本Mock设置
        $this->secretStore->method('generateSecureToken')->willReturn('token');
        $this->secretStore->method('encryptApiKey')->willReturn('encrypted');
        $this->secretStore->method('hashApiKey')->willReturn('hash');

        $clonedModel = $this->createMock(AiApiKey::class);
        $this->apiKeyModel->method('__clone')->willReturn($clonedModel);

        $clonedModel->expects($this->once())
            ->method('setData')
            ->with($this->callback(function($data) use ($options) {
                return $data['status'] === $options['status']
                    && $data['quota_daily'] === $options['quota_daily']
                    && $data['quota_monthly'] === $options['quota_monthly']
                    && $data['expires_at'] === $options['expires_at'];
            }));

        $clonedModel->method('save');

        $result = $this->service->createApiKey('Test Key', 1, 1, $options);
        $this->assertInstanceOf(AiApiKey::class, $result);
    }

    /**
     * 测试：验证有效令牌
     */
    public function testValidateTokenSuccess()
    {
        $rawToken = 'valid_token';
        $tokenHash = 'hash_' . $rawToken;

        $this->secretStore->expects($this->once())
            ->method('hashApiKey')
            ->with($rawToken)
            ->willReturn($tokenHash);

        // Mock Query
        $query = $this->createMock(QueryInterface::class);
        $this->apiKeyModel->expects($this->once())
            ->method('where')
            ->with(AiApiKey::schema_fields_TOKEN_HASH, $tokenHash)
            ->willReturn($query);

        $query->expects($this->once())
            ->method('find')
            ->willReturn($this->apiKeyModel);

        $this->apiKeyModel->expects($this->once())
            ->method('getId')
            ->willReturn(1);

        $this->apiKeyModel->expects($this->once())
            ->method('getData')
            ->with(AiApiKey::schema_fields_STATUS)
            ->willReturn(AiApiKey::STATUS_APPROVED);

        $result = $this->service->validateToken($rawToken);
        $this->assertInstanceOf(AiApiKey::class, $result);
    }

    /**
     * 测试：验证无效令牌（不存在）
     */
    public function testValidateTokenNotFound()
    {
        $rawToken = 'invalid_token';
        $tokenHash = 'hash_' . $rawToken;

        $this->secretStore->method('hashApiKey')->willReturn($tokenHash);

        $query = $this->createMock(QueryInterface::class);
        $this->apiKeyModel->method('where')->willReturn($query);
        $query->method('find')->willReturn($this->apiKeyModel);
        
        $this->apiKeyModel->method('getId')->willReturn(null);

        $result = $this->service->validateToken($rawToken);
        $this->assertNull($result);
    }

    /**
     * 测试：验证无效令牌（状态未激活）
     */
    public function testValidateTokenInactiveStatus()
    {
        $rawToken = 'inactive_token';
        $tokenHash = 'hash_' . $rawToken;

        $this->secretStore->method('hashApiKey')->willReturn($tokenHash);

        $query = $this->createMock(QueryInterface::class);
        $this->apiKeyModel->method('where')->willReturn($query);
        $query->method('find')->willReturn($this->apiKeyModel);
        
        $this->apiKeyModel->method('getId')->willReturn(1);
        $this->apiKeyModel->method('getData')
            ->with(AiApiKey::schema_fields_STATUS)
            ->willReturn('rejected');

        $result = $this->service->validateToken($rawToken);
        $this->assertNull($result);
    }

    /**
     * 测试：撤销API密钥
     */
    public function testRevokeApiKey()
    {
        $keyId = 123;

        $this->apiKeyModel->expects($this->once())
            ->method('load')
            ->with($keyId)
            ->willReturn($this->apiKeyModel);

        $this->apiKeyModel->expects($this->once())
            ->method('getId')
            ->willReturn($keyId);

        $this->apiKeyModel->expects($this->once())
            ->method('setData')
            ->with(AiApiKey::schema_fields_STATUS, AiApiKey::STATUS_REJECTED);

        $this->apiKeyModel->expects($this->once())
            ->method('save');

        $result = $this->service->revokeApiKey($keyId);
        $this->assertTrue($result);
    }

    /**
     * 测试：撤销不存在的API密钥
     */
    public function testRevokeApiKeyNotFound()
    {
        $keyId = 999;

        $this->apiKeyModel->method('load')->willReturn($this->apiKeyModel);
        $this->apiKeyModel->method('getId')->willReturn(null);

        $result = $this->service->revokeApiKey($keyId);
        $this->assertFalse($result);
    }

    /**
     * 测试：记录使用量
     */
    public function testRecordUsage()
    {
        $keyId = 123;
        $cost = 0.05;

        $this->apiKeyModel->expects($this->once())
            ->method('load')
            ->with($keyId)
            ->willReturn($this->apiKeyModel);

        $this->apiKeyModel->expects($this->once())
            ->method('getId')
            ->willReturn($keyId);

        // 模拟获取当前使用量
        $this->apiKeyModel->expects($this->exactly(3))
            ->method('getData')
            ->willReturnMap([
                [AiApiKey::schema_fields_USAGE_DAILY, null, 0.10],
                [AiApiKey::schema_fields_USAGE_MONTHLY, null, 1.50],
                [AiApiKey::schema_fields_CALL_COUNT, null, 42]
            ]);

        // 验证更新
        $this->apiKeyModel->expects($this->once())
            ->method('setData')
            ->with($this->callback(function($data) use ($cost) {
                return $data[AiApiKey::schema_fields_USAGE_DAILY] === 0.15
                    && $data[AiApiKey::schema_fields_USAGE_MONTHLY] === 1.55
                    && $data[AiApiKey::schema_fields_CALL_COUNT] === 43;
            }));

        $this->apiKeyModel->expects($this->once())
            ->method('save');

        $this->service->recordUsage($keyId, $cost);
    }

    /**
     * 测试：检查配额（未超限）
     */
    public function testCheckQuotaNotExceeded()
    {
        $keyId = 123;

        $this->apiKeyModel->expects($this->once())
            ->method('load')
            ->with($keyId)
            ->willReturn($this->apiKeyModel);

        $this->apiKeyModel->method('getData')
            ->willReturnMap([
                [AiApiKey::schema_fields_QUOTA_DAILY, null, 10.0],
                [AiApiKey::schema_fields_QUOTA_MONTHLY, null, 300.0],
                [AiApiKey::schema_fields_USAGE_DAILY, null, 5.0],
                [AiApiKey::schema_fields_USAGE_MONTHLY, null, 150.0]
            ]);

        $result = $this->service->checkQuota($keyId);
        $this->assertTrue($result);
    }

    /**
     * 测试：检查配额（日配额超限）
     */
    public function testCheckQuotaDailyExceeded()
    {
        $keyId = 123;

        $this->apiKeyModel->method('load')->willReturn($this->apiKeyModel);
        $this->apiKeyModel->method('getData')
            ->willReturnMap([
                [AiApiKey::schema_fields_QUOTA_DAILY, null, 10.0],
                [AiApiKey::schema_fields_USAGE_DAILY, null, 10.5]
            ]);

        $result = $this->service->checkQuota($keyId);
        $this->assertFalse($result);
    }
}

