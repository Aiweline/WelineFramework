<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Ai\Model\AiApiKey;

/**
 * AI API Key Service
 * 
 * @package Weline_Ai
 */
class AiApiKeyService
{
    public function __construct(
        private readonly AiApiKey $apiKey
    ) {
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
        $apiKey = clone $this->apiKey;
        $apiKey->setData([
            'name' => $name,
            'token' => $this->generateToken(),
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

        return $apiKey;
    }

    /**
     * Generate unique API token
     *
     * @return string
     */
    private function generateToken(): string
    {
        return 'sk-' . bin2hex(random_bytes(32));
    }

    /**
     * Validate API key
     *
     * @param string $token
     * @return AiApiKey|null
     */
    public function validateToken(string $token): ?AiApiKey
    {
        $apiKey = clone $this->apiKey;
        $apiKey->load($token, 'token');

        if (!$apiKey->getId()) {
            return null;
        }

        if (!$apiKey->isActive()) {
            return null;
        }

        if (!$apiKey->hasQuota()) {
            return null;
        }

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

