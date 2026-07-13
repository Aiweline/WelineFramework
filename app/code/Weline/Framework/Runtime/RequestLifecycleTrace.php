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
use Weline\Framework\Env\WelineEnv;

/**
 * Mutable trace data for exactly one main/fiber/request scope.
 *
 * @internal RequestLifecycleTrace is the only public entry point.
 */
final class RequestLifecycleTraceState
{
    public ?bool $enabledCache = null;
    public bool $maxSpansLogged = false;
    public bool $recordingDisabledUntilReset = false;
    public ?int $maxSpansCapCache = null;
    public ?int $metaStringMaxBytesCache = null;

    /** @var list<array{name: string, duration_ms: float, category?: string, parent?: string, meta?: array<string, mixed>}> */
    public array $spans = [];

    public string $requestId = '';
    public int $nextSeq = 1;

    /** @var list<string> */
    public array $compactRows = [];

    /** @var array<string, int> */
    public array $nameIds = [];

    /** @var array<int, string> */
    public array $names = [];

    /** @var array<string, int> */
    public array $categoryIds = [];

    /** @var array<int, string> */
    public array $categories = [];

    /** @var array<string, int> */
    public array $metaIds = [];

    /** @var array<int, array<string, mixed>> */
    public array $metas = [];

    /** @var array<string, float> */
    public array $startStack = [];

    /** @var list<string> */
    public array $currentParentStack = [];

    public function __construct(public string $scopeId)
    {
    }
}

class RequestLifecycleTrace
{
    private const REQUEST_CONTEXT_ID_KEY = 'request_lifecycle_trace.request_id';

    private static bool $stateManagerRegistered = false;

    /** 极端重试/风暴时防止静态 span 无限增长导致 OOM（可被 wls.debug.request_trace_max_spans 覆盖） */
    private const DEFAULT_MAX_SPANS = 4096;

    private const HEAVY_ROUTE_PREFIXES = [
        '/websites/backend/site-builder-agent/',
    ];

    /** @var \WeakMap<\Fiber, RequestLifecycleTraceState>|null */
    private static ?\WeakMap $fiberStates = null;

    /** @var \WeakMap<Context, RequestLifecycleTraceState>|null */
    private static ?\WeakMap $contextStates = null;

    private static ?RequestLifecycleTraceState $mainState = null;

    /**
     * Resolve trace storage without sharing mutable state between request Fibers.
     *
     * A Fiber is the primary WLS isolation boundary. The request id additionally
     * fences a reused Context/main execution scope. FPM keeps the historical
     * single-request behavior through the Context/main branches.
     */
    private static function state(): RequestLifecycleTraceState
    {
        $scopeId = self::currentScopeId();
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber !== null) {
            self::$fiberStates ??= new \WeakMap();
            $state = self::$fiberStates[$fiber] ?? null;
            if (!$state instanceof RequestLifecycleTraceState
                || self::scopeTransitionIsNewRequest($state->scopeId, $scopeId)
            ) {
                $state = new RequestLifecycleTraceState($scopeId);
                self::$fiberStates[$fiber] = $state;
            } elseif ($state->scopeId !== $scopeId) {
                // Preserve pre-RequestContext bootstrap spans in this Fiber.
                $state->scopeId = $scopeId;
            }

            return $state;
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            self::$contextStates ??= new \WeakMap();
            $state = self::$contextStates[$context] ?? null;
            if (!$state instanceof RequestLifecycleTraceState
                && self::$mainState instanceof RequestLifecycleTraceState
                && (!\class_exists(Runtime::class, false) || !Runtime::isPersistent())
            ) {
                // FPM can create its Context after early bootstrap events.
                $state = self::$mainState;
                self::$mainState = null;
            }
            if (!$state instanceof RequestLifecycleTraceState
                || self::scopeTransitionIsNewRequest($state->scopeId, $scopeId)
            ) {
                $state = new RequestLifecycleTraceState($scopeId);
                self::$contextStates[$context] = $state;
            } elseif ($state->scopeId !== $scopeId) {
                $state->scopeId = $scopeId;
                self::$contextStates[$context] = $state;
            }

            return $state;
        }

        if (!self::$mainState instanceof RequestLifecycleTraceState
            || self::$mainState->scopeId !== $scopeId
        ) {
            self::$mainState = new RequestLifecycleTraceState($scopeId);
        }

