<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Api;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Ai\Service\AiChatService;
use Weline\Ai\Service\AiApiKeyService;

/**
 * Chat API Controller
 * 
 * Handles AI chat requests with model selection and session management.
 * 
 * @package Weline_Ai
 */
class Chat extends FrontendRestController
{
    public function __construct(
        private readonly AiChatService $chatService,
        private readonly AiApiKeyService $apiKeyService
    ) {
    }

    /**
     * POST /api/v1/chat
     * 
     * Process chat request with AI model
     *
     * @return array
     */
    public function post(): array
    {
        try {
            // Get request data
            $data = $this->request->getBodyParams();
            
            // Validate required fields
            if (empty($data['prompt'])) {
                return $this->error('Prompt is required', 400);
            }

            if (empty($data['model_code'])) {
                return $this->error('Model code is required', 400);
            }

            $prompt = (string) $data['prompt'];
            $modelCode = (string) $data['model_code'];
            $sessionId = $data['session_id'] ?? uniqid('session_', true);

            // Get API version and locale from headers
            $version = $this->request->getHeader('X-API-Version') ?? 'v1';
            $locale = $this->request->getHeader('X-API-Locale') ?? 'zh_Hans_CN';

            // Process chat request
            $result = $this->chatService->chat($prompt, $modelCode, $sessionId, [
                'version' => $version,
                'locale' => $locale,
            ]);

            // Return success response
            return $this->success('请求成功', [
                'response' => $result['response'],
                'locale' => $locale,
                'version' => $version,
                'session_id' => $sessionId,
                'tokens_used' => $result['tokens_used'] ?? 0,
                'cost' => $result['cost'] ?? 0.0,
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 400);
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 500);
        } catch (\Exception $e) {
            return $this->error('Internal server error', 500);
        }
    }

}
