<?php
declare(strict_types=1);

/**
 * Transport-neutral helpers shared by the plain HTTP, stream TLS and
 * experimental EventBuffer TLS Workers.
 *
 * Keep socket accept, TLS handshake, framing and response writes in the owning
 * Transport Adapter. This file only contains process/runtime bookkeeping that
 * must behave identically in both Workers.
 */

if (!\function_exists('wlsBootstrapFrameworkRuntime')) {
    function wlsBootstrapFrameworkRuntime(): \Weline\Framework\Runtime\WlsRuntime
    {
        $runtime = new \Weline\Framework\Runtime\WlsRuntime();
        $runtime->bootstrap();
        return $runtime;
    }
}

if (!\function_exists('wlsCreateWorkerFullPageCacheFastPath')) {
    function wlsCreateWorkerFullPageCacheFastPath(
        \Weline\Framework\Runtime\WlsRuntime $runtime
    ): \Weline\Server\Service\WorkerFullPageCacheFastPath {
        return new \Weline\Server\Service\WorkerFullPageCacheFastPath(
            \Weline\Framework\Manager\ObjectManager::getInstance(
                \Weline\Framework\Router\FullPageCacheCoordinator::class
            ),
            $runtime,
        );
    }
}

if (!\function_exists('wlsNormalizeMemoryLimit')) {
    function wlsNormalizeMemoryLimit(mixed $value, string $default = '256M'): string
    {
        if (\is_int($value) || \is_float($value)) {
            $value = (string) (int) $value;
        }
        $value = \strtoupper(\trim((string) $value));
        $default = \strtoupper(\trim($default)) ?: '256M';
        if ($value === '') {
            return $default;
        }
        if ($value === '-1') {
            return '-1';
        }
        if (\preg_match('/^[1-9]\d*$/', $value)) {
            return $value . 'M';
        }
        if (\preg_match('/^[1-9]\d*(?:K|M|G)$/', $value)) {
            return $value;
        }
        return $default;
    }
}

if (!\function_exists('wlsMemoryLimitToBytes')) {
    function wlsMemoryLimitToBytes(mixed $value): int
    {
        $limit = \strtoupper(\trim((string) $value));
        if ($limit === '' || $limit === '-1' || $limit === '0') {
            return 0;
        }

        $unit = \substr($limit, -1);
        $number = (float) $limit;
        if ($number <= 0) {
            return 0;
        }

        return match ($unit) {
            'G' => (int) \round($number * 1024 * 1024 * 1024),
            'M' => (int) \round($number * 1024 * 1024),
            'K' => (int) \round($number * 1024),
            default => (int) \round($number),
        };
    }
}

if (!\function_exists('wlsResetLongRunningExecutionLimit')) {
    function wlsResetLongRunningExecutionLimit(): void
    {
        if (\function_exists('ini_set') && (string)@\ini_get('max_execution_time') !== '0') {
            @\ini_set('max_execution_time', '0');
        }
        if (\function_exists('set_time_limit')) {
            @\set_time_limit(0);
        }
    }
}

/**
 * @param array<int, array<string, mixed>> $activeFibers
 */
function wlsCountActiveFibersForAdmission(array $activeFibers): int
{
    $count = 0;
    foreach ($activeFibers as $fiberState) {
        if (($fiberState['is_sse_protocol'] ?? false) === true) {
            continue;
        }
        $count++;
    }

    return $count;
}

/**
 * Fiber 请求结束后统一清台（响应已完成/连接已关闭后调用）。
 */
function wlsFiberRequestContextLeave(): void
{
    if (\session_status() === PHP_SESSION_ACTIVE) {
        @\session_write_close();
    }
    \Weline\Framework\Http\Sse\SseContext::reset();
    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Manager\ObjectManager::removeInstance(\Weline\Framework\Http\Request::class);
    try {
        $resolvedClass = \Weline\Framework\Manager\ObjectManager::parserClass(\Weline\Framework\Http\Request::class);
        if ($resolvedClass !== \Weline\Framework\Http\Request::class) {
            \Weline\Framework\Manager\ObjectManager::removeInstance($resolvedClass);
        }
    } catch (\Throwable) {
    }
}

