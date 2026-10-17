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
     * Call once after RequestContext is initialized (and after panel cookie install).
     * Closed requests: mark disarmed without loading RequestLifecycleTrace.
     */
    public static function bootstrapFromRequest(): void
    {
        if (self::isControlPlane()) {
            self::markArmed(false);

            return;
        }

        if (!PanelTraceCookie::isPanelArmed() && !PanelTraceCookie::isTplPerfArmed()) {
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
    }

    /** Fiber/request reset boundary */
    public static function reset(): void
    {
        self::clearArmedSlot();
        self::clearRecorderFromContext();
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
        // Closed: cookie peek only — do not load the recorder.
        if (!PanelTraceCookie::isTplPerfArmed() && !PanelTraceCookie::isPanelArmed()) {
            return;
        }
        self::loadAndEnableRecorder();
        RequestLifecycleTrace::armTemplatePerfOverlayIfRequested();
    }

    public static function isTemplatePerfOverlayRequested(): bool
    {
        if (PanelTraceCookie::isTplPerfArmed()) {
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
        if (!\class_exists(RequestLifecycleTrace::class, false)) {
            if (self::getArmed() !== true) {
                self::clearArmedSlot();
            }

            return;
        }
        RequestLifecycleTrace::invalidateIfDisarmed();
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
        RequestLifecycleTrace::issuePanelTraceCookie($response, $enabled);
        if ($enabled) {
            self::armFromPanel();
        } else {
            self::disarm();
        }
    }

    public static function issuePanelTplPerfCookie(object $response, bool $enabled): void
    {
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

            return self::$fiberArmed[$fiber] ?? null;
        }
        $context = Context::getCurrent();
        if ($context !== null) {
            self::$contextArmed ??= new \WeakMap();

            return self::$contextArmed[$context] ?? null;
        }

        return self::$mainArmed;
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
