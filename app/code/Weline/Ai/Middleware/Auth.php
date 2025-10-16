<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

use Weline\Ai\Service\AiApiKeyService;
use Weline\Framework\Http\Request;

/**
 * API 认证中间件
 * 
 * 功能：
 * - 验证 API Key 有效性
 * - 检查配额限制
 * - 记录使用情况
 * - 设置认证上下文
 * 
 * @package Weline_Ai
 */
class Auth
{
    private AiApiKeyService $apiKeyService;
    private Request $request;

    public function __construct(
        AiApiKeyService $apiKeyService,
        Request $request
    ) {
        $this->apiKeyService = $apiKeyService;
        $this->request = $request;
    }

    /**
     * 处理请求认证
     *
     * @param mixed $request
     * @param callable $next
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        // 从请求头获取 Authorization
        $authHeader = $this->request->getHeader('Authorization');
        
        if (empty($authHeader)) {
            return $this->unauthorizedResponse('缺少认证信息');
        }

        // 提取 Bearer Token
        $token = $this->extractToken($authHeader);
        if (!$token) {
            return $this->unauthorizedResponse('无效的认证格式');
        }

        // 验证 API Key
        $apiKey = $this->apiKeyService->validateToken($token);
        if (!$apiKey) {
            return $this->unauthorizedResponse('无效或过期的 API Key');
        }

        // 检查配额
        if (!$apiKey->hasQuota()) {
            return $this->quotaExceededResponse();
        }

        // 设置认证上下文到请求
        $this->request->setData('api_key_id', $apiKey->getId());
        $this->request->setData('user_id', $apiKey->getData('user_id'));
        $this->request->setData('tenant_id', $apiKey->getData('tenant_id'));
        $this->request->setData('authenticated', true);

        // 记录 API Key 使用
        $this->apiKeyService->recordUsage($apiKey->getId());

        return $next($request);
    }

    /**
     * 从认证头提取 Token
     *
     * @param string $authHeader
     * @return string|null
     */
    private function extractToken(string $authHeader): ?string
    {
        // 支持 Bearer Token 格式
        if (preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        // 直接使用 Token
        if (str_starts_with($authHeader, 'sk-')) {
            return $authHeader;
        }

        return null;
    }

    /**
     * 返回未授权响应
     *
     * @param string $message
     * @return array
     */
    private function unauthorizedResponse(string $message = '未授权访问'): array
    {
        http_response_code(401);
        return [
            'success' => false,
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => $message
            ]
        ];
    }

    /**
     * 返回配额超限响应
     *
     * @return array
     */
    private function quotaExceededResponse(): array
    {
        http_response_code(429);
        return [
            'success' => false,
            'error' => [
                'code' => 'QUOTA_EXCEEDED',
                'message' => 'API 调用配额已超限'
            ]
        ];
    }
}
