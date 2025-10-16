<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Ai\Service\AiApiKeyService;

/**
 * API Key Management Controller
 * 
 * @package Weline_Ai
 */
class ApiKey extends FrontendRestController
{
    private AiApiKeyService $apiKeyService;
    
    public function __construct(AiApiKeyService $apiKeyService)
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * POST /ai/rest/v1/apikey - Create API Key
     */
    public function postIndex()
    {
        try {
            $data = $this->request->getBodyParams();

            $name = $data['name'] ?? '';
            $userId = (int) ($data['user_id'] ?? 1);
            $tenantId = (int) ($data['tenant_id'] ?? 1);

            if (empty($name)) {
                return $this->fetch(['success' => false, 'message' => 'Name is required', 'code' => 400]);
            }

            $apiKey = $this->apiKeyService->createApiKey($name, $userId, $tenantId, [
                'quota_daily' => $data['quota_daily'] ?? null,
                'quota_monthly' => $data['quota_monthly'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            return $this->fetch([
                'success' => true,
                'message' => 'API密钥创建成功',
                'data' => [
                    'id' => $apiKey->getId(),
                    'name' => $apiKey->getData('name'),
                    'token' => $apiKey->getData('token'),
                    'status' => $apiKey->getData('status'),
                    'created_at' => $apiKey->getData('created_at'),
                ]
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->fetch(['success' => false, 'message' => $e->getMessage(), 'code' => 400]);
        } catch (\Exception $e) {
            return $this->fetch(['success' => false, 'message' => 'Internal server error', 'code' => 500]);
        }
    }

    /**
     * GET /ai/rest/v1/apikey - Get API Key by ID or list
     */
    public function getApiKey()
    {
        try {
            $keyId = (int) $this->request->getParam('id');

            if ($keyId) {
                // Get single API key
                $apiKey = $this->apiKeyService->getById($keyId);

                if (!$apiKey->getId()) {
                    return $this->fetch(['success' => false, 'message' => 'API Key not found', 'code' => 404]);
                }

                return $this->fetch([
                    'success' => true,
                    'message' => '请求成功',
                    'data' => [
                        'id' => $apiKey->getId(),
                        'name' => $apiKey->getData('name'),
                        'token' => $apiKey->getData('token'),
                        'user_id' => $apiKey->getData('user_id'),
                        'tenant_id' => $apiKey->getData('tenant_id'),
                        'status' => $apiKey->getData('status'),
                        'quota_daily' => $apiKey->getData('quota_daily'),
                        'quota_monthly' => $apiKey->getData('quota_monthly'),
                        'usage_daily' => $apiKey->getData('usage_daily'),
                        'usage_monthly' => $apiKey->getData('usage_monthly'),
                        'created_at' => $apiKey->getData('created_at'),
                    ]
                ]);
            } else {
                // List API keys (simplified version)
                return $this->fetch([
                    'success' => true,
                    'message' => '请求成功',
                    'data' => [
                        'items' => [],
                        'total' => 0,
                    ]
                ]);
            }

        } catch (\Exception $e) {
            return $this->fetch(['success' => false, 'message' => 'Internal server error', 'code' => 500]);
        }
    }

    /**
     * DELETE /ai/rest/v1/apikey/{id}
     */
    public function deleteIndex()
    {
        try {
            $keyId = (int) $this->request->getParam('id');

            if (!$keyId) {
                return $this->fetch(['success' => false, 'message' => 'API Key ID is required', 'code' => 400]);
            }

            $apiKey = $this->apiKeyService->getById($keyId);
            if (!$apiKey->getId()) {
                return $this->fetch(['success' => false, 'message' => 'API Key not found', 'code' => 404]);
            }

            $apiKey->setData('status', 'revoked')->save();

            return $this->fetch([
                'success' => true,
                'message' => 'API密钥已撤销',
                'data' => [
                    'id' => $keyId,
                ]
            ]);

        } catch (\Exception $e) {
            return $this->fetch(['success' => false, 'message' => 'Internal server error', 'code' => 500]);
        }
    }
}

