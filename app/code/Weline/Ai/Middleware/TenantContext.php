<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 租户上下文中间件
 */
class TenantContext
{
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
        // 设置租户上下文到全局变量或服务容器
        $GLOBALS['current_tenant'] = $tenantCode;
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
