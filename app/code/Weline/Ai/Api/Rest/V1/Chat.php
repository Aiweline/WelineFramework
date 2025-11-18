<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Rest\V1;

use Weline\Framework\App\Controller\FrontendRestController;
use Weline\Ai\Service\AiChatService;

/**
 * Chat API Controller
 * 
 * @package Weline_Ai
 */
class Chat extends FrontendRestController
{
    private AiChatService $chatService;

    public function __construct(AiChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * POST /visitor/rest/v1/chat
     */
    public function postIndex()
    {
        try {
            $data = $this->request->getBodyParams();
            
            // If data is string, try to decode as JSON
            if (is_string($data)) {
                $decoded = json_decode($data, true);
                if ($decoded !== null) {
                    $data = $decoded;
                }
            }
            
            // Fallback to getParams if data is still empty or not an array
            if (empty($data) || !is_array($data)) {
                $data = $this->request->getParams();
            }

            $prompt = $data['prompt'] ?? '';
            $modelCode = $data['model_code'] ?? '';
            $sessionId = $data['session_id'] ?? uniqid('session_');
            $version = $this->request->getHeader('X-API-Version') ?? 'v1';
            $locale = $this->request->getHeader('X-API-Locale') ?? 'en-US';

            if (empty($prompt)) {
                return $this->fetch([
                    'success' => false, 
                    'message' => 'Prompt cannot be empty', 
                    'code' => 400
                ]);
            }
            if (empty($modelCode)) {
                return $this->fetch(['success' => false, 'message' => 'Model code cannot be empty', 'code' => 400]);
            }

            $result = $this->chatService->chat($prompt, $modelCode, $sessionId, [
                'version' => $version,
                'locale' => $locale,
            ]);

            return $this->fetch([
                'success' => true,
                'message' => '请求成功',
                'data' => [
                    'response' => $result['response'],
                    'locale' => $locale,
                    'version' => $version,
                    'session_id' => $sessionId,
                    'tokens_used' => $result['tokens_used'] ?? 0,
                    'cost' => $result['cost'] ?? 0.0,
                ]
            ]);

        } catch (\InvalidArgumentException $e) {
            return $this->fetch(['success' => false, 'message' => $e->getMessage(), 'code' => 400]);
        } catch (\RuntimeException $e) {
            return $this->fetch(['success' => false, 'message' => $e->getMessage(), 'code' => 500]);
        } catch (\Exception $e) {
            return $this->fetch(['success' => false, 'message' => 'Internal server error', 'code' => 500]);
        }
    }
}

