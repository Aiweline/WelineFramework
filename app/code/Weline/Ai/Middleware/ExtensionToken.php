<?php

declare(strict_types=1);

namespace Weline\Ai\Middleware;

use Weline\Ai\Service\Extension\ExtensionTokenService;
/**
 * AI扩展API通道签章校验中间件
 */
class ExtensionToken
{
    private ExtensionTokenService $tokenService;

    public function __construct(
        ExtensionTokenService $tokenService
    ) {
        $this->tokenService = $tokenService;
    }

    /**
     * 处理请求
     *
     * @param $request
     * @param callable $next
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        $token = (string)($request->getHeader('X-Ai-Ext-Token') ?? '');
        $payload = $this->tokenService->verify($token);

        if ($payload === false) {
            http_response_code(403);
            header('Content-Type: application/json');
            return json_encode(['error' => 'invalid_token'], JSON_UNESCAPED_UNICODE);
        }

        if (method_exists($request, 'setData')) {
            $request->setData('ai_ext_payload', $payload);
        }
        return $next($request);
    }
}
