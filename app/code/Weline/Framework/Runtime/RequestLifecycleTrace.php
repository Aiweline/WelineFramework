<?php

declare(strict_types=1);

/**
 * Weline Framework - 请求生命周期链路追踪
 *
 * DEV/DEBUG 下默认关闭；仅当 Weline 开发面板打开（签名 Cookie）时记录，
 * 关闭面板即停止。不认配置开关与租约 TTL。
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
    public int $dbSpanCount = 0;
    public float $dbDurationMs = 0.0;
    public int $wlsSpanCount = 0;
    public float $wlsDurationMs = 0.0;
    public int $droppedSpanCount = 0;

    /** @var array<string, array<string, mixed>> */
    public array $phases = [];

    /** @var list<array{name: string, duration_ms: float, category?: string, parent?: string, meta?: array<string, mixed>}> */
    public array $spans = [];

    /** @var list<array<string, mixed>> Slow overflow samples, sorted by duration ascending. */
    public array $slowOverflowSpans = [];

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
    private const REDACTED_AUTH_SQL = '[REDACTED: authentication persistence statement]';
    private const PANEL_TRACE_COOKIE = 'w_weline_trace_panel';
    private const PANEL_TRACE_PAYLOAD = 'on';
    private const PANEL_TPL_PERF_COOKIE = 'w_weline_tpl_perf';
    private const PANEL_TPL_PERF_PAYLOAD = 'on';

    private static bool $stateManagerRegistered = false;

    /** 极端重试/风暴时防止静态 span 无限增长导致 OOM（可被 wls.debug.request_trace_max_spans 覆盖） */
    private const DEFAULT_MAX_SPANS = 4096;
    private const MAX_SUMMARY_PHASES = 48;

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
     * 是否启用。
     *
     * 只认 Weline 面板开/关签发的签名 Cookie；关闭面板即关闭记录。
     * 不认 DEV/DEBUG 常开、不认 wls.debug.request_trace / wls_trace 配置与查询开关。
     *
     * WLS Master / Dispatcher / Session / Memory 等控制面没有请求级 reset。
     * 无活跃 RequestContext 时必须保持关闭（且不写入 enabledCache）。
     */
    public static function isEnabled(): bool
    {
        $state = self::state();
        if ($state->enabledCache === true) {
            return true;
        }

        $hasActiveRequestContext = \class_exists(RequestContext::class, false)
            && RequestContext::isInitialized();

        // Persistent control-plane / pre-request: never enable. Do not cache —
        // Workers still need to enable after RequestContext becomes ready.
        if (\class_exists(Runtime::class, false)
            && Runtime::isPersistent()
            && !$hasActiveRequestContext
        ) {
            return false;
        }

        // false 可能在 URL 解析前被缓存；模板耗时旁路允许稍后武装 DB 埋点。
        if ($state->enabledCache === false) {
            if (self::isTemplatePerfOverlayRequested()) {
                $state->enabledCache = true;
                return true;
            }
            return false;
        }

        $enabled = self::isPanelTraceArmed() || self::isTemplatePerfOverlayRequested();

        if (!$enabled) {
            $state->enabledCache = false;
            return false;
        }

        if (\class_exists(RequestContext::class, false) && !RequestContext::isInitialized()) {
            return false;
        }

        if (self::shouldSkipForCurrentRequest()) {
            $state->enabledCache = false;
            return false;
        }

        $state->enabledCache = true;
        return true;
    }

    /**
     * 模板旁路耗时徽标：面板 token 签发的签名 cookie、env、RequestContext，或 query `wls_tpl_perf=1`。
     * 面板开关路径须经 DeveloperWorkspace `trace/tpl-perf`（PanelAccessService）武装 cookie。
     */
    public static function isTemplatePerfOverlayRequested(): bool
    {
        if (self::isPanelTplPerfArmed()) {
            return true;
        }

        try {
            if (\class_exists(RequestContext::class, false)
                && RequestContext::isInitialized()
                && RequestContext::get('view.template.overlay') === true
            ) {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(\Weline\Framework\App\Env::class, false)
                && (bool)\Weline\Framework\App\Env::get('wls.performance.template_render_overlay_enabled', false)
            ) {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            if (\class_exists(RequestContext::class, false)
                && RequestContext::isInitialized()
                && \class_exists(\Weline\Framework\Manager\ObjectManager::class, false)
            ) {
                /** @var \Weline\Framework\Http\Request $request */
                $request = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
                $flag = (string)($request->getGet('wls_tpl_perf') ?? $request->getParam('wls_tpl_perf') ?? '');
                if ($flag === '1' || \strtolower($flag) === 'true') {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        $query = (string)WelineEnv::server('QUERY_STRING', '');
        if ($query === '') {
            $requestUri = (string)WelineEnv::server('REQUEST_URI', '');
            if ($requestUri === '' && \function_exists('w_env_request_uri')) {
                $requestUri = (string)\w_env_request_uri();
            }
            $parts = \parse_url($requestUri);
            $query = \is_array($parts) ? (string)($parts['query'] ?? '') : '';
        }
        if ($query === '') {
            return false;
        }
        \parse_str($query, $params);
        $flag = $params['wls_tpl_perf'] ?? null;
        if (\is_array($flag)) {
            return false;
        }
        $value = \strtolower((string)$flag);

        return $value === '1' || $value === 'true';
    }

    /**
     * @return array{db_duration_ms: float, db_span_count: int, wls_duration_ms: float, wls_span_count: int}
     */
    public static function snapshotIoCounters(): array
    {
        $state = self::state();

        return [
            'db_duration_ms' => \round($state->dbDurationMs, 2),
            'db_span_count' => $state->dbSpanCount,
            'wls_duration_ms' => \round($state->wlsDurationMs, 2),
            'wls_span_count' => $state->wlsSpanCount,
        ];
    }

    public static function armTemplatePerfOverlayIfRequested(): void
    {
        if (!self::isTemplatePerfOverlayRequested()) {
            return;
        }
        try {
            if (\class_exists(RequestContext::class, false) && RequestContext::isInitialized()) {
                RequestContext::set('view.template.overlay', true);
            }
        } catch (\Throwable) {
        }
        $state = self::state();
        $state->enabledCache = true;
    }

    public static function panelTraceCookieName(): string
    {
        return self::PANEL_TRACE_COOKIE;
    }

    public static function isPanelTraceArmed(): bool
    {
        return self::isValidPanelTraceCookie(self::readPanelTraceCookieValue());
    }

    /** Install panel-open cookie into the current process (tests / same-request). */
    public static function installPanelTraceOn(): void
    {
        $_COOKIE[self::PANEL_TRACE_COOKIE] = self::buildPanelTraceCookieValue();
    }

    public static function clearPanelTrace(): void
    {
        unset($_COOKIE[self::PANEL_TRACE_COOKIE]);
    }

    /**
     * @param object{setCookie?: callable} $response
     */
    public static function issuePanelTraceCookie(object $response, bool $enabled): void
    {
        if ($enabled) {
            $value = self::buildPanelTraceCookieValue();
            $_COOKIE[self::PANEL_TRACE_COOKIE] = $value;
            if (\method_exists($response, 'setCookie')) {
                $response->setCookie(
                    self::PANEL_TRACE_COOKIE,
                    $value,
                    0,
                    '/',
                    '',
                    self::isSecureRequest(),
                    true,
                    'Lax'
                );
            }

            return;
        }

        self::clearPanelTrace();
        if (\method_exists($response, 'setCookie')) {
            $response->setCookie(
                self::PANEL_TRACE_COOKIE,
                '',
                \time() - 3600,
                '/',
                '',
                self::isSecureRequest(),
                true,
                'Lax'
            );
        }
    }

    public static function buildPanelTraceCookieValue(): string
    {
        $signature = \hash_hmac('sha256', self::PANEL_TRACE_PAYLOAD, self::panelTraceSigningKey());

        return self::base64UrlEncode(self::PANEL_TRACE_PAYLOAD . '.' . $signature);
    }

    public static function isValidPanelTraceCookie(string $cookieValue): bool
    {
        if ($cookieValue === '') {
            return false;
        }

        $decoded = self::base64UrlDecode($cookieValue);
        if ($decoded === '') {
            return false;
        }

        $parts = \explode('.', $decoded, 2);
        if (\count($parts) !== 2) {
            return false;
        }

        [$payload, $signature] = $parts;
        if ($payload !== self::PANEL_TRACE_PAYLOAD || $signature === '') {
            return false;
        }

        $expected = \hash_hmac('sha256', self::PANEL_TRACE_PAYLOAD, self::panelTraceSigningKey());

        return \hash_equals($expected, $signature);
    }

    private static function readPanelTraceCookieValue(): string
    {
        if (\class_exists(\Weline\Framework\Http\Cookie::class, false)) {
            $fromHelper = \Weline\Framework\Http\Cookie::get(self::PANEL_TRACE_COOKIE, '');
            if (\is_scalar($fromHelper) && (string)$fromHelper !== '') {
                return (string)$fromHelper;
            }
        }

        $fromServer = '';
        if (\class_exists(WelineEnv::class, false)) {
            $fromServer = (string)WelineEnv::server('HTTP_COOKIE', '');
        }
        if ($fromServer === '' && isset($_SERVER['HTTP_COOKIE'])) {
            $fromServer = (string)$_SERVER['HTTP_COOKIE'];
        }
        if ($fromServer !== '') {
            foreach (\explode(';', $fromServer) as $pair) {
                $pair = \trim($pair);
                if ($pair === '' || !\str_contains($pair, '=')) {
                    continue;
                }
                [$name, $value] = \explode('=', $pair, 2);
                if (\trim($name) === self::PANEL_TRACE_COOKIE) {
                    return \rawurldecode(\trim($value));
                }
            }
        }

        return isset($_COOKIE[self::PANEL_TRACE_COOKIE])
            ? (string)$_COOKIE[self::PANEL_TRACE_COOKIE]
            : '';
    }

    private static function panelTraceSigningKey(): string
    {
        $salt = \defined('BP') ? (string)BP : __DIR__;

        return \hash('sha256', $salt . '|request_lifecycle_trace_panel');
    }

    public static function panelTplPerfCookieName(): string
    {
        return self::PANEL_TPL_PERF_COOKIE;
    }

    public static function isPanelTplPerfArmed(): bool
    {
        return self::isValidPanelTplPerfCookie(self::readPanelTplPerfCookieValue());
    }

    /** Install tpl-perf overlay cookie into the current process (tests / same-request). */
    public static function installPanelTplPerfOn(): void
    {
        $_COOKIE[self::PANEL_TPL_PERF_COOKIE] = self::buildPanelTplPerfCookieValue();
    }

    public static function clearPanelTplPerf(): void
    {
        unset($_COOKIE[self::PANEL_TPL_PERF_COOKIE]);
    }

    /**
     * @param object{setCookie?: callable} $response
     */
    public static function issuePanelTplPerfCookie(object $response, bool $enabled): void
    {
        if ($enabled) {
            $value = self::buildPanelTplPerfCookieValue();
            $_COOKIE[self::PANEL_TPL_PERF_COOKIE] = $value;
            if (\method_exists($response, 'setCookie')) {
                $response->setCookie(
                    self::PANEL_TPL_PERF_COOKIE,
                    $value,
                    0,
                    '/',
                    '',
                    self::isSecureRequest(),
                    true,
                    'Lax'
                );
            }

            return;
        }

        self::clearPanelTplPerf();
        if (\method_exists($response, 'setCookie')) {
            $response->setCookie(
                self::PANEL_TPL_PERF_COOKIE,
                '',
                \time() - 3600,
                '/',
                '',
                self::isSecureRequest(),
                true,
                'Lax'
            );
        }
    }

    public static function buildPanelTplPerfCookieValue(): string
    {
        $signature = \hash_hmac('sha256', self::PANEL_TPL_PERF_PAYLOAD, self::panelTplPerfSigningKey());

        return self::base64UrlEncode(self::PANEL_TPL_PERF_PAYLOAD . '.' . $signature);
    }

    public static function isValidPanelTplPerfCookie(string $cookieValue): bool
    {
        if ($cookieValue === '') {
            return false;
        }

        $decoded = self::base64UrlDecode($cookieValue);
        if ($decoded === '') {
            return false;
        }

        $parts = \explode('.', $decoded, 2);
        if (\count($parts) !== 2) {
            return false;
        }

        [$payload, $signature] = $parts;
        if ($payload !== self::PANEL_TPL_PERF_PAYLOAD || $signature === '') {
            return false;
        }

        $expected = \hash_hmac('sha256', self::PANEL_TPL_PERF_PAYLOAD, self::panelTplPerfSigningKey());

        return \hash_equals($expected, $signature);
    }

    private static function readPanelTplPerfCookieValue(): string
    {
        if (\class_exists(\Weline\Framework\Http\Cookie::class, false)) {
            $fromHelper = \Weline\Framework\Http\Cookie::get(self::PANEL_TPL_PERF_COOKIE, '');
            if (\is_scalar($fromHelper) && (string)$fromHelper !== '') {
                return (string)$fromHelper;
            }
        }

        $fromServer = '';
        if (\class_exists(WelineEnv::class, false)) {
            $fromServer = (string)WelineEnv::server('HTTP_COOKIE', '');
        }
        if ($fromServer === '' && isset($_SERVER['HTTP_COOKIE'])) {
            $fromServer = (string)$_SERVER['HTTP_COOKIE'];
        }
        if ($fromServer !== '') {
            foreach (\explode(';', $fromServer) as $pair) {
                $pair = \trim($pair);
                if ($pair === '' || !\str_contains($pair, '=')) {
                    continue;
                }
                [$name, $value] = \explode('=', $pair, 2);
                if (\trim($name) === self::PANEL_TPL_PERF_COOKIE) {
                    return \rawurldecode(\trim($value));
                }
            }
        }

        return isset($_COOKIE[self::PANEL_TPL_PERF_COOKIE])
            ? (string)$_COOKIE[self::PANEL_TPL_PERF_COOKIE]
            : '';
    }

    private static function panelTplPerfSigningKey(): string
    {
        $salt = \defined('BP') ? (string)BP : __DIR__;

        return \hash('sha256', $salt . '|request_lifecycle_tpl_perf_panel');
    }

    private static function isSecureRequest(): bool
    {
        if (!\class_exists(WelineEnv::class, false)) {
            return false;
        }
        $https = (string)WelineEnv::server('HTTPS', '');

        return $https !== '' && \strtolower($https) !== 'off';
    }

    private static function base64UrlEncode(string $value): string
    {
        return \rtrim(\strtr(\base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string
    {
        $value = \strtr($value, '-_', '+/');
        $padding = \strlen($value) % 4;
        if ($padding > 0) {
            $value .= \str_repeat('=', 4 - $padding);
        }
        $decoded = \base64_decode($value, true);

        return \is_string($decoded) ? $decoded : '';
    }

    /**
     * Explicit production opt-in only. DEBUG / developer panel injection must not
     * open control-plane-wide request tracing.
     */
    private static function isEnvRequestTraceEnabled(): bool
    {
        if (!\class_exists(\Weline\Framework\App\Env::class, false)) {
            return false;
        }

        return (bool)\Weline\Framework\App\Env::get('wls.debug.request_trace', false);
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
        // Keep scalar DB/WLS totals even when detailed spans have reached the cap.
        // Accumulate before rounding so many sub-centisecond calls are not lost.
        if ($category === 'db') {
            ++$state->dbSpanCount;
            $state->dbDurationMs += $durationMs;
        } elseif ($category === 'wls') {
            ++$state->wlsSpanCount;
            $state->wlsDurationMs += $durationMs;
        }
        if ($state->recordingDisabledUntilReset) {
            ++$state->droppedSpanCount;
            self::retainSlowOverflowSpan($name, $durationMs, $category, $parent, $meta, $state);
            return;
        }
        $maxSpans = self::getMaxSpansCap($state);
        if (\count($state->spans) >= $maxSpans) {
            if (!$state->maxSpansLogged) {
                $state->maxSpansLogged = true;
                \error_log('[RequestLifecycleTrace] span 已达上限 ' . (string) $maxSpans . '，顺序明细停止，慢样本与 DB/WLS 统计继续直至 reset');
            }
            $state->recordingDisabledUntilReset = true;
            ++$state->droppedSpanCount;
            self::retainSlowOverflowSpan($name, $durationMs, $category, $parent, $meta, $state);

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

    /** Keep late enclosing spans visible without expanding the chronological trace or its dictionaries. */
    private static function retainSlowOverflowSpan(
        string $name,
        float $durationMs,
        string $category,
        ?string $parent,
        array $meta,
        RequestLifecycleTraceState $state
    ): void
    {
        // Reject fast calls before resolving parents or allocating/sanitizing metadata.
        if ($durationMs < 10.0) {
            return;
        }
        $limit = 40;
        $count = \count($state->slowOverflowSpans);
        if ($count >= $limit
            && $durationMs <= $state->slowOverflowSpans[0]['duration_ms']) {
            return;
        }
        $resolvedParent = $parent;
        if ($resolvedParent === null || $resolvedParent === '') {
            $resolvedParent = self::getCurrentParent();
        }
        $span = [
            'name' => $name,
            'duration_ms' => \round($durationMs, 2),
            'category' => $category,
            'sampled_after_cap' => true,
        ];
        if ($resolvedParent !== null && $resolvedParent !== '') {
            $span['parent'] = $resolvedParent;
        }
        if (!empty($meta)) {
            $span['meta'] = self::sanitizeMetaForStorage($meta, $state);
        }
        if ($count >= $limit) {
            \array_shift($state->slowOverflowSpans);
        }
        $state->slowOverflowSpans[] = $span;
        \usort($state->slowOverflowSpans, static fn(array $a, array $b): int =>
            $a['duration_ms'] <=> $b['duration_ms']);
    }

    /**
     * Retain a completed phase independently of the detailed-span cap.
     * Existing names may be updated after the fixed phase-name limit is reached.
     *
     * @param array<string, mixed> $meta
     */
    public static function recordPhase(string $name, float $durationMs, array $meta = []): void
    {
        if (!self::isEnabled()) {
            return;
        }

        $state = self::state();
        if (isset($state->phases[$name]) || \count($state->phases) < self::MAX_SUMMARY_PHASES) {
            $phase = ['duration_ms' => \round($durationMs, 2)];
            if ($meta !== []) {
                $phase['meta'] = self::sanitizeMetaForStorage($meta, $state);
            }
            $state->phases[$name] = $phase;
        }

        self::recordSpan($name, $durationMs, 'phase', null, $meta);
    }

    /**
     * Measure a callback with bounded, request-local aggregates even after the detail cap.
     * Durations include children and Fiber suspension; overlapping phases must not be summed.
     * Use stable phase names, never product IDs or raw request values.
     *
     * @template T
     * @param callable(): T $callback
     * @param array<string, mixed> $meta
     * @return T
     */
    public static function measurePhase(string $name, callable $callback, array $meta = []): mixed
    {
        if (!self::isEnabled()) {
            return $callback();
        }

        $state = self::state();
        $dbCount = $state->dbSpanCount;
        $dbMs = $state->dbDurationMs;
        $wlsCount = $state->wlsSpanCount;
        $wlsMs = $state->wlsDurationMs;
        $startedAt = \hrtime(true);
        $failed = false;
        try {
            return $callback();
        } catch (\Throwable $error) {
            $failed = true;
            throw $error;
        } finally {
            $durationMs = \round((\hrtime(true) - $startedAt) / 1e6, 2);
            if (isset($state->phases[$name]) || \count($state->phases) < self::MAX_SUMMARY_PHASES) {
                $previous = $state->phases[$name] ?? [];
                $phase = [
                    'duration_ms' => \round((float)($previous['duration_ms'] ?? 0.0) + $durationMs, 2),
                    'calls' => (int)($previous['calls'] ?? 0) + 1,
                    'max_ms' => \max((float)($previous['max_ms'] ?? 0.0), $durationMs),
                    'errors' => (int)($previous['errors'] ?? 0) + (int)$failed,
                    'db_span_count' => (int)($previous['db_span_count'] ?? 0) + $state->dbSpanCount - $dbCount,
                    'db_duration_ms' => \round((float)($previous['db_duration_ms'] ?? 0.0) + $state->dbDurationMs - $dbMs, 2),
                    'wls_span_count' => (int)($previous['wls_span_count'] ?? 0) + $state->wlsSpanCount - $wlsCount,
                    'wls_duration_ms' => \round((float)($previous['wls_duration_ms'] ?? 0.0) + $state->wlsDurationMs - $wlsMs, 2),
                    'measurement' => 'inclusive',
                ];
                if ($meta !== []) {
                    $phase['meta'] = self::sanitizeMetaForStorage($meta, $state);
                }
                $state->phases[$name] = $phase;
            }
            // Detail rows contain this invocation only, never the cumulative phase total.
            self::recordSpan($name, $durationMs, 'phase', null, $meta);
        }
    }

    /**
     * Remove authentication persistence values before SQL reaches any trace or log sink.
     */
    public static function redactDatabaseSql(string $sql): string
    {
        return self::containsAuthenticationPersistence($sql)
            ? self::REDACTED_AUTH_SQL
            : $sql;
    }

    public static function containsAuthenticationPersistence(string $sql): bool
    {
        return \preg_match('/\b(?:session_digest|token_digest)\b/i', $sql) === 1;
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
        // Compact rows and category maps keep the original chronological detail contract.
        $spans = $state->spans;
        if (empty($state->compactRows) && !empty($spans)) {
            foreach ($spans as $span) {
                self::appendCompactSpan($span, $state);
            }
        }

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
            'summary' => self::getAggregateSummary() + [
                'request_id' => self::ensureRequestId(),
                'total_ms' => \round($totalMs, 2),
                // Category maps describe retained details; DB scalar totals also include dropped details.
                'category_counts' => $categoryCounts,
                'category_totals' => self::roundFloatMap($categoryTotals),
            ],
        ];
    }

    /**
     * DB/WLS totals cover all enabled span calls in those categories, independently of the detail cap.
     * span_count remains the number of retained details for compact trace consumers.
     * These are instrumented DB spans, not a count of SQL statements or server waits.
     *
     * @return array{span_count: int, dropped_span_count: int, db_span_count: int, db_duration_ms: float, wls_span_count: int, wls_duration_ms: float, truncated: bool, max_spans: int, phases: array<string, array<string, mixed>>}
     */
    public static function getAggregateSummary(): array
    {
        $state = self::state();

        return [
            'span_count' => \count($state->spans),
            'dropped_span_count' => $state->droppedSpanCount,
            'db_span_count' => $state->dbSpanCount,
            'db_duration_ms' => \round($state->dbDurationMs, 2),
            'wls_span_count' => $state->wlsSpanCount,
            'wls_duration_ms' => \round($state->wlsDurationMs, 2),
            'truncated' => $state->recordingDisabledUntilReset,
            'max_spans' => self::getMaxSpansCap($state),
            'slow_overflow_span_count' => \count($state->slowOverflowSpans),
            'slow_overflow_span_limit' => 40,
            'phases' => $state->phases,
            'template_render_files' => RequestContext::isInitialized()
                ? (RequestContext::get('view.template.aggregate') ?? [])
                : [],
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
        $suffix = '...(truncated)';
        $out = [];
        foreach ($meta as $key => $value) {
            if ($key === 'sql'
                && \is_string($value)
                && self::containsAuthenticationPersistence($value)) {
                $out[$key] = self::redactDatabaseSql($value);
                continue;
            }
            if ($max > 0 && \is_string($value) && \strlen($value) > $max) {
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
        $state = self::state();
        $spans = $state->spans;

        if (empty($spans)) {
            return \array_merge($spans, $state->slowOverflowSpans);
        }

        // 1. Only chronological details contribute to partial parent DB summaries; samples are not complete children.
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
            return \array_merge($spans, $state->slowOverflowSpans);
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

        return \array_merge($spans, $state->slowOverflowSpans);
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
