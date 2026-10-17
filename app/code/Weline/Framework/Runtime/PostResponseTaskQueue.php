<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App\Env;
use Weline\Framework\Context;

final class PostResponseTaskQueue
{
    /**
     * @var array<string, array{
     *   task: callable,
     *   context: ?WlsFiberContext,
     *   scope: ?array,
     *   mode: PostResponseCaptureMode,
     *   not_before: float,
     *   shared_request_id: ?string
     * }>
     */
    private static array $tasks = [];
    private static bool $draining = false;

    /** @var array<string, array{context: WlsFiberContext, refs: int}> */
    private static array $sharedFullByRequestId = [];

    public static function enqueue(
        string $key,
        callable $task,
        float $notBefore = 0.0,
        PostResponseCaptureMode $mode = PostResponseCaptureMode::Full,
    ): void {
        if ($key === '' || isset(self::$tasks[$key])) {
            return;
        }

        $context = null;
        $scope = null;
        $sharedRequestId = null;
        $sharedHit = false;
        $memoryBeforeCapture = \memory_get_usage(true);

        if (Runtime::isPersistent()) {
            try {
                if ($mode === PostResponseCaptureMode::None) {
                    // Closure must carry all dependencies.
                } elseif ($mode === PostResponseCaptureMode::Scope) {
                    $scope = self::captureScopeSnapshot();
                } else {
                    $requestId = RequestContext::getId();
                    if (\is_string($requestId) && $requestId !== '') {
                        $sharedRequestId = $requestId;
                        if (isset(self::$sharedFullByRequestId[$requestId])) {
                            $context = self::$sharedFullByRequestId[$requestId]['context'];
                            self::$sharedFullByRequestId[$requestId]['refs']++;
                            $sharedHit = true;
                        } else {
                            $captured = WlsFiberContext::captureForPostResponse();
                            self::$sharedFullByRequestId[$requestId] = [
                                'context' => $captured,
                                'refs' => 1,
                            ];
                            $context = $captured;
                        }
                    } else {
                        $context = WlsFiberContext::captureForPostResponse();
                    }
                }
            } catch (\Throwable) {
                $context = null;
                $scope = null;
                $sharedRequestId = null;
                $sharedHit = false;
            }
        }

        self::$tasks[$key] = [
            'task' => $task,
            'context' => $context,
            'scope' => $scope,
            'mode' => $mode,
            'not_before' => max(0.0, $notBefore),
            'shared_request_id' => $sharedRequestId,
        ];
        if ((string)\getenv('WELINE_DIAG_MEMORY') === '1') {
            \error_log('[MemoryProbe] ' . \json_encode([
                'component' => 'PostResponseTaskQueue',
                'phase' => 'enqueue',
                'key' => \substr($key, 0, 120),
                'pending' => \count(self::$tasks),
                'mode' => $mode->value,
                'shared_hit' => $sharedHit,
                'context_captured' => $context instanceof WlsFiberContext,
                'scope_captured' => \is_array($scope),
                'capture_delta' => \memory_get_usage(true) - $memoryBeforeCapture,
                'usage' => \memory_get_usage(true),
                'peak' => \memory_get_peak_usage(true),
            ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
        }
    }

    public static function drain(float $budgetMs = 8.0, ?int $maxTasks = null): int
    {
        if (self::$tasks === [] || self::$draining) {
            return 0;
        }

        self::$draining = true;
        try {
            self::memoryProbe('drain_start', null, null);
            $startedAt = \microtime(true);
            $slowTaskMs = (float)(Env::get('wls.post_response_task_slow_ms', \max(25.0, $budgetMs)) ?: \max(25.0, $budgetMs));
            $taskLimit = $maxTasks === null ? PHP_INT_MAX : \max(1, $maxTasks);
            $processed = 0;
            foreach (\array_keys(self::$tasks) as $key) {
                $task = self::$tasks[$key] ?? null;
                if (\is_array($task) && (float)($task['not_before'] ?? 0.0) > \microtime(true)) {
                    continue;
                }
                unset(self::$tasks[$key]);
                if (!\is_array($task) || !\is_callable($task['task'] ?? null)) {
                    self::releaseSharedFull($task['shared_request_id'] ?? null);
                    continue;
                }

                try {
                    self::memoryProbe('task_start', $key, \count(self::$tasks));
                    $mode = $task['mode'] ?? PostResponseCaptureMode::Full;
                    if (!$mode instanceof PostResponseCaptureMode) {
                        $mode = PostResponseCaptureMode::Full;
                    }
                    $context = $task['context'] ?? null;
                    if ($mode === PostResponseCaptureMode::Full && $context instanceof WlsFiberContext) {
                        $context->restore(false);
                    } elseif ($mode === PostResponseCaptureMode::Scope && \is_array($task['scope'] ?? null)) {
                        self::restoreScopeSnapshot($task['scope']);
                    }
                    $taskStartedAt = \microtime(true);
                    ($task['task'])();
                    $taskElapsedMs = (\microtime(true) - $taskStartedAt) * 1000;
                    self::memoryProbe('task_after_callable', $key, \count(self::$tasks));
                    if ($slowTaskMs > 0 && $taskElapsedMs >= $slowTaskMs) {
                        Env::log_warning(
                            'runtime/post_response_task',
                            'Slow post-response task: key=' . self::formatTaskKey($key)
                            . ' elapsed_ms=' . \round($taskElapsedMs, 2)
                        );
                    }
                } catch (\Throwable $throwable) {
                    Env::log_error('runtime/post_response_task', $throwable->getMessage());
                } finally {
                    $processed++;
                    self::releaseSharedFull($task['shared_request_id'] ?? null);
                    self::cleanupRestoredContext();
                    self::memoryProbe('task_after_cleanup', $key, \count(self::$tasks));
                }

                if ($processed >= $taskLimit) {
                    break;
                }

                if ($budgetMs > 0 && ((\microtime(true) - $startedAt) * 1000) >= $budgetMs) {
                    break;
                }
            }

            self::memoryProbe('drain_end', null, \count(self::$tasks));
            return $processed;
        } finally {
            self::$draining = false;
        }
    }

    private static function memoryProbe(string $phase, ?string $key, ?int $pending): void
    {
        if ((string)\getenv('WELINE_DIAG_MEMORY') !== '1') {
            return;
        }

        \error_log('[MemoryProbe] ' . \json_encode([
            'component' => 'PostResponseTaskQueue',
            'phase' => $phase,
            'key' => $key === null ? null : \substr($key, 0, 120),
            'pending' => $pending,
            'usage' => \memory_get_usage(true),
            'peak' => \memory_get_peak_usage(true),
        ], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    public static function pendingCount(): int
    {
        return \count(self::$tasks);
    }

    /**
     * 只读汇总到期状态，不暴露任务键、回调或请求上下文。
     *
     * next_due_ms：空队列为 null；已有到期任务为 0；否则为最近到期的剩余毫秒。
     *
     * @return array{
     *   pending:int,
     *   ready:int,
     *   delayed:int,
     *   next_due_ms:?float,
     *   draining:bool,
     *   capture_full:int,
     *   capture_scope:int,
     *   capture_none:int
     * }
     */
    public static function getRuntimeDiagnostics(): array
    {
        $now = \microtime(true);
        $ready = 0;
        $delayed = 0;
        $nextDue = null;
        $captureFull = 0;
        $captureScope = 0;
        $captureNone = 0;
        foreach (self::$tasks as $task) {
            $notBefore = (float)($task['not_before'] ?? 0.0);
            if ($notBefore > $now) {
                $delayed++;
            } else {
                $ready++;
            }
            $nextDue = $nextDue === null ? $notBefore : \min($nextDue, $notBefore);
            $mode = $task['mode'] ?? PostResponseCaptureMode::Full;
            if ($mode === PostResponseCaptureMode::None) {
                $captureNone++;
            } elseif ($mode === PostResponseCaptureMode::Scope) {
                $captureScope++;
            } else {
                $captureFull++;
            }
        }

        return [
            'pending' => \count(self::$tasks),
            'ready' => $ready,
            'delayed' => $delayed,
            'next_due_ms' => $nextDue === null ? null : \round(\max(0.0, ($nextDue - $now) * 1000), 3),
            'draining' => self::$draining,
            'capture_full' => $captureFull,
            'capture_scope' => $captureScope,
            'capture_none' => $captureNone,
        ];
    }

    /**
     * Report whether the queue is currently executing post-response work.
     *
     * Cache writers use this to execute a refresh write immediately while a
     * deferred task is draining, instead of enqueueing the same write again.
     */
    public static function isDraining(): bool
    {
        return self::$draining;
    }

    /** @internal test/diagnostics */
    public static function sharedFullSnapshotCount(): int
    {
        return \count(self::$sharedFullByRequestId);
    }

    /** @internal test helper — inspect pending task bag without exposing production API surface. */
    public static function debugPeekTask(string $key): ?array
    {
        return self::$tasks[$key] ?? null;
    }

    private static function formatTaskKey(string $key): string
    {
        if (\strlen($key) <= 160) {
            return $key;
        }

        return \substr($key, 0, 157) . '...';
    }

    /**
     * @return array{
     *   envelope: array<string, mixed>,
     *   language: string,
     *   currency: string,
     *   area: string,
     *   host: string,
     *   scheme: string,
     *   website_url: string
     * }
     */
    private static function captureScopeSnapshot(): array
    {
        $envelope = ScopeEnvelope::capture()->toArray();
        $language = RequestContext::getWelineUserLang();
        $currency = RequestContext::getWelineUserCurrency();
        $area = RequestContext::getWelineArea();
        $websiteUrl = RequestContext::getWelineWebsiteUrl();
        $host = '';
        $scheme = '';
        try {
            $context = Context::getCurrent();
            if ($context !== null) {
                $host = \trim((string)$context->get('input.host', ''));
                $scheme = \trim((string)$context->get('input.scheme', ''));
            }
        } catch (\Throwable) {
        }
        if ($host === '') {
            $host = \trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        }
        if ($scheme === '') {
            $scheme = \trim((string)($_SERVER['REQUEST_SCHEME'] ?? 'http'));
        }
        if ($scheme === '') {
            $scheme = 'http';
        }

        return [
            'envelope' => $envelope,
            'language' => $language,
            'currency' => $currency !== '' ? $currency : 'CNY',
            'area' => $area !== '' ? $area : RequestContext::AREA_FRONTEND,
            'host' => $host,
            'scheme' => $scheme,
            'website_url' => $websiteUrl,
        ];
    }

    /**
     * @param array{
     *   envelope?: array<string, mixed>,
     *   language?: string,
     *   currency?: string,
     *   area?: string,
     *   host?: string,
     *   scheme?: string,
     *   website_url?: string
     * } $snapshot
     */
    private static function restoreScopeSnapshot(array $snapshot): void
    {
        $envelopeData = $snapshot['envelope'] ?? null;
        if (!\is_array($envelopeData)) {
            return;
        }

        try {
            $envelope = ScopeEnvelope::fromArray($envelopeData);
            RequestContext::installScopeIdentity($envelope->scope);
        } catch (\Throwable) {
            // Scope restore is best-effort for post-response writers.
        }

        $language = (string)($snapshot['language'] ?? '');
        if ($language !== '') {
            try {
                RequestContext::setWelineUserLang($language);
            } catch (\Throwable) {
            }
        }
        $currency = (string)($snapshot['currency'] ?? '');
        if ($currency !== '') {
            try {
                RequestContext::setWelineUserCurrency($currency);
            } catch (\Throwable) {
                $_SERVER['WELINE_USER_CURRENCY'] = $currency;
            }
        }

        $area = \trim((string)($snapshot['area'] ?? ''));
        if ($area !== '') {
            try {
                RequestContext::setWelineArea($area);
            } catch (\Throwable) {
            }
        }
        $websiteUrl = \trim((string)($snapshot['website_url'] ?? ''));
        if ($websiteUrl !== '') {
            try {
                RequestContext::setWelineWebsiteUrl($websiteUrl);
            } catch (\Throwable) {
            }
        }

        self::restoreScopeOriginPins(
            \trim((string)($snapshot['host'] ?? '')),
            \trim((string)($snapshot['scheme'] ?? '')),
        );
    }

    private static function restoreScopeOriginPins(string $host, string $scheme): void
    {
        if ($host === '' && $scheme === '') {
            return;
        }

        try {
            $context = Context::getCurrent();
            if ($context === null) {
                if ($host !== '') {
                    $_SERVER['HTTP_HOST'] = $host;
                }
                if ($scheme !== '') {
                    $_SERVER['REQUEST_SCHEME'] = $scheme;
                }
                return;
            }

            $server = $context->get('input.server');
            $server = \is_array($server) ? $server : [];
            if ($host !== '') {
                $context->set('input.host', $host);
                $server['HTTP_HOST'] = $host;
                $_SERVER['HTTP_HOST'] = $host;
            }
            if ($scheme !== '') {
                $context->set('input.scheme', $scheme);
                $server['REQUEST_SCHEME'] = $scheme;
                $_SERVER['REQUEST_SCHEME'] = $scheme;
            }
            $context->set('input.server', $server);
        } catch (\Throwable) {
            if ($host !== '') {
                $_SERVER['HTTP_HOST'] = $host;
            }
            if ($scheme !== '') {
                $_SERVER['REQUEST_SCHEME'] = $scheme;
            }
        }
    }

    private static function releaseSharedFull(?string $requestId): void
    {
        if ($requestId === null || $requestId === '' || !isset(self::$sharedFullByRequestId[$requestId])) {
            return;
        }
        self::$sharedFullByRequestId[$requestId]['refs']--;
        if (self::$sharedFullByRequestId[$requestId]['refs'] <= 0) {
            unset(self::$sharedFullByRequestId[$requestId]);
        }
    }

    private static function cleanupRestoredContext(): void
    {
        $failures = [];
        try {
            StateManager::reset();
        } catch (\Throwable $e) {
            RequestResetException::append($failures, 'state_manager', $e);
        }

        try {
            Context::leave();
        } catch (\Throwable $e) {
            RequestResetException::append($failures, 'context_leave', $e);
        }

        if ($failures !== []) {
            throw new RequestResetException('post_response_task', $failures);
        }
    }
}
