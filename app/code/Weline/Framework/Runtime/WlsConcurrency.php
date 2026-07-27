<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * WLS 多 Fiber 并发探测（Worker 注册，Runtime 查询）
 *
 * Worker 在存在挂起请求 Fiber（如 SSE suspend）时，{@see getOtherSuspendedRequestFiberCount()} 大于 0。
 * 当 {@see getOtherSuspendedRequestFiberCount()} 大于 0 时，{@see WlsRuntime::reset()} 会对 {@see StateManager::reset()}
 * 传入 {@see callbackNamesOmittableWithPeerFibers()}，跳过已由 {@see StateManager::runWlsPersistentRequestEntryBaseline()}
 * 覆盖且不宜在「他 Fiber 仍挂起」时重复执行的回调；模块 resetter、Session、SseContext、RequestContext、
 * DB、Request 与 Template 等请求边界回调仍在 finally 全量清理。
 *
 * 已注册静态请求状态由 {@see WlsFiberContext::captureForFiber()} 随目标 Fiber 捕获/恢复；模块请求状态必须
 * 收敛到 Fiber-local {@see RequestContext}。DB 清理不在 omit 白名单，事务也不得跨协作式 yield。
 *
 * 静态审计（本地可重复执行，结果需人工分类是否为「请求级」）：
 * `rg "private static \\$" app/code -g"*.php"`，对命中类检查是否已 registerStaticReset / reset 回调。
 */
final class WlsConcurrency
{
    /** @var callable():int|null */
    private static $otherSuspendedFiberCountProvider = null;

    /**
     * Worker 主循环注册：返回当前挂起的请求 Fiber 数量（不含已同步跑完、未入池的 Fiber）。
     *
     * @param callable():int $provider
     */
    public static function setOtherSuspendedFiberCountProvider(?callable $provider): void
    {
        self::$otherSuspendedFiberCountProvider = $provider;
    }

    /**
     * 其他挂起中的请求 Fiber 数量；未注册或非 WLS 时为 0。
     */
    public static function getOtherSuspendedRequestFiberCount(): int
    {
        if (self::$otherSuspendedFiberCountProvider === null) {
            return 0;
        }
        try {
            $n = (int) (self::$otherSuspendedFiberCountProvider)();
        } catch (\Throwable) {
            return 0;
        }

        return \max(0, $n);
    }

    /**
     * Process-wide caches may only be compacted when no request Fiber can
     * still observe them. The worker-owned provider is the sole concurrency
     * fact source. An unregistered provider keeps FPM/non-WLS compatibility;
     * a registered provider that throws or reports an invalid negative count
     * fails closed and postpones compaction.
     *
     * Some transports conservatively include the current resumed Fiber in
     * their active set. In that case compaction is postponed until the set is
     * empty, which is safer than clearing caches visible to a peer request.
     */
    public static function canCompactProcessCaches(): bool
    {
        if (self::$otherSuspendedFiberCountProvider === null) {
            return true;
        }

        try {
            return (int)(self::$otherSuspendedFiberCountProvider)() === 0;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * {@see StateManager::reset()} 可按名跳过的回调。
     *
     * 每一项都必须同时满足：由 {@see StateManager::registerFrameworkResets()} 实际注册，且在
     * {@see StateManager::runWlsPersistentRequestEntryBaseline()} 或同一请求入口已有等价清理。
     * Fiber RequestContext 中的模块状态不属于此白名单。
     *
     * @return list<string>
     */
    public static function callbackNamesOmittableWithPeerFibers(): array
    {
        return [
            'request_scoped_objects',
            'state_instance',
            'router_core_instance',
            'controller_instances',
            'model_instances',
            'observer_instances',
            'message_manager_request_state',
            'events_manager_observer_cache',
            'view_hook_runtime_cache',
            'process_url_cache_static',
        ];
    }
}