        return self::$mainState;
    }

    private static function scopeTransitionIsNewRequest(string $from, string $to): bool
    {
        return $from !== $to
            && \str_starts_with($from, 'request:')
            && \str_starts_with($to, 'request:');
    }

    private static function currentScopeId(): string
    {
        if (\class_exists(RequestContext::class, false)) {
            $requestId = (string)(RequestContext::getRequestId() ?? '');
            if ($requestId !== '') {
                return 'request:' . $requestId;
            }
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            return 'context:' . \spl_object_id($context);
        }

        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber !== null) {
            return 'fiber:' . \spl_object_id($fiber);
        }

        return 'main';
    }

    /**
     * 是否启用（DEV 或 DEBUG 时启用，便于开发环境与调试时查看请求链路）
     */
    public static function isEnabled(): bool
    {
        $state = self::state();
        if ($state->enabledCache !== null) {
            return $state->enabledCache;
        }

        $enabled = false;
        if (self::isExplicitPersistentTraceRequested()) {
            $enabled = true;
        } elseif (\defined('DEV') && DEV) {
            $enabled = true;
        } elseif (\defined('DEBUG') && DEBUG) {
            $enabled = true;
        } elseif (\defined('WLS_DEV_MODE') && WLS_DEV_MODE) {
            $enabled = true;
        }

        if (!$enabled) {
            $state->enabledCache = false;
            return false;
        }


        // Master / Dispatcher / Session / Memory 等常驻进程不会进入请求级 init/cleanup，
        // 若在这些进程继续启用 trace，spans 会永久累积。
        if (\class_exists(RequestContext::class, false) && !RequestContext::isInitialized()) {
            return false;
        }

        if (\class_exists(Runtime::class, false)
            && Runtime::isPersistent()
            && !self::isPersistentRequestTraceAllowed()
        ) {
            $state->enabledCache = false;
            return false;
        }

        if (self::shouldSkipForCurrentRequest()) {
            $state->enabledCache = false;
            return false;
        }

        $state->enabledCache = true;
        return true;
    }

    private static function isPersistentRequestTraceAllowed(): bool
    {
        if (!\class_exists(\Weline\Framework\App\Env::class, false)) {
            return self::isExplicitPersistentTraceRequested();
        }

        $panelEnabled = (\defined('DEV') && DEV)
            || (\defined('DEBUG') && DEBUG)
            || (\defined('WLS_DEV_MODE') && WLS_DEV_MODE);
        try {
            $panelEnabled = \Weline\Framework\Manager\ObjectManager::getInstance(
                DeveloperAccessPolicy::class
            )->shouldInjectBootstrap();
        } catch (\Throwable) {
        }

        return self::isExplicitPersistentTraceRequested()
            || (bool)\Weline\Framework\App\Env::get('wls.debug.request_trace', $panelEnabled);
    }

    private static function isExplicitPersistentTraceRequested(): bool
    {
        $header = (string)WelineEnv::server('HTTP_X_WELINE_TRACE', '');
        if ($header === '1' || \strtolower($header) === 'true') {
            return true;
        }

        $query = (string)WelineEnv::server('QUERY_STRING', '');
        if ($query === '') {
            $requestUri = (string)WelineEnv::server('REQUEST_URI', '');
            if ($requestUri === '' && \function_exists('w_env_request_uri')) {
                $requestUri = (string)\w_env_request_uri();
            }
            if ($requestUri === '' && \function_exists('w_env')) {
                $requestUri = (string)\w_env('request.uri', '');
            }
            $parts = \parse_url($requestUri);
            $query = \is_array($parts) ? (string)($parts['query'] ?? '') : '';
        }
        if ($query === '') {
            return false;
        }

        \parse_str($query, $params);
        $flag = $params['wls_trace'] ?? null;
        if (\is_array($flag)) {
            return false;
        }

        $value = \strtolower((string)$flag);
        return $value === '1' || $value === 'true';
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
        $state = self::state();
        if ($state->recordingDisabledUntilReset) {
            return;
        }
        $maxSpans = self::getMaxSpansCap($state);
        if (\count($state->spans) >= $maxSpans) {
            if (!$state->maxSpansLogged) {
                $state->maxSpansLogged = true;
                \error_log('[RequestLifecycleTrace] span 已达上限 ' . (string) $maxSpans . '，已停止记录直至 reset');
            }
            $state->recordingDisabledUntilReset = true;

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
            $span['meta'] = self::sanitizeMetaForStorage($meta, $state);
        }
        $state->spans[] = $span;
        self::appendCompactSpan($span, $state);
    }

    public static function ensureRequestId(): string
    {
        $state = self::state();
        if (self::hasRequestContextScope()) {
            $contextRequestId = (string) RequestContext::get(self::REQUEST_CONTEXT_ID_KEY, '');
            if ($contextRequestId !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $contextRequestId)) {
                $state->requestId = $contextRequestId;
                return $contextRequestId;
            }

            $requestId = self::resolveContextBackedRequestId($state);
            RequestContext::set(self::REQUEST_CONTEXT_ID_KEY, $requestId);
            $state->requestId = $requestId;

            return $requestId;
        }

        if ($state->requestId !== '') {
            return $state->requestId;
        }

        $state->requestId = self::resolveNewRequestId();

        return $state->requestId;
    }

    private static function resolveContextBackedRequestId(RequestLifecycleTraceState $state): string
    {
        $incoming = self::resolveIncomingRequestId();
        if ($incoming !== '') {
            return $incoming;
        }

        $contextId = (string)(RequestContext::getRequestId() ?? '');
        if ($contextId !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $contextId)) {
            return $contextId;
        }

        if ($state->requestId !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $state->requestId)) {
            return $state->requestId;
        }

        return self::resolveNewRequestId();
    }

    private static function resolveNewRequestId(): string
    {
        $incoming = self::resolveIncomingRequestId();
        if ($incoming !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $incoming)) {
            return $incoming;
        }

        try {
            return \bin2hex(\random_bytes(8)) . '-' . \dechex((int)(\microtime(true) * 1000000));
        } catch (\Throwable) {
            return \str_replace('.', '', \uniqid('req', true));
        }
    }

    private static function resolveIncomingRequestId(): string
    {
        $incoming = (string)(
            WelineEnv::server('HTTP_X_WELINE_REQUEST_ID', '')
            ?: WelineEnv::server('HTTP_X_REQUEST_ID', '')
            ?: ($_SERVER['HTTP_X_WELINE_REQUEST_ID'] ?? '')
            ?: ($_SERVER['HTTP_X_REQUEST_ID'] ?? '')
        );
        if ($incoming !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $incoming)) {
            return $incoming;
        }

        return '';
    }

    private static function hasRequestContextScope(): bool
    {
        return \class_exists(RequestContext::class, false)
            && (RequestContext::isInitialized() || RequestContext::getRequestId() !== null);
    }

    /**
     * @return array{request_id: string, format: string, trace: string, dict: array<string, mixed>, summary: array<string, mixed>}
     */
    public static function exportCompactPayload(): array
    {
        $state = self::state();
        $spans = self::getSpansWithDbSummary();
        if (empty($state->compactRows) && !empty($spans)) {
            foreach ($spans as $span) {
                self::appendCompactSpan($span, $state);
            }
        }

        $dbDurationMs = 0.0;
        $totalMs = 0.0;
        $categoryCounts = [];
        $categoryTotals = [];
        foreach ($spans as $span) {
            $category = (string)($span['category'] ?? 'framework');
            $durationMs = (float)($span['duration_ms'] ?? 0.0);
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            $categoryTotals[$category] = ($categoryTotals[$category] ?? 0.0) + $durationMs;
            if ((string)($span['parent'] ?? '') === '') {
                $totalMs += $durationMs;
            }
            if ($category === 'db') {
                $dbDurationMs += $durationMs;
            }
        }
        if ($totalMs <= 0.0) {
            $totalMs = (float)\array_sum($categoryTotals);
        }

        return [
            'request_id' => self::ensureRequestId(),
            'format' => 'compact-v1',
            'trace' => \implode("\n", $state->compactRows),
            'dict' => [
                'names' => $state->names,
                'categories' => $state->categories,
                'metas' => $state->metas,
            ],
            'summary' => [
                'span_count' => \count($spans),
                'request_id' => self::ensureRequestId(),
                'total_ms' => \round($totalMs, 2),
                'db_duration_ms' => \round($dbDurationMs, 2),
                'category_counts' => $categoryCounts,
                'category_totals' => self::roundFloatMap($categoryTotals),
                'truncated' => $state->recordingDisabledUntilReset,
                'max_spans' => self::getMaxSpansCap($state),
            ],
        ];
    }

    /**
     * @param array<string, float> $values
     * @return array<string, float>
     */
    private static function roundFloatMap(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $out[$key] = \round((float)$value, 2);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $span
     */
    private static function appendCompactSpan(
        array $span,
        RequestLifecycleTraceState $state
    ): void
    {
        $seq = $state->nextSeq++;
        $nameId = self::dictId((string)($span['name'] ?? ''), $state->nameIds, $state->names);
        $parentId = self::dictId((string)($span['parent'] ?? ''), $state->nameIds, $state->names);
        $categoryId = self::dictId((string)($span['category'] ?? 'framework'), $state->categoryIds, $state->categories);
        $durationUs = (int)\round(((float)($span['duration_ms'] ?? 0.0)) * 1000);
        $metaId = self::metaId(\is_array($span['meta'] ?? null) ? $span['meta'] : [], $state);

        $state->compactRows[] = \implode('|', [$seq, $parentId, $categoryId, $nameId, $durationUs, $metaId]);
    }

    /**
     * @param array<string, int> $lookup
     * @param array<int, string> $dict
     */
    private static function dictId(string $value, array &$lookup, array &$dict): int
    {
        if ($value === '') {
            return 0;
        }
        if (isset($lookup[$value])) {
            return $lookup[$value];
        }
        $id = \count($dict) + 1;
        $lookup[$value] = $id;
        $dict[$id] = $value;

        return $id;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private static function metaId(array $meta, RequestLifecycleTraceState $state): int
    {
        if (empty($meta)) {
            return 0;
        }
        $json = \json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_string($json) || $json === '') {
            return 0;
        }
        if (isset($state->metaIds[$json])) {
            return $state->metaIds[$json];
        }
        $id = \count($state->metas) + 1;
        $state->metaIds[$json] = $id;
        $state->metas[$id] = $meta;

        return $id;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function sanitizeMetaForStorage(
        array $meta,
        RequestLifecycleTraceState $state
    ): array
    {
        $max = self::getMetaStringMaxBytes($state);
        if ($max <= 0) {
            return $meta;
        }

        $suffix = '...(truncated)';
        $out = [];
        foreach ($meta as $key => $value) {
            if (\is_string($value) && \strlen($value) > $max) {
                $out[$key] = \substr($value, 0, $max) . $suffix;
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    private static function getMaxSpansCap(RequestLifecycleTraceState $state): int
    {
        if ($state->maxSpansCapCache !== null) {
            return $state->maxSpansCapCache;
        }

        $cap = self::DEFAULT_MAX_SPANS;
        if (\class_exists(\Weline\Framework\App\Env::class, false)) {
            $configured = (int)\Weline\Framework\App\Env::get('wls.debug.request_trace_max_spans', self::DEFAULT_MAX_SPANS);
            if ($configured > 0) {
                $cap = \min($configured, 65535);
            }
        }

        $state->maxSpansCapCache = \max(1, $cap);

        return $state->maxSpansCapCache;
    }

    private static function getMetaStringMaxBytes(RequestLifecycleTraceState $state): int
    {
        if ($state->metaStringMaxBytesCache !== null) {
            return $state->metaStringMaxBytesCache;
        }

        if (!\class_exists(\Weline\Framework\App\Env::class, false)) {
            $state->metaStringMaxBytesCache = 0;

            return 0;
        }

        $configured = (int)\Weline\Framework\App\Env::get('wls.debug.request_trace_meta_max_bytes', 0);
        if ($configured === 0) {
            $state->metaStringMaxBytesCache = 0;

            return 0;
        }
        if ($configured > 0) {
            $state->metaStringMaxBytesCache = \min($configured, 1048576);

            return $state->metaStringMaxBytesCache;
        }

        $state->metaStringMaxBytesCache = 2048;

        return $state->metaStringMaxBytesCache;
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
        self::state()->currentParentStack[] = $observerSpanName;
    }

    /**
     * 观察者执行结束时出栈（回到事件调用结束才算完）
     */
    public static function popCurrentParent(): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $state = self::state();
        if ($state->currentParentStack === []) {
            return;
        }
        \array_pop($state->currentParentStack);
    }

    /**
     * 当前链路上下文（栈顶），用于嵌套事件挂父
     */
    public static function getCurrentParent(): ?string
    {
        $stack = self::state()->currentParentStack;
        if ($stack === []) {
            return null;
        }
        return $stack[\array_key_last($stack)];
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
        self::state()->startStack[$name] = \microtime(true);
    }

    /**
     * 结束一个 span 并记录耗时
     */
    public static function endSpan(string $name, string $category = 'framework'): void
    {
        if (!self::isEnabled()) {
            return;
        }
        $state = self::state();
        if (!isset($state->startStack[$name])) {
            return;
        }
        $durationMs = (\microtime(true) - $state->startStack[$name]) * 1000;
        unset($state->startStack[$name]);
        self::recordSpan($name, $durationMs, $category);
    }

    /**
     * 获取当前请求已记录的所有 span（按顺序）
     *
     * @return list<array{name: string, duration_ms: float, category?: string, parent?: string, meta?: array<string, mixed>}>
     */
    public static function getSpans(): array
    {
        return self::state()->spans;
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
        $spans = self::state()->spans;

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
        $spans = self::state()->spans;
        if ($name === '' || $spans === []) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($spans as $span) {
            if (($span['name'] ?? '') !== $name) {
                continue;
            }
            $total += (float)($span['duration_ms'] ?? 0.0);
        }

        return round($total, 2);
    }

    public static function reset(): void
    {
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber !== null) {
            if (self::$fiberStates !== null && isset(self::$fiberStates[$fiber])) {
                unset(self::$fiberStates[$fiber]);
            }

            return;
        }

        $context = Context::getCurrent();
        if ($context !== null) {
            if (self::$contextStates !== null && isset(self::$contextStates[$context])) {
                unset(self::$contextStates[$context]);
            }

            return;
        }

        self::$mainState = null;
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

        return (string)WelineEnv::server('REQUEST_URI', '');
    }
}
