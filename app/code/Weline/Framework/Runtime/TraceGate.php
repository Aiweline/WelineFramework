<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;

/**
 * Zero-cost request-trace gate.
 *
 * Call sites MUST use this facade (not {@see RequestLifecycleTrace}) so that when
 * the panel is closed the heavy recorder class is never autoloaded and meta/timing
 * payloads built only behind {@see isArmed()} never allocate.
 *
 * Bootstrap ({@see bootstrapFromRequest}) peeks panel cookies via {@see PanelTraceCookie}
 * and, only when armed, loads RequestLifecycleTrace into the request context.
 */
final class TraceGate
{
    public const CONTEXT_RECORDER_KEY = 'runtime.request_lifecycle_trace.recorder';

    /** @var \WeakMap<\Fiber, bool>|null */
    private static ?\WeakMap $fiberArmed = null;

    /** @var \WeakMap<Context, bool>|null */
    private static ?\WeakMap $contextArmed = null;

    private static ?bool $mainArmed = null;

    /**
     * Per-scope latch for the two panel cookies.
     *
     * Peeking them is *not* cheap: `PanelTraceCookie::read()` resolves the
     * CookieScope-qualified name, scans `$_COOKIE`, and may parse the whole
     * `HTTP_COOKIE` header — measured 8.5–9.7 µs per peek on this machine. Template
     * rendering used to peek three times per include (overlay arm + two annotate
     * checks), so ~27 µs per include went into re-answering a question whose answer
     * cannot change unless *we* change it: cookies are only issued or toggled through
     * {@see issuePanelTplPerfCookie()} / {@see issuePanelTraceCookie()} /
     * {@see armFromPanel()} / {@see invalidateIfDisarmed()}, and every one of those
     * clears this latch.
     *
     * @var \WeakMap<\Fiber, array{tpl_perf: bool, panel: bool}>|null
     */
    private static ?\WeakMap $fiberCookiePeek = null;

    /** @var \WeakMap<Context, array{tpl_perf: bool, panel: bool}>|null */
    private static ?\WeakMap $contextCookiePeek = null;

    /** @var array{tpl_perf: bool, panel: bool}|null */
    private static ?array $mainCookiePeek = null;

    /**
     * Call once after RequestContext is initialized (and after panel cookie install).
     * Closed requests: mark disarmed without loading RequestLifecycleTrace.
     */
    public static function bootstrapFromRequest(): void
    {
        // Re-read the cookies: callers use this as the "resolve again now" entry point
        // after installing/removing a panel cookie (see RequestLifecycleTrace::
        // installPanelTraceOn / clearPanelTrace), so a latched peek would be stale.
        self::clearCookiePeek();
        if (self::isControlPlane()) {
            self::markArmed(false);

            return;
        }

        $peek = self::cookiePeek();
        if (!$peek['panel'] && !$peek['tpl_perf']) {
            self::markArmed(false);
            self::clearRecorderFromContext();

            return;
        }

        self::loadAndEnableRecorder();
    }

    public static function isArmed(): bool
    {
        return self::getArmed() === true;
    }

    /** @deprecated Prefer {@see isArmed()}; kept for call-site migration compatibility */
    public static function isEnabled(): bool
    {
        return self::isArmed();
    }

    public static function isHotArmed(): bool
    {
        return self::isArmed();
    }

    /**
     * Same-request arm after panel cookie issue (loads recorder if needed).
     */
    public static function armFromPanel(): void
    {
        self::clearCookiePeek();
        if (self::isControlPlane()) {
            self::markArmed(false);

            return;
        }
        self::loadAndEnableRecorder();
    }

    public static function disarm(): void
    {
        self::markArmed(false);
        self::clearRecorderFromContext();
        self::clearCookiePeek();
    }