function wlsDrainPostResponseTasks(
    int $activeRequests = 0,
    array $requestBuffers = [],
    array $writeBuffers = [],
    ?int $currentConnId = null
): void
{
    if (!\class_exists(\Weline\Framework\Runtime\PostResponseTaskQueue::class)) {
        return;
    }

    $deferWhenBusy = (bool)(\Weline\Framework\App\Env::get('wls.post_response_task_defer_when_busy', true) ?? true);
    if ($deferWhenBusy && wlsWorkerHasPendingRequestWork($activeRequests, $requestBuffers, $writeBuffers, $currentConnId)) {
        return;
    }

    $maxTasks = (int)(\Weline\Framework\App\Env::get('wls.post_response_task_max_per_drain', 1) ?: 1);
    \Weline\Framework\Runtime\PostResponseTaskQueue::drain(
        (float)(\Weline\Framework\App\Env::get('wls.post_response_task_budget_ms', 8) ?: 8),
        \max(1, $maxTasks)
    );
}

function wlsWorkerHasPendingRequestWork(
    int $activeRequests,
    array $requestBuffers,
    array $writeBuffers,
    ?int $currentConnId
): bool {
    if ($activeRequests > 0) {
        return true;
    }

    foreach ($requestBuffers as $connId => $buffer) {
        if ($currentConnId !== null && (int)$connId === $currentConnId) {
            continue;
        }
        if (\is_string($buffer) && $buffer !== '') {
            return true;
        }
    }

    foreach ($writeBuffers as $connId => $buffer) {
        if ($currentConnId !== null && (int)$connId === $currentConnId) {
            continue;
        }
        if (\is_string($buffer) && $buffer !== '') {
            return true;
        }
    }

    return false;
}

function wlsGetStaticFileCacheStatus(): array
{
    if (!\function_exists('handleStaticFile')) {
        return [];
    }

    $rawStatus = handleStaticFile('__CACHE_STATUS__', '');
    if (!\is_string($rawStatus) || $rawStatus === '') {
        return [];
    }

    $decoded = \json_decode($rawStatus, true);
    return \is_array($decoded) ? $decoded : [];
}

function wlsCompactWorkerMemoryCaches(
    string $reason,
    int $maxMemoryBytes = 0,
    float $staticClearPressure = 0.55,
    int $staticClearMinBytes = 16777216,
    bool $forceStaticClear = false
): array {
    $beforeMemory = \memory_get_usage(true);
    $pressure = $maxMemoryBytes > 0 ? $beforeMemory / $maxMemoryBytes : 0.0;
    $status = wlsGetStaticFileCacheStatus();
    $staticSize = (int)($status['size'] ?? 0);
    $staticCount = (int)($status['count'] ?? 0);
    $staticClear = [
        'cleared' => false,
        'reason' => $reason,
        'count' => $staticCount,
        'size' => $staticSize,
        'pressure' => $pressure,
    ];

    if (
        \function_exists('handleStaticFile')
        && ($forceStaticClear || $staticSize >= $staticClearMinBytes || $pressure >= $staticClearPressure)
        && ($staticSize > 0 || $staticCount > 0 || $forceStaticClear)
    ) {
        $rawClear = handleStaticFile('__CLEAR_CACHE__', '');
        if (\is_string($rawClear) && \preg_match('/^cleared:(\d+):(\d+)$/', $rawClear, $matches) === 1) {
            $staticClear['count'] = (int)$matches[1];
            $staticClear['size'] = (int)$matches[2];
        }
        $staticClear['cleared'] = true;
    }

    $compaction = \Weline\Server\Service\WorkerResponseMemoryGuard::compact();
    $compaction['static_file_cache'] = $staticClear;
    $compaction['memory_before_bytes'] = $beforeMemory;
    $compaction['memory_after_bytes'] = \memory_get_usage(true);

    return $compaction;
}

