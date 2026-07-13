<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * WLS 多 Fiber 并发探测（Worker 注册，Runtime 查询）
 *
 * Worker 在存在挂起请求 Fiber（如 SSE suspend）时，{@see getOtherSuspendedRequestFiberCount()} 大于 0。
 * 当 {@see getOtherSuspendedRequestFiberCount()} 大于 0 时，{@see WlsRuntime::reset()} 会对 {@see StateManager::reset()}
 * 传入 {@see callbackNamesOmittableWithPeerFibers()}，跳过已由 {@see StateManager::runWlsPersistentRequestEntryBaseline()}
 * 覆盖且不宜在「他 Fiber 仍挂起」时重复执行的回调；Session/SseContext/RequestContext/DB 等仍在 finally 全量清理。
 *
 * 数据层：多 Fiber 共享 DB 连接池时，若在事务未提交前 yield，理论上可能交叉；业务侧应避免长事务跨 yield。
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
     * {@see StateManager::reset()} 可按名跳过的回调（供实验或后续 peer 感知策略使用）。
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
            'slot_renderer_cache',
            'theme_config_blocks',
            'theme_data_request_state',
            'preview_token_request_state',
            'menu_render_service',
            'acl_taglib_reset',
            'message_manager_request_state',
            'events_manager_observer_cache',
            'widget_taglib_cache',
            'view_hook_runtime_cache',
            'virtual_theme_context',
            'process_url_cache_static',
        ];
    }
}