    /** Fiber/request reset boundary */
    public static function reset(): void
    {
        self::clearArmedSlot();
        self::clearRecorderFromContext();
        self::clearCookiePeek();
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @param array<string, mixed>|callable(): array<string, mixed> $meta
     * @return T
     */
    public static function measurePhase(string $name, callable $callback, array|callable $meta = []): mixed
    {
        if (!self::isArmed()) {
            return $callback();
        }
        $resolved = self::resolveMeta($meta);

        return RequestLifecycleTrace::measurePhase($name, $callback, $resolved);
    }

    /**
     * @param array<string, mixed>|callable(): array<string, mixed> $meta
     */
    public static function recordSpan(
        string $name,
        float $durationMs,
        string $category = 'framework',
        ?string $parent = null,
        array|callable $meta = []
    ): void {
        if (!self::isArmed()) {
            return;
        }
        RequestLifecycleTrace::recordSpan($name, $durationMs, $category, $parent, self::resolveMeta($meta));
    }

    /**
     * @param array<string, mixed>|callable(): array<string, mixed> $meta
     */
    public static function recordPhase(string $name, float $durationMs, array|callable $meta = []): void
    {
        if (!self::isArmed()) {
            return;
        }
        RequestLifecycleTrace::recordPhase($name, $durationMs, self::resolveMeta($meta));
    }

    /** @param list<string> $names */
    public static function reserveSummaryPhases(array $names): void
    {
        if (!self::isArmed() || $names === []) {
            return;
        }
        RequestLifecycleTrace::reserveSummaryPhases($names);
    }

    public static function pushCurrentParent(string $observerSpanName): void
    {
        if (!self::isArmed()) {
            return;
        }
        RequestLifecycleTrace::pushCurrentParent($observerSpanName);
    }

    public static function popCurrentParent(): void
    {
        if (!self::isArmed()) {
            return;
        }
        RequestLifecycleTrace::popCurrentParent();
    }

    public static function startSpan(string $name): void
    {
        if (!self::isArmed()) {
            return;
        }
        RequestLifecycleTrace::startSpan($name);
    }

    public static function endSpan(string $name, string $category = 'framework'): void
    {
        if (!self::isArmed()) {
            return;
        }
        RequestLifecycleTrace::endSpan($name, $category);
    }

    private const REDACTED_AUTH_SQL = '[REDACTED: authentication persistence statement]';

    /**
     * SQL safety helpers live on the gate so DB/logging paths never autoload
     * {@see RequestLifecycleTrace} when the panel is closed.
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

    /** @return array{db_duration_ms: float, db_span_count: int, wls_duration_ms: float, wls_span_count: int} */
    public static function snapshotIoCounters(): array
    {
        if (!self::isArmed()) {
            return [
                'db_duration_ms' => 0.0,
                'db_span_count' => 0,
                'wls_duration_ms' => 0.0,
                'wls_span_count' => 0,
            ];
        }

        return RequestLifecycleTrace::snapshotIoCounters();
    }

    public static function getCurrentParent(): ?string
    {
        if (!self::isArmed()) {
            return null;
        }

        return RequestLifecycleTrace::getCurrentParent();
    }

    public static function armTemplatePerfOverlayIfRequested(): void
    {
        // Closed: cookie peek only — do not load the recorder. Latched per scope, so
        // this is a WeakMap read on the hot path instead of two HMAC cookie parses.
        $peek = self::cookiePeek();
        if (!$peek['tpl_perf'] && !$peek['panel']) {
            return;
        }
        self::loadAndEnableRecorder();
        RequestLifecycleTrace::armTemplatePerfOverlayIfRequested();
    }

    public static function isTemplatePerfOverlayRequested(): bool
    {
        if (self::cookiePeek()['tpl_perf']) {
            return true;
        }
        if (!self::isArmed() && !\class_exists(RequestLifecycleTrace::class, false)) {
            return false;
        }

        return RequestLifecycleTrace::isTemplatePerfOverlayRequested();
    }

    public static function shouldSkipForCurrentRequest(): bool
    {
        if (!self::isArmed() && !\class_exists(RequestLifecycleTrace::class, false)) {
            return false;
        }

        return RequestLifecycleTrace::shouldSkipForCurrentRequest();
    }

    public static function ensureRequestId(): string
    {
        if (self::isArmed() || \class_exists(RequestLifecycleTrace::class, false)) {
            return RequestLifecycleTrace::ensureRequestId();
        }
        try {
            if (\class_exists(RequestContext::class, false) && RequestContext::isInitialized()) {
                $id = (string)(RequestContext::getRequestId() ?? '');
                if ($id !== '' && \preg_match('/^[a-zA-Z0-9_.:-]{8,128}$/', $id)) {
                    return $id;
                }
            }
        } catch (\Throwable) {
        }

        return 'req_' . \bin2hex(\random_bytes(8));
    }

    public static function invalidateIfDisarmed(): void
    {
        // CookieScope may have just gained a worker suffix, so the latched peek is
        // no longer trustworthy: drop it before anything re-reads the cookies.
        self::clearCookiePeek();
        if (!\class_exists(RequestLifecycleTrace::class, false)) {
            if (self::getArmed() !== true) {
                self::clearArmedSlot();
                // CookieScope may have just gained a worker suffix; re-peek
                // w_weline_trace_panel_w0 without keeping a sticky false latch.
                self::bootstrapFromRequest();
            }

            return;
        }
        RequestLifecycleTrace::invalidateIfDisarmed();
        if (self::getArmed() !== true) {
            self::bootstrapFromRequest();
        }
    }

    /** @return list<array<string, mixed>> */
    public static function getSpansWithDbSummary(): array
    {
        if (!self::isArmed() && !\class_exists(RequestLifecycleTrace::class, false)) {
            return [];
        }

        return RequestLifecycleTrace::getSpansWithDbSummary();
    }

    /** @return array<string, mixed> */
    public static function getAggregateSummary(): array
    {
        if (!self::isArmed() && !\class_exists(RequestLifecycleTrace::class, false)) {
            return [
                'span_count' => 0,
                'dropped_span_count' => 0,
                'db_span_count' => 0,
                'db_duration_ms' => 0.0,
                'wls_span_count' => 0,
                'wls_duration_ms' => 0.0,
                'truncated' => false,
                'max_spans' => 0,
                'phases' => [],
            ];
        }

        return RequestLifecycleTrace::getAggregateSummary();
    }

    public static function sumDurationsByName(string $name): float
    {
        if (!self::isArmed() && !\class_exists(RequestLifecycleTrace::class, false)) {
            return 0.0;
        }

        return RequestLifecycleTrace::sumDurationsByName($name);
    }

    /** @return array<string, mixed> */
    public static function exportCompactPayload(): array
    {
        if (!self::isArmed() && !\class_exists(RequestLifecycleTrace::class, false)) {
            return ['summary' => self::getAggregateSummary(), 'spans' => [], 'dict' => []];
        }

        return RequestLifecycleTrace::exportCompactPayload();
    }

    public static function resetRecorder(): void
    {
        if (\class_exists(RequestLifecycleTrace::class, false)) {
            RequestLifecycleTrace::reset();
        }
        self::reset();
    }

    public static function issuePanelTraceCookie(object $response, bool $enabled): void
    {
        self::clearCookiePeek();
        RequestLifecycleTrace::issuePanelTraceCookie($response, $enabled);
        if ($enabled) {
            self::armFromPanel();
        } else {
            self::disarm();
        }
    }

    public static function issuePanelTplPerfCookie(object $response, bool $enabled): void
    {
        // The Set-Cookie lands after this call, so the latched peek is stale either
        // way; drop it before re-bootstrapping.
        self::clearCookiePeek();
        RequestLifecycleTrace::issuePanelTplPerfCookie($response, $enabled);
        if ($enabled) {
            self::armFromPanel();
        } else {
            self::bootstrapFromRequest();
        }
    }

    public static function panelTraceCookieName(): string
    {
        return PanelTraceCookie::panelCookieName();
    }

    public static function panelTplPerfCookieName(): string
    {
        return PanelTraceCookie::tplPerfCookieName();
    }

    /** Sync from RequestLifecycleTrace latch (Trace already loaded). */
    public static function markArmed(bool $armed): void
    {
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber !== null) {
            self::$fiberArmed ??= new \WeakMap();
            self::$fiberArmed[$fiber] = $armed;

            return;
        }
        $context = Context::getCurrent();
        if ($context !== null) {
            self::$contextArmed ??= new \WeakMap();
            self::$contextArmed[$context] = $armed;

            return;
        }
        self::$mainArmed = $armed;
    }

