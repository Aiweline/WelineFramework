<?php

declare(strict_types=1);

namespace Weline\Ai\Controller\Api\V1;

use Weline\Ai\Service\BillingService;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * AI聊天API控制器
 * 
 * 提供类似OpenAI的Chat Completions API
 * 路径: /ai/api/v1/chat/completions
 */
class Chat extends FrontendController
{
    private BillingService $billingService;
    
    public function __construct(
        Request $request,
        BillingService $billingService
    ) {
        parent::__construct($request);
        $this->billingService = $billingService;
    }
    
    /**
     * Chat Completions API
     * POST /ai/api/v1/chat/completions
     * 
     * 请求格式（类似OpenAI）:
     * {
     *   "model": "gpt-4",
     *   "messages": [
     *     {"role": "user", "content": "Hello"}
     *   ],
     *   "temperature": 0.7,
     *   "max_tokens": 1000
     * }
     */
    public function completions()
    {
        // 获取请求数据
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            return $this->errorResponse('Invalid JSON', 400);
        }
        
        // 验证必需参数
        if (empty($input['model'])) {
            return $this->errorResponse('Missing required parameter: model', 400);
        }
        
        if (empty($input['messages']) || !is_array($input['messages'])) {
            return $this->errorResponse('Missing required parameter: messages', 400);
        }
        
        // 从中间件获取API密钥信息
        $apiKeyId = (int)$this->request->getData('api_key_id');
        $userId = (int)$this->request->getData('user_id');
        $requestId = $this->request->getData('request_id');
        $startTime = (float)$this->request->getData('request_start_time');
        
        try {
            // 调用AI服务（这里模拟调用，实际应调用真实AI服务）
            $aiResponse = $this->callAIService($input);
            
            // 计算费用
            $modelCode = $input['model'];
            $usage = $aiResponse['usage'] ?? [
                'prompt_tokens' => 100,
                'completion_tokens' => 200,
                'total_tokens' => 300,
            ];
            
            $cost = $this->billingService->calculateCost(
                $modelCode,
                $usage['prompt_tokens'],
                $usage['completion_tokens']
            );
            
            // 扣除余额并记录日志
            $callData = [
                'api_key_id' => $apiKeyId,
                'model_id' => $cost['model_id'],
                'model_code' => $modelCode,
                'request_id' => $requestId,
                'endpoint' => '/ai/api/v1/chat/completions',
                'request_method' => 'POST',
                'request_ip' => $this->request->getClientIp(),
                'prompt_tokens' => $usage['prompt_tokens'],
                'completion_tokens' => $usage['completion_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'prompt_cost' => $cost['prompt_cost'],
                'completion_cost' => $cost['completion_cost'],
                'total_cost' => $cost['total_cost'],
                'response_status' => 200,
                'response_time' => (int)((microtime(true) - $startTime) * 1000),
                'status' => 'success',
            ];
            
            $this->billingService->deductBalance($userId, $cost['total_cost'], $callData);
            
            // 更新API密钥使用量
            $this->billingService->updateApiKeyUsage($apiKeyId, $cost['total_cost']);
            
            // 返回AI响应（添加费用信息）
            $aiResponse['usage']['cost'] = [
                'prompt_cost' => $cost['prompt_cost'],
                'completion_cost' => $cost['completion_cost'],
                'total_cost' => $cost['total_cost'],
                'currency' => 'CNY',
            ];
            
            return $this->jsonResponse($aiResponse);
            
        } catch (\Exception $e) {
            // 记录失败日志
            $callData = [
                'api_key_id' => $apiKeyId,
                'model_code' => $input['model'],
                'request_id' => $requestId,
                'endpoint' => '/ai/api/v1/chat/completions',
                'request_method' => 'POST',
                'request_ip' => $this->request->getClientIp(),
                'response_status' => 500,
                'response_time' => (int)((microtime(true) - $startTime) * 1000),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ];
            
            // 尝试记录日志（即使失败也继续）
            try {
                $this->billingService->deductBalance($userId, 0, $callData);
            } catch (\Exception $logError) {
                // 忽略日志记录错误
            }
            
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
    
    /**
     * 调用AI服务（模拟实现）
     * 实际应用中，这里应该调用真实的AI服务（OpenAI, Anthropic等）
     *
     * @param array $input
     * @return array
     */
    private function callAIService(array $input): array
    {
        // TODO: 实际调用AI服务
        // 例如：调用OpenAI API、Anthropic API、DeepSeek API等
        
        // 这里返回模拟响应
        return [
            'id' => 'chatcmpl-' . uniqid(),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $input['model'],
            'choices' => [
                [
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => '这是一个模拟的AI响应。在实际应用中，这里会返回真实的AI模型生成的内容。',
                    ],
                    'finish_reason' => 'stop',
                ]
            ],
            'usage' => [
                'prompt_tokens' => 50,
                'completion_tokens' => 100,
                'total_tokens' => 150,
            ],
        ];
    }
    
    /**
     * JSON响应
     *
     * @param array $data
     * @return string
     */
    private function jsonResponse(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    
    /**
     * 错误响应
     *
     * @param string $message
     * @param int $code
     * @return string
     */
    private function errorResponse(string $message, int $code = 400): string
    {
        http_response_code($code);
        header('Content-Type: application/json');
        return json_encode([
            'error' => [
                'message' => $message,
                'type' => 'invalid_request_error',
                'code' => $code,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}

