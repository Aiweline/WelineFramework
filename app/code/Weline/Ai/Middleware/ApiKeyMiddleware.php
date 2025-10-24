<?php

declare(strict_types=1);

namespace Weline\Ai\Middleware;

use Weline\Ai\Model\AiApiKey;
use Weline\Ai\Service\BillingService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Http\Request;

/**
 * API密钥验证中间件
 * 
 * 功能：
 * 1. 验证API密钥有效性
 * 2. 检查用户余额
 * 3. 检查API密钥配额
 * 4. 记录请求信息到request属性中供后续使用
 */
class ApiKeyMiddleware
{
    private BillingService $billingService;
    
    public function __construct(
        BillingService $billingService
    ) {
        $this->billingService = $billingService;
    }
    
    /**
     * 处理请求
     *
     * @param Request $request
     * @param callable $next
     * @return mixed
     */
    public function handle(Request $request, callable $next)
    {
        // 从请求头中提取API密钥
        $apiKey = $this->extractApiKey($request);
        
        if (!$apiKey) {
            return $this->errorResponse('缺少API密钥', 401);
        }
        
        // 验证API密钥
        /** @var AiApiKey $apiKeyModel */
        $apiKeyModel = ObjectManager::getInstance(AiApiKey::class);
        $apiKeyModel = $apiKeyModel->where('token', $apiKey)->fetchOne();
        
        if (!$apiKeyModel || !$apiKeyModel->getId()) {
            return $this->errorResponse('无效的API密钥', 401);
        }
        
        // 检查API密钥状态
        if ($apiKeyModel->getData('status') !== AiApiKey::STATUS_APPROVED) {
            return $this->errorResponse('API密钥未激活或已被禁用', 403);
        }
        
        // 检查API密钥是否过期
        if ($apiKeyModel->isExpired()) {
            return $this->errorResponse('API密钥已过期', 403);
        }
        
        // 检查用户余额
        $userId = (int)$apiKeyModel->getData('user_id');
        if (!$this->billingService->checkBalance($userId, 0.01)) {
            return $this->errorResponse('账户余额不足，请充值', 402);
        }
        
        // 检查配额（预估消费0.1元，实际会在调用后精确计算）
        if (!$this->billingService->checkQuota($apiKeyModel, 0.1)) {
            return $this->errorResponse('API密钥配额已用尽', 429);
        }
        
        // 将API密钥信息记录到request属性中
        $request->setData('api_key_model', $apiKeyModel);
        $request->setData('api_key_id', $apiKeyModel->getId());
        $request->setData('user_id', $userId);
        $request->setData('request_start_time', microtime(true));
        $request->setData('request_id', uniqid('req_'));
        
        // 继续处理请求
        return $next($request);
    }
    
    /**
     * 从请求中提取API密钥
     *
     * @param Request $request
     * @return string|null
     */
    private function extractApiKey(Request $request): ?string
    {
        // 优先从 Authorization: Bearer {token} 头获取
        $authorization = $request->getHeader('Authorization');
        if ($authorization && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }
        
        // 从 X-API-Key 头获取
        $apiKey = $request->getHeader('X-API-Key');
        if ($apiKey) {
            return $apiKey;
        }
        
        // 从查询参数获取
        $apiKey = $request->getGet('api_key');
        if ($apiKey) {
            return $apiKey;
        }
        
        return null;
    }
    
    /**
     * 返回错误响应
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
                'type' => 'authentication_error',
                'code' => $code,
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
}