function wlsWorkerMemoryHealthDiagnostics(bool $includeStaticProperties = false, bool $includeObjectProperties = false): array
{
    $diagnostics = [
        'memory_usage_allocated' => \memory_get_usage(true),
        'memory_usage_used' => \memory_get_usage(false),
        'memory_peak_allocated' => \memory_get_peak_usage(true),
        'memory_peak_used' => \memory_get_peak_usage(false),
        'static_file_cache' => wlsGetStaticFileCacheStatus(),
        'gc_status' => \function_exists('gc_status') ? \gc_status() : [],
        'object_manager' => [],
        'state_manager' => [],
    ];

    if (\class_exists(\Weline\Framework\Manager\ObjectManager::class, false)) {
        try {
            $diagnostics['object_manager'] = \Weline\Framework\Manager\ObjectManager::getRuntimeMemoryDiagnostics(12, $includeObjectProperties);
        } catch (\Throwable $throwable) {
            $diagnostics['object_manager_error'] = $throwable->getMessage();
        }
    }

    if (\class_exists(\Weline\Framework\Runtime\StateManager::class, false)) {
        try {
            $diagnostics['state_manager'] = \Weline\Framework\Runtime\StateManager::getStats();
        } catch (\Throwable $throwable) {
            $diagnostics['state_manager_error'] = $throwable->getMessage();
        }
    }

    if ($includeStaticProperties) {
        $diagnostics['static_properties'] = wlsWorkerStaticPropertyDiagnostics();
    }

    return $diagnostics;
}

function wlsWorkerStaticPropertyDiagnostics(int $limit = 25, int $thresholdBytes = 8192): array
{
    $limit = \max(1, \min(100, $limit));
    $thresholdBytes = \max(0, $thresholdBytes);
    $items = [];
    $classesScanned = 0;
    $propertiesScanned = 0;

    foreach (\get_declared_classes() as $className) {
        if (!\str_starts_with($className, 'Weline\\') && !\str_starts_with($className, 'GuoLaiRen\\')) {
            continue;
        }

        try {
            $reflection = new \ReflectionClass($className);
            $properties = $reflection->getStaticProperties();
        } catch (\Throwable) {
            continue;
        }

        $classesScanned++;
        foreach ($properties as $propertyName => $value) {
            $propertiesScanned++;
            $approxBytes = wlsApproxMemoryValueSize($value);
            if ($approxBytes < $thresholdBytes) {
                continue;
            }
            $items[] = [
                'property' => $className . '::$' . (string)$propertyName,
                'type' => \get_debug_type($value),
                'count' => \is_countable($value) ? \count($value) : null,
                'approx_bytes' => $approxBytes,
            ];
        }
    }

    \usort(
        $items,
        static fn(array $a, array $b): int => ((int)$b['approx_bytes']) <=> ((int)$a['approx_bytes'])
    );

    return [
        'classes_scanned' => $classesScanned,
        'properties_scanned' => $propertiesScanned,
        'threshold_bytes' => $thresholdBytes,
        'top' => \array_slice($items, 0, $limit),
    ];
}

function wlsApproxMemoryValueSize(mixed $value, int $depth = 0, int &$visited = 0): int
{
    if ($visited > 50000) {
        return 0;
    }
    $visited++;

    if (\is_string($value)) {
        return \strlen($value);
    }
    if (\is_int($value) || \is_float($value) || \is_bool($value) || $value === null) {
        return 16;
    }
    if (\is_object($value)) {
        return 128;
    }
    if (\is_resource($value)) {
        return 32;
    }
    if (!\is_array($value)) {
        return 0;
    }
    if ($depth >= 5) {
        return \count($value) * 32;
    }

    $size = 16;
    foreach ($value as $key => $item) {
        $size += \is_string($key) ? \strlen($key) : 16;
        $size += wlsApproxMemoryValueSize($item, $depth + 1, $visited);
        if ($visited > 50000) {
            break;
        }
    }

    return $size;
}
