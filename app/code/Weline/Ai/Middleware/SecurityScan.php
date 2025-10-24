<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 安全扫描中间件
 */
class SecurityScan
{
    public function handle($request, $next)
    {
        if ($this->isMaliciousRequest($request)) {
            return $this->securityViolationResponse();
        }
        
        return $next($request);
    }

    private function isMaliciousRequest($request): bool
    {
        // 简单的安全检查
        $content = $request->getContent();
        $maliciousPatterns = ['<script>', 'DROP TABLE', 'UNION SELECT'];
        
        foreach ($maliciousPatterns as $pattern) {
            if (strpos($content, $pattern) !== false) {
                return true;
            }
        }
        
        return false;
    }

    private function securityViolationResponse()
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'SECURITY_VIOLATION',
                'message' => '安全违规'
            ]
        ];
    }
}