    private static function getArmed(): ?bool
    {
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber !== null) {
            self::$fiberArmed ??= new \WeakMap();
            $armed = self::$fiberArmed[$fiber] ?? null;
        } else {
            $context = Context::getCurrent();
            if ($context !== null) {
                self::$contextArmed ??= new \WeakMap();
                $armed = self::$contextArmed[$context] ?? null;
            } else {
                $armed = self::$mainArmed;
            }
        }

        if ($armed !== null) {
            return $armed;
        }

        // Empty mirror: fall back to the recorder's own cached latch. Guarded by a
        // no-autoload class_exists so a disarmed request still never loads the
        // recorder; the mirror is normally filled by bootstrap/latchArmed.
        if (!\class_exists(RequestLifecycleTrace::class, false)) {
            return null;
        }

        return RequestLifecycleTrace::peekEnabledCache();
    }

    /**
     * Panel-cookie decision for the current Fiber/Context, computed at most once.
     *
     * @return array{tpl_perf: bool, panel: bool}
     */
    private static function cookiePeek(): array
    {
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        $context = $fiber === null ? Context::getCurrent() : null;

        if ($fiber !== null) {
            self::$fiberCookiePeek ??= new \WeakMap();
            $peek = self::$fiberCookiePeek[$fiber] ?? null;
        } elseif ($context !== null) {
            self::$contextCookiePeek ??= new \WeakMap();
            $peek = self::$contextCookiePeek[$context] ?? null;
        } else {
            $peek = self::$mainCookiePeek;
        }

        if ($peek !== null) {
            return $peek;
        }

        $peek = [
            'tpl_perf' => PanelTraceCookie::isTplPerfArmed(),
            'panel' => PanelTraceCookie::isPanelArmed(),
        ];

        if ($fiber !== null) {
            self::$fiberCookiePeek[$fiber] = $peek;
        } elseif ($context !== null) {
            self::$contextCookiePeek[$context] = $peek;
        } else {
            self::$mainCookiePeek = $peek;
        }

        return $peek;
    }

    private static function clearCookiePeek(): void
    {
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber !== null) {
            if (self::$fiberCookiePeek !== null && isset(self::$fiberCookiePeek[$fiber])) {
                unset(self::$fiberCookiePeek[$fiber]);
            }

            return;
        }
        $context = Context::getCurrent();
        if ($context !== null) {
            if (self::$contextCookiePeek !== null && isset(self::$contextCookiePeek[$context])) {
                unset(self::$contextCookiePeek[$context]);
            }

            return;
        }
        self::$mainCookiePeek = null;
    }

    private static function clearArmedSlot(): void
    {
        $fiber = \class_exists(\Fiber::class) ? \Fiber::getCurrent() : null;
        if ($fiber !== null) {
            if (self::$fiberArmed !== null && isset(self::$fiberArmed[$fiber])) {
                unset(self::$fiberArmed[$fiber]);
            }

            return;
        }
        $context = Context::getCurrent();
        if ($context !== null) {
            if (self::$contextArmed !== null && isset(self::$contextArmed[$context])) {
                unset(self::$contextArmed[$context]);
            }

            return;
        }
        self::$mainArmed = null;
    }

    private static function loadAndEnableRecorder(): void
    {
        // Autoload happens here only.
        RequestLifecycleTrace::forceEnableCache();
        self::markArmed(true);
        try {
            if (\class_exists(RequestContext::class, false) && RequestContext::isInitialized()) {
                RequestContext::set(self::CONTEXT_RECORDER_KEY, RequestLifecycleTrace::class);
            }
        } catch (\Throwable) {
        }
    }

    private static function clearRecorderFromContext(): void
    {
        try {
            if (\class_exists(RequestContext::class, false) && RequestContext::isInitialized()) {
                RequestContext::set(self::CONTEXT_RECORDER_KEY, null);
            }
        } catch (\Throwable) {
        }
    }

    private static function isControlPlane(): bool
    {
        $hasActiveRequestContext = \class_exists(RequestContext::class, false)
            && RequestContext::isInitialized();

        return \class_exists(Runtime::class, false)
            && Runtime::isPersistent()
            && !$hasActiveRequestContext;
    }

    /**
     * @param array<string, mixed>|callable(): array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private static function resolveMeta(array|callable $meta): array
    {
        if (\is_array($meta)) {
            return $meta;
        }
        $resolved = $meta();

        return \is_array($resolved) ? $resolved : [];
    }
}
