<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 内容安全检查中间件
 */
class ContentSafety
{
    public function handle($request, $next)
    {
        if ($this->isUnsafeContent($request)) {
            return $this->unsafeContentResponse();
        }
        
        return $next($request);
    }

    private function isUnsafeContent($request): bool
    {
        $content = $request->getContent();
        $unsafeKeywords = ['暴力', '色情', '仇恨言论'];
        
        foreach ($unsafeKeywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function unsafeContentResponse()
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'UNSAFE_CONTENT',
                'message' => '内容不安全'
            ]
        ];
    }
}
