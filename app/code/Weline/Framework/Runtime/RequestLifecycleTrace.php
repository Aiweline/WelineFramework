<?php

declare(strict_types=1);

/**
 * Weline Framework - 请求生命周期链路追踪
 *
 * 仅在 DEV 模式下记录当前请求各阶段耗时，供开发面板「请求链路」Tab 展示。
 * WLS 下需在 StateManager 注册重置，避免跨请求残留。
 */

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;

class RequestLifecycleTrace
{
    private static bool $stateManagerRegistered = false;

    /** 极端重试/风暴时防止静态 span 无限增长导致 OOM */
    private const MAX_SPANS = 4096;

    private const HEAVY_ROUTE_PREFIXES = [
        '/pagebuilder/backend/ai-site-agent/',
        '/websites/backend/site-builder-agent/',
    ];

    private static bool $maxSpansLogged = false;

    /** @var list<array{name: string, duration_ms: float, category?: string, parent?: string, meta?: array<string, mixed>}> */
    private static array $spans = [];

    /** @var array<string, float> name => start microtime */
    private static array $startStack = [];

    /**
     * 当前链路上下文栈：观察者执行时入栈，执行完出栈。
     * 嵌套派发的事件会挂到栈顶观察者下，形成「事件→观察者→子事件→子观察者」的完整链路，
     * 观察者 span 的结束以回到事件调用结束为准（含其内所有子事件耗时）。
     * @var list<string> 栈顶为当前父 span 名称，如 observer::Weline::Acl::Observer::RouteBefore
     */
    private static array $currentParentStack = [];

    /**
     * 是否启用（DEV 或 DEBUG 时启用，便于开发环境与调试时查看请求链路）
     */
    public static function isEnabled(): bool
    {
        $enabled = false;
        if (\defined('DEV') && DEV) {
            $enabled = true;
        } elseif (\defined('DEBUG') && DEBUG) {
            $enabled = true;
        }

        if (!$enabled) {
            return false;
        }

        // Master / Dispatcher / Session / Memory 等常驻进程不会进入请求级 init/cleanup，
        // 若在这些进程继续启用 trace，spans 会永久累积。
        if (\class_exists(RequestContext::class, false) && !RequestContext::isInitialized()) {
            return false;
        }

        if (\class_exists(Runtime::class, false)
            && Runtime::isPersistent()
            && (!\class_exists(\Weline\Framework\App\Env::class, false)
                || !(bool)\Weline\Framework\App\Env::get('wls.debug.request_trace', false))
        ) {
            return false;
        }

        if (self::shouldSkipForCurrentRequest()) {
            return false;
        }

        return true;
    }

