<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiApiKey;

/**
 * AI API Key Service
 * 
 * 功能：
 * - API 密钥生成和管理
 * - 密钥加密存储
 * - 密钥验证
 * - 使用量跟踪
 * 
 * @package Weline_Ai
 */
class AiApiKeyService
{
    private AiApiKey $apiKey;
    private SecretStoreService $secretStore;

    public function __construct(
        AiApiKey $apiKey,
        SecretStoreService $secretStore
    ) {
        $this->apiKey = $apiKey;
        $this->secretStore = $secretStore;
    }

    /**
     * Create new API key
     *
     * @param string $name
     * @param int $userId
     * @param int $tenantId
     * @param array $options
     * @return AiApiKey
     */
    public function createApiKey(
        string $name,
        int $userId,
        int $tenantId,
        array $options = []
    ): AiApiKey {
        // 生成原始令牌
        $rawToken = $this->generateToken();
        
        // 加密令牌用于存储
        $encryptedToken = $this->secretStore->encryptApiKey($rawToken);
        
        // 生成令牌哈希用于快速查找
        $tokenHash = $this->secretStore->hashApiKey($rawToken);

        $apiKey = clone $this->apiKey;
        $apiKey->setData([
            'name' => $name,
            'token' => $encryptedToken, // 存储加密后的令牌
            'token_hash' => $tokenHash, // 存储哈希用于查找
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'status' => $options['status'] ?? AiApiKey::STATUS_APPROVED,
            'quota_daily' => $options['quota_daily'] ?? null,
            'quota_monthly' => $options['quota_monthly'] ?? null,
            'usage_daily' => 0,
            'usage_monthly' => 0,
            'expires_at' => $options['expires_at'] ?? null,
        ]);

        $apiKey->save();

        // 临时设置原始令牌用于返回给用户（仅此一次）
        $apiKey->setData('raw_token', $rawToken);

        return $apiKey;
    }

    /**
     * Generate unique API token
     *
     * @return string
     */
    private function generateToken(): string
    {
        return $this->secretStore->generateSecureToken(32);
    }

    /**
     * Validate API key
     *
     * @param string $token 原始令牌
     * @return AiApiKey|null
     */
    public function validateToken(string $token): ?AiApiKey
    {
        // 生成令牌哈希用于查找
        $tokenHash = $this->secretStore->hashApiKey($token);

        $apiKey = clone $this->apiKey;
        $apiKey->load($tokenHash, 'token_hash');

        if (!$apiKey->getId()) {
            return null;
        }

        // 验证加密的令牌
        $encryptedToken = $apiKey->getData('token');
        if (!$this->secretStore->verifyApiKey($token, $encryptedToken)) {
            return null;
        }

        // 检查状态
        if (!$apiKey->isActive()) {
            return null;
        }

        // 检查配额
        if (!$apiKey->hasQuota()) {
            return null;
        }

        // 更新最后使用时间
        $apiKey->setData('last_used_at', date('Y-m-d H:i:s'));

        return $apiKey;
    }

    /**
     * Record API key usage
     *
     * @param int $apiKeyId
     * @return void
     */
    public function recordUsage(int $apiKeyId): void
    {
        $apiKey = clone $this->apiKey;
        $apiKey->load($apiKeyId);

        if ($apiKey->getId()) {
            $apiKey->incrementUsage();
            $apiKey->save();
        }
    }

    /**
     * Reset daily usage counters
     *
     * @return int Number of keys reset
     */
    public function resetDailyUsage(): int
    {
        $connection = $this->apiKey->getConnection();
        return $connection->update(
            'ai_api_key',
            ['usage_daily' => 0],
            []
        );
    }

    /**
     * Reset monthly usage counters
     *
     * @return int Number of keys reset
     */
    public function resetMonthlyUsage(): int
    {
        $connection = $this->apiKey->getConnection();
        return $connection->update(
            'ai_api_key',
            ['usage_monthly' => 0],
            []
        );
    }
}

