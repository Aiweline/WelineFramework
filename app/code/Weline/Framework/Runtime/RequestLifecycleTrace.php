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
    private const REQUEST_CONTEXT_ID_KEY = 'request_lifecycle_trace.request_id';

    private static bool $stateManagerRegistered = false;

    /** 极端重试/风暴时防止静态 span 无限增长导致 OOM（可被 wls.debug.request_trace_max_spans 覆盖） */
    private const DEFAULT_MAX_SPANS = 4096;

    private const HEAVY_ROUTE_PREFIXES = [
        '/pagebuilder/backend/ai-site-agent/',
        '/websites/backend/site-builder-agent/',
    ];

    private static bool $maxSpansLogged = false;

    private static bool $recordingDisabledUntilReset = false;

    /** @var positive-int|null 缓存 getMaxSpansCap()，reset 时清空 */
    private static ?int $maxSpansCapCache = null;

    /** @var int|null 缓存 getMetaStringMaxBytes()（0=不截断），reset 时清空 */
    private static ?int $metaStringMaxBytesCache = null;

    private static ?bool $enabledCache = null;

    /** @var list<array{name: string, duration_ms: float, category?: string, parent?: string, meta?: array<string, mixed>}> */
    private static array $spans = [];

    private static string $requestId = '';

    private static int $nextSeq = 1;

    /** @var list<string> */
    private static array $compactRows = [];

    /** @var array<string, int> */
    private static array $nameIds = [];

    /** @var array<int, string> */
    private static array $names = [];

    /** @var array<string, int> */
    private static array $categoryIds = [];

    /** @var array<int, string> */
    private static array $categories = [];

    /** @var array<string, int> */
    private static array $metaIds = [];

    /** @var array<int, array<string, mixed>> */
    private static array $metas = [];

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
        if (self::$enabledCache !== null) {
            return self::$enabledCache;
        }

        $enabled = false;
        if (self::isExplicitPersistentTraceRequested()) {
            $enabled = true;
        } elseif (\defined('DEV') && DEV) {
            $enabled = true;
        } elseif (\defined('DEBUG') && DEBUG) {
            $enabled = true;
        }

        if (!$enabled) {
            self::$enabledCache = false;
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
            self::$enabledCache = false;
            return false;
        }

        if (self::shouldSkipForCurrentRequest()) {
            self::$enabledCache = false;
            return false;
        }

        self::$enabledCache = true;
        return true;
    }

    private static function isPersistentRequestTraceAllowed(): bool
    {
        if (!\class_exists(\Weline\Framework\App\Env::class, false)) {
            return self::isExplicitPersistentTraceRequested();
        }

        $panelEnabled = (bool)\Weline\Framework\App\Env::get('wls.debug.dev_tool_panel', false);

        return self::isExplicitPersistentTraceRequested()
            || (bool)\Weline\Framework\App\Env::get('wls.debug.request_trace', $panelEnabled);
    }

    private static function isExplicitPersistentTraceRequested(): bool
    {
        $header = (string)($_SERVER['HTTP_X_WELINE_TRACE'] ?? '');
        if ($header === '1' || \strtolower($header) === 'true') {
            return true;
        }

        $query = (string)($_SERVER['QUERY_STRING'] ?? '');
        if ($query === '') {
            $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');
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
        if (self::$recordingDisabledUntilReset) {
            return;
        }
        $maxSpans = self::getMaxSpansCap();
        if (\count(self::$spans) >= $maxSpans) {
            if (!self::$maxSpansLogged) {
                self::$maxSpansLogged = true;
                \error_log('[RequestLifecycleTrace] span 已达上限 ' . (string) $maxSpans . '，已停止记录直至 reset');
            }
            self::$recordingDisabledUntilReset = true;

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
            $span['meta'] = self::sanitizeMetaForStorage($meta);
        }
        self::$spans[] = $span;
        self::appendCompactSpan($span);
    }

    public static function ensureRequestId(): string
    {
        if (self::hasRequestContextScope()) {
            $contextRequestId = (string) RequestContext::get(self::REQUEST_CONTEXT_ID_KEY, '');
            if ($contextRequestId !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $contextRequestId)) {
                self::$requestId = $contextRequestId;
                return $contextRequestId;
            }

            $requestId = self::resolveContextBackedRequestId();
            RequestContext::set(self::REQUEST_CONTEXT_ID_KEY, $requestId);
            self::$requestId = $requestId;

            return $requestId;
        }

        if (self::$requestId !== '') {
            return self::$requestId;
        }

        self::$requestId = self::resolveNewRequestId();

        return self::$requestId;
    }

    private static function resolveContextBackedRequestId(): string
    {
        $incoming = self::resolveIncomingRequestId();
        if ($incoming !== '') {
            return $incoming;
        }

        $contextId = (string)(RequestContext::getRequestId() ?? '');
        if ($contextId !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $contextId)) {
            return $contextId;
        }

        if (self::$requestId !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', self::$requestId)) {
            return self::$requestId;
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
        $incoming = (string)($_SERVER['HTTP_X_WELINE_REQUEST_ID'] ?? $_SERVER['HTTP_X_REQUEST_ID'] ?? '');
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
        $spans = self::getSpansWithDbSummary();
        if (empty(self::$compactRows) && !empty($spans)) {
            foreach ($spans as $span) {
                self::appendCompactSpan($span);
            }
        }

        $dbDurationMs = 0.0;
        $categoryCounts = [];
        foreach ($spans as $span) {
            $category = (string)($span['category'] ?? 'framework');
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
            if ($category === 'db') {
                $dbDurationMs += (float)($span['duration_ms'] ?? 0.0);
            }
        }

        return [
            'request_id' => self::ensureRequestId(),
            'format' => 'compact-v1',
            'trace' => \implode("\n", self::$compactRows),
            'dict' => [
                'names' => self::$names,
                'categories' => self::$categories,
                'metas' => self::$metas,
            ],
            'summary' => [
                'span_count' => \count($spans),
                'db_duration_ms' => \round($dbDurationMs, 2),
                'category_counts' => $categoryCounts,
                'truncated' => self::$recordingDisabledUntilReset,
                'max_spans' => self::getMaxSpansCap(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $span
     */
    private static function appendCompactSpan(array $span): void
    {
        $seq = self::$nextSeq++;
        $nameId = self::dictId((string)($span['name'] ?? ''), self::$nameIds, self::$names);
        $parentId = self::dictId((string)($span['parent'] ?? ''), self::$nameIds, self::$names);
        $categoryId = self::dictId((string)($span['category'] ?? 'framework'), self::$categoryIds, self::$categories);
        $durationUs = (int)\round(((float)($span['duration_ms'] ?? 0.0)) * 1000);
        $metaId = self::metaId(\is_array($span['meta'] ?? null) ? $span['meta'] : []);

        self::$compactRows[] = \implode('|', [$seq, $parentId, $categoryId, $nameId, $durationUs, $metaId]);
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
    private static function metaId(array $meta): int
    {
        if (empty($meta)) {
            return 0;
        }
        $json = \json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!\is_string($json) || $json === '') {
            return 0;
        }
        if (isset(self::$metaIds[$json])) {
            return self::$metaIds[$json];
        }
        $id = \count(self::$metas) + 1;
        self::$metaIds[$json] = $id;
        self::$metas[$id] = $meta;

        return $id;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function sanitizeMetaForStorage(array $meta): array
    {
        $max = self::getMetaStringMaxBytes();
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

    private static function getMaxSpansCap(): int
    {
        if (self::$maxSpansCapCache !== null) {
            return self::$maxSpansCapCache;
        }

        $cap = self::DEFAULT_MAX_SPANS;
        if (\class_exists(\Weline\Framework\App\Env::class, false)) {
            $configured = (int)\Weline\Framework\App\Env::get('wls.debug.request_trace_max_spans', self::DEFAULT_MAX_SPANS);
            if ($configured > 0) {
                $cap = \min($configured, 65535);
            }
        }

        self::$maxSpansCapCache = \max(1, $cap);

        return self::$maxSpansCapCache;
    }

    private static function getMetaStringMaxBytes(): int
    {
        if (self::$metaStringMaxBytesCache !== null) {
            return self::$metaStringMaxBytesCache;
        }

        if (!\class_exists(\Weline\Framework\App\Env::class, false)) {
            self::$metaStringMaxBytesCache = 0;

            return 0;
        }

        $configured = (int)\Weline\Framework\App\Env::get('wls.debug.request_trace_meta_max_bytes', 0);
        if ($configured === 0) {
            self::$metaStringMaxBytesCache = 0;

            return 0;
        }
        if ($configured > 0) {
            self::$metaStringMaxBytesCache = \min($configured, 1048576);

            return self::$metaStringMaxBytesCache;
        }

        self::$metaStringMaxBytesCache = 2048;

        return self::$metaStringMaxBytesCache;
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
        self::$requestId = '';
        self::$nextSeq = 1;
        self::$compactRows = [];
        self::$nameIds = [];
        self::$names = [];
        self::$categoryIds = [];
        self::$categories = [];
        self::$metaIds = [];
        self::$metas = [];
        self::$maxSpansLogged = false;
        self::$recordingDisabledUntilReset = false;
        self::$maxSpansCapCache = null;
        self::$metaStringMaxBytesCache = null;
        self::$enabledCache = null;
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
