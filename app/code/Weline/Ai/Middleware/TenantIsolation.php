<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

use Weline\Ai\Service\AiTenantService;
use Weline\Framework\Http\Request;

/**
 * 多租户隔离中间件
 * 
 * 功能：
 * - 验证租户上下文
 * - 设置租户数据隔离
 * - 防止跨租户数据访问
 * - 记录租户活动
 * 
 * @package Weline_Ai
 */
class TenantIsolation
{
    private AiTenantService $tenantService;
    private Request $request;

    public function __construct(
        AiTenantService $tenantService,
        Request $request
    ) {
        $this->tenantService = $tenantService;
        $this->request = $request;
    }

    /**
     * 处理租户隔离
     *
     * @param mixed $request
     * @param callable $next
     * @return mixed
     */
    public function handle($request, callable $next)
    {
        // 从认证上下文获取租户ID
        $tenantId = $this->request->getData('tenant_id');
        
        if (empty($tenantId)) {
            // 尝试从请求头获取
            $tenantCode = $this->request->getHeader('X-Tenant-Code');
            
            if (!empty($tenantCode)) {
                // 通过租户代码查找租户
                $tenant = $this->tenantService->getTenantByCode($tenantCode);
                if ($tenant) {
                    $tenantId = $tenant->getId();
                }
            }
        }

        if (empty($tenantId)) {
            return $this->missingTenantResponse();
        }

        // 验证租户状态
        $tenant = $this->tenantService->getTenantById((int)$tenantId);
        if (!$tenant || !$tenant->isActive()) {
            return $this->inactiveTenantResponse();
        }

        // 设置当前请求的租户上下文
        $this->setTenantContext($tenantId, $tenant->getData('name'));

        // 记录租户活动
        $this->tenantService->recordActivity((int)$tenantId);

        return $next($request);
    }

    /**
     * 设置租户上下文
     *
     * @param int|string $tenantId
     * @param string $tenantName
     * @return void
     */
    private function setTenantContext($tenantId, string $tenantName): void
    {
        // 设置到请求对象
        $this->request->setData('current_tenant_id', $tenantId);
        $this->request->setData('current_tenant_name', $tenantName);

        TenantContext::mergeTenantContext([
            'tenant_id' => (int)$tenantId,
            'tenant_name' => $tenantName,
            'set_at' => time(),
        ]);
    }

    /**
     * 获取当前租户ID（静态方法）
     *
     * @return int|null
     */
    public static function getCurrentTenantId(): ?int
    {
        $tenantId = TenantContext::getTenantContext()['tenant_id'] ?? null;
        return $tenantId === null ? null : (int)$tenantId;
    }

    /**
     * 检查是否已设置租户上下文
     *
     * @return bool
     */
    public static function hasTenantContext(): bool
    {
        return array_key_exists('tenant_id', TenantContext::getTenantContext());
    }

    /**
     * 返回缺少租户响应
     *
     * @return array
     */
    private function missingTenantResponse(): array
    {
        http_response_code(400);
        return [
            'success' => false,
            'error' => [
                'code' => 'MISSING_TENANT_CONTEXT',
                'message' => '缺少租户上下文信息'
            ]
        ];
    }

    /**
     * 返回租户不可用响应
     *
     * @return array
     */
    private function inactiveTenantResponse(): array
    {
        http_response_code(403);
        return [
            'success' => false,
            'error' => [
                'code' => 'TENANT_INACTIVE',
                'message' => '租户不可用或已被暂停'
            ]
        ];
    }
}
