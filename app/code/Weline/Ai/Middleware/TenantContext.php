<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

use Weline\Framework\Runtime\RequestContext;

/**
 * 租户上下文中间件
 */
class TenantContext
{
    public const REQUEST_CONTEXT_KEY = 'module.Weline_Ai.tenant';

    public function handle($request, $next)
    {
        $tenantCode = $request->getHeader('X-Tenant-Code');
        
        if (empty($tenantCode)) {
            return $this->missingTenantResponse();
        }
        
        // 设置租户上下文
        $this->setTenantContext($tenantCode);
        
        return $next($request);
    }

    private function setTenantContext(string $tenantCode): void
    {
        self::mergeTenantContext([
            'tenant_code' => $tenantCode,
        ]);
    }

    public static function mergeTenantContext(array $tenantContext): void
    {
        RequestContext::set(
            self::REQUEST_CONTEXT_KEY,
            array_replace(self::getTenantContext(), $tenantContext),
        );
    }

    public static function getTenantContext(): array
    {
        $tenantContext = RequestContext::get(self::REQUEST_CONTEXT_KEY, []);
        return is_array($tenantContext) ? $tenantContext : [];
    }

    public static function resetRequestState(): void
    {
        RequestContext::remove(self::REQUEST_CONTEXT_KEY);
    }

    private function missingTenantResponse()
    {
        return [
            'success' => false,
            'error' => [
                'code' => 'MISSING_TENANT_CONTEXT',
                'message' => '缺少租户上下文'
            ]
        ];
    }
}
