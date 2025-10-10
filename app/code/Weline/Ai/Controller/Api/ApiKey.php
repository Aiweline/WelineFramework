<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Ai\Service\AiApiKeyService;
use Weline\Ai\Model\AiApiKey as AiApiKeyModel;

/**
 * API Key Controller
 * 
 * Handles API key management operations.
 * 
 * @package Weline_Ai
 */
class ApiKey extends FrontendRestController
{
    public function __construct(
        private readonly AiApiKeyService $apiKeyService,
        private readonly AiApiKeyModel $apiKeyModel
    ) {
    }

    /**
     * POST /api/v1/api-key
     * 
     * Create a new API key
     *
     * @return array
     */
    public function post(): array
    {
        try {
            $data = $this->request->getBodyParams();

            // Validate required fields
            if (empty($data['name'])) {
                return $this->error('Name is required', 400);
            }

            if (empty($data['user_id'])) {
                return $this->error('User ID is required', 400);
            }

            $name = (string) $data['name'];
            $userId = (int) $data['user_id'];
            $tenantId = (int) ($data['tenant_id'] ?? 1); // Default to tenant 1

            // Create API key
            $apiKey = $this->apiKeyService->createApiKey(
                $name,
                $userId,
                $tenantId,
                [
                    'quota_daily' => $data['quota_daily'] ?? null,
                    'quota_monthly' => $data['quota_monthly'] ?? null,
                    'expires_at' => $data['expires_at'] ?? null,
                ]
            );

            return $this->success('API密钥创建成功', [
                'id' => $apiKey->getId(),
                'name' => $apiKey->getData('name'),
                'token' => $apiKey->getData('token'),
                'status' => $apiKey->getData('status'),
                'quota_daily' => $apiKey->getData('quota_daily'),
                'quota_monthly' => $apiKey->getData('quota_monthly'),
                'expires_at' => $apiKey->getData('expires_at'),
                'created_at' => $apiKey->getData('created_at'),
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

    /**
     * GET /api/v1/api-key
     * 
     * Get API key list for current user
     *
     * @return array
     */
    public function get(): array
    {
        try {
            $userId = (int) $this->request->getParam('user_id');
            
            if (!$userId) {
                return $this->error('User ID is required', 400);
            }

            // Get API keys for user
            $keys = $this->getApiKeysByUser($userId);

            return $this->success('请求成功', [
                'items' => $keys,
                'total' => count($keys),
            ]);

        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

    /**
     * GET /api/v1/api-key/{id}
     * 
     * Get specific API key details
     *
     * @return array
     */
    public function getOne(): array
    {
        try {
            $keyId = (int) $this->request->getParam('id');

            if (!$keyId) {
                return $this->error('API Key ID is required', 400);
            }

            $apiKey = clone $this->apiKeyModel;
            $apiKey->load($keyId);

            if (!$apiKey->getId()) {
                return $this->error('API Key not found', 404);
            }

            return $this->success('请求成功', [
                'id' => $apiKey->getId(),
                'name' => $apiKey->getData('name'),
                'token' => $apiKey->getData('token'),
                'status' => $apiKey->getData('status'),
                'quota_daily' => $apiKey->getData('quota_daily'),
                'quota_monthly' => $apiKey->getData('quota_monthly'),
                'usage_daily' => $apiKey->getData('usage_daily'),
                'usage_monthly' => $apiKey->getData('usage_monthly'),
                'last_used_at' => $apiKey->getData('last_used_at'),
                'expires_at' => $apiKey->getData('expires_at'),
                'created_at' => $apiKey->getData('created_at'),
            ]);

        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

    /**
     * DELETE /api/v1/api-key/{id}
     * 
     * Revoke/delete an API key
     *
     * @return array
     */
    public function delete(): array
    {
        try {
            $keyId = (int) $this->request->getParam('id');

            if (!$keyId) {
                return $this->error('API Key ID is required', 400);
            }

            $apiKey = clone $this->apiKeyModel;
            $apiKey->load($keyId);

            if (!$apiKey->getId()) {
                return $this->error('API Key not found', 404);
            }

            // Revoke the key
            $apiKey->setData('status', AiApiKeyModel::STATUS_REVOKED);
            $apiKey->save();

            return $this->success('API密钥已撤销', [
                'id' => $keyId,
            ]);

        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

    /**
     * Get API keys by user ID
     *
     * @param int $userId
     * @return array
     */
    private function getApiKeysByUser(int $userId): array
    {
        $connection = $this->apiKeyModel->getConnection();
        $select = $connection->select()
            ->from('ai_api_key')
            ->where('user_id = ?', $userId)
            ->order('created_at DESC');

        $results = $connection->fetchAll($select);
        
        $keys = [];
        foreach ($results as $data) {
            $keys[] = [
                'id' => $data['id'],
                'name' => $data['name'],
                'token' => substr($data['token'], 0, 12) . '...', // Mask token
                'status' => $data['status'],
                'usage_daily' => $data['usage_daily'],
                'usage_monthly' => $data['usage_monthly'],
                'last_used_at' => $data['last_used_at'],
                'created_at' => $data['created_at'],
            ];
        }

        return $keys;
    }

}