    public static function shouldSkipForCurrentRequest(): bool
    {
        if (!\class_exists(Runtime::class, false) || !Runtime::isPersistent()) {
            return false;
        }

        if (\class_exists(\Weline\Framework\App\Env::class, false)
            && (bool)\Weline\Framework\App\Env::get('wls.debug.trace_heavy_routes', false)
        ) {
            return false;
        }

        $uri = self::currentRequestUri();
        if ($uri === '') {
            return false;
        }

        foreach (self::HEAVY_ROUTE_PREFIXES as $prefix) {
            if (\str_contains($uri, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 注册到 StateManager（WLS 请求结束后重置）
     */
    public static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }
        if (class_exists(StateManager::class)) {
            StateManager::registerResetCallback('RequestLifecycleTrace', [self::class, 'reset']);
            self::$stateManagerRegistered = true;
        }
    }

    /**
     * 记录一段已计算好的耗时（毫秒）
     *
     * @param string $name 阶段名称
     * @param float $durationMs 耗时（毫秒）
     * @param string $category 分类：framework / controller / event / observer / db（db=数据库查询，挂到当前父如 action_execute 下）
     * @param string|null $parent 父阶段名称（null 时若存在当前上下文栈顶则用栈顶，用于嵌套事件挂到当前观察者下）
     * @param array<string, mixed> $meta 额外元信息（如 sql、operation、table）
     */
    public static function recordSpan(
        string $name,
        float $durationMs,
        string $category = 'framework',
        ?string $parent = null,
        array $meta = []
    ): void
    {
        if (!self::isEnabled()) {
            return;
        }
        if (\count(self::$spans) >= self::MAX_SPANS) {
            if (!self::$maxSpansLogged) {
                self::$maxSpansLogged = true;
                \error_log('[RequestLifecycleTrace] span 已达上限 ' . (string) self::MAX_SPANS . '，已停止记录直至 reset');
            }

            return;
        }
        self::registerStateManager();
        $resolvedParent = $parent;
        if ($resolvedParent === null || $resolvedParent === '') {
            $resolvedParent = self::getCurrentParent();
        }
        $span = [
            'name' => $name,
            'duration_ms' => round($durationMs, 2),
            'category' => $category,
        ];
        if ($resolvedParent !== null && $resolvedParent !== '') {
            $span['parent'] = $resolvedParent;
        }
        if (!empty($meta)) {
            $span['meta'] = $meta;
        }
        self::$spans[] = $span;
    }

    /**
     * 进入观察者时入栈，使该观察者内派发的事件/子观察者挂到本观察者下
     */
    public static function pushCurrentParent(string $observerSpanName): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::registerStateManager();
        self::$currentParentStack[] = $observerSpanName;
    }

    /**
     * 观察者执行结束时出栈（回到事件调用结束才算完）
     */
    public static function popCurrentParent(): void
    {
        if (!self::isEnabled() || empty(self::$currentParentStack)) {
            return;
        }
        array_pop(self::$currentParentStack);
    }

    /**
     * 当前链路上下文（栈顶），用于嵌套事件挂父
     */
    public static function getCurrentParent(): ?string
    {
        if (empty(self::$currentParentStack)) {
            return null;
        }
        return self::$currentParentStack[array_key_last(self::$currentParentStack)];
    }

    /**
     * 开始一个 span（与 endSpan 成对使用）
     */
    public static function startSpan(string $name): void
    {
        if (!self::isEnabled()) {
            return;
        }
        self::registerStateManager();
        self::$startStack[$name] = microtime(true);
    }

    /**
     * 结束一个 span 并记录耗时
     */
    public static function endSpan(string $name, string $category = 'framework'): void
    {
        if (!self::isEnabled() || !isset(self::$startStack[$name])) {
            return;
        }
        $durationMs = (microtime(true) - self::$startStack[$name]) * 1000;
        unset(self::$startStack[$name]);
        self::recordSpan($name, $durationMs, $category);
    }

    /**
     * 获取当前请求已记录的所有 span（按顺序）
     *
     * @return list<array{name: string, duration_ms: float, category?: string, parent?: string, meta?: array<string, mixed>}>
     */
    public static function getSpans(): array
    {
        return self::$spans;
    }

    /**
     * 获取带数据库耗时汇总的信息：
     * - 原始 spans 结构保持不变
     * - 对于非 db 类别的 span，若其名作为 parent 挂有 db span，则附加 db_duration_ms 字段（毫秒）
     *
     * 用于 DevToolPanel「请求链路」树中在每个阶段节点上展示数据库总耗时。
     *
     * @return list<array{name: string, duration_ms: float, category?: string, parent?: string, db_duration_ms?: float, meta?: array<string, mixed>}>
     */
    public static function getSpansWithDbSummary(): array
    {
        // 先复制一份，避免直接修改内部静态数组
        $spans = self::$spans;

        if (empty($spans)) {
            return $spans;
        }

        // 1. 聚合所有 db span：按 parent 名称累计 duration_ms
        $dbByParent = [];
        foreach ($spans as $span) {
            $category = $span['category'] ?? 'framework';
            if ($category !== 'db') {
                continue;
            }
            $parent = $span['parent'] ?? null;
            if (!$parent) {
                continue;
            }
            $duration = (float)($span['duration_ms'] ?? 0.0);
            if (!isset($dbByParent[$parent])) {
                $dbByParent[$parent] = 0.0;
            }
            $dbByParent[$parent] += $duration;
        }

        if (empty($dbByParent)) {
            return $spans;
        }

        // 2. 遍历所有非 db span，如有聚合结果则附加 db_duration_ms 字段
        foreach ($spans as &$span) {
            $category = $span['category'] ?? 'framework';
            if ($category === 'db') {
                continue;
            }
            $name = $span['name'] ?? '';
            if ($name === '' || !isset($dbByParent[$name])) {
                continue;
            }
            $span['db_duration_ms'] = round($dbByParent[$name], 2);
        }
        unset($span);

        return $spans;
    }

    /**
     * 重置（WLS 状态管理调用）
     */
    public static function sumDurationsByName(string $name): float
    {
        if ($name === '' || empty(self::$spans)) {
            return 0.0;
        }

        $total = 0.0;
        foreach (self::$spans as $span) {
            if (($span['name'] ?? '') !== $name) {
                continue;
            }
            $total += (float)($span['duration_ms'] ?? 0.0);
        }

        return round($total, 2);
    }

    public static function reset(): void
    {
        self::$spans = [];
        self::$startStack = [];
        self::$currentParentStack = [];
        self::$maxSpansLogged = false;
    }

    private static function currentRequestUri(): string
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            $uri = (string)$context->get('input.uri', '');
            if ($uri !== '') {
                return $uri;
            }
        }

        return (string)($_SERVER['REQUEST_URI'] ?? '');
    }
}
