<?php
declare(strict_types=1);

namespace Weline\Ai\Middleware;

/**
 * 租户上下文管理器
 * 
 * 封装租户上下文的存储和访问，支持 Fiber 安全的环境变量存储。
 * 
 * @package Weline\Ai\Middleware
 */
class TenantContext
{
    private const CONTEXT_KEY = 'ai_tenant_context';

    /**
     * 设置租户上下文
     *
     * @param int|string $tenantId
     * @param string $tenantName
     * @return void
     */
    public static function set(int|string $tenantId, string $tenantName): void
    {
        $context = [
            'tenant_id' => $tenantId,
            'tenant_name' => $tenantName,
            'set_at' => time()
        ];
        // 存储到 WelineEnv 以支持 Fiber 隔离
        \w_env_set(self::CONTEXT_KEY, $context);
        // 同时设置到 $GLOBALS 保持向后兼容
        $GLOBALS[self::CONTEXT_KEY] = $context;
    }

    /**
     * 获取当前租户上下文
     *
     * @return array|null
     */
    public static function get(): ?array
    {
        return w_env(self::CONTEXT_KEY) ?? $GLOBALS[self::CONTEXT_KEY] ?? null;
    }

    /**
     * 获取当前租户ID
     *
     * @return int|null
     */
    public static function getTenantId(): ?int
    {
        $context = self::get();
        return $context['tenant_id'] ?? null;
    }

    /**
     * 获取当前租户名称
     *
     * @return string|null
     */
    public static function getTenantName(): ?string
    {
        $context = self::get();
        return $context['tenant_name'] ?? null;
    }

    /**
     * 检查是否已设置租户上下文
     *
     * @return bool
     */
    public static function has(): bool
    {
        return self::get() !== null;
    }

    /**
     * 清除租户上下文
     *
     * @return void
     */
    public static function clear(): void
    {
        \w_env_set(self::CONTEXT_KEY, null);
        unset($GLOBALS[self::CONTEXT_KEY]);
    }
}
