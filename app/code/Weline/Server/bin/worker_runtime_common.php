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

if (!\function_exists('wlsAppendBackendLoginReturnUrl')) {
    function wlsAppendBackendLoginReturnUrl(
        string $redirectUrl,
        \Weline\Framework\Http\Request $request,
        string $method,
        string $requestTarget,
    ): string {
        $method = \strtoupper($method);
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $redirectUrl;
        }

        $redirectPath = (string) (\parse_url($redirectUrl, PHP_URL_PATH) ?: '');
        $normalizedRedirectPath = \strtolower($redirectPath);
        if ($normalizedRedirectPath === ''
            || !\str_ends_with($normalizedRedirectPath, '/admin/login')
        ) {
            return $redirectUrl;
        }

        $uri = $requestTarget;
        if ($uri === '') {
            $uri = (string) ($request->getServer('WELINE_ORIGIN_REQUEST_URI')
                ?: $request->getServer('REQUEST_URI'));
        }
        $queryString = (string) $request->getServer('QUERY_STRING');
        if ($queryString !== '' && !\str_contains($uri, '?')) {
            $uri .= '?' . $queryString;
        }
        if ($uri === '') {
            return $redirectUrl;
        }

        $currentPath = \strtolower((string) (\parse_url($uri, PHP_URL_PATH) ?: ''));
        if ($currentPath === ''
            || \str_ends_with($currentPath, '/admin/login')
            || \str_ends_with($currentPath, '/admin/login/post')
            || \str_ends_with($currentPath, '/admin/login/logout')
        ) {
            return $redirectUrl;
        }

        $backendPrefix = \substr($redirectPath, 0, -\strlen('/admin/login'));
        $uriPath = (string) (\parse_url($uri, PHP_URL_PATH) ?: '');
        if ($backendPrefix !== ''
            && $uriPath !== ''
            && !\str_starts_with($uriPath, $backendPrefix . '/')
        ) {
            $uri = $backendPrefix . (\str_starts_with($uri, '/') ? $uri : '/' . $uri);
        }
        $uri = wlsNormalizeBackendReturnUri($uri);

        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = wlsResolveBackendLoginReturnHost($request, $scheme);
        $returnUrl = $scheme . '://' . $host
            . (\str_starts_with($uri, '/') ? $uri : '/' . $uri);
        $query = [
            'no_access_reason' => 'not_logged_in',
            'return_url' => $returnUrl,
        ];

        $redirectUrl = wlsRemoveBackendLoginReturnParams($redirectUrl);
        return $redirectUrl
            . (\str_contains($redirectUrl, '?') ? '&' : '?')
            . \http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!\function_exists('wlsResolveBackendLoginReturnHost')) {
    function wlsResolveBackendLoginReturnHost(
        \Weline\Framework\Http\Request $request,
        string $scheme
    ): string {
        $host = \trim((string) ($request->getServer('HTTP_HOST')
            ?: $request->getServer('SERVER_NAME')
            ?: 'localhost'));
        if ($host === '' || \str_contains($host, ':') || \str_starts_with($host, '[')) {
            return $host !== '' ? $host : 'localhost';
        }

        $port = \trim((string) ($request->getServer('HTTP_WELINE_ORIGINAL_PORT') ?: ''));
        if ($port === '' || !\ctype_digit($port)) {
            return $host;
        }

        if (($scheme === 'http' && $port === '80')
            || ($scheme === 'https' && $port === '443')
        ) {
            return $host;
        }

        return $host . ':' . $port;
    }
}

if (!\function_exists('wlsNormalizeBackendReturnUri')) {
    function wlsNormalizeBackendReturnUri(string $uri): string
    {
        $path = (string) (\parse_url($uri, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return $uri;
        }

        $segments = \explode('/', \trim($path, '/'));
        $firstSegment = (string) ($segments[0] ?? '');
        if (!isset($segments[1], $segments[2], $segments[3])
            || $firstSegment === ''
            || !wlsIsBackendReturnCurrencySegment($segments[1])
            || !wlsIsBackendReturnLocaleSegment($segments[2])
            || $segments[3] !== $firstSegment
        ) {
            return $uri;
        }

        \array_splice($segments, 3, 1);
        $normalized = '/' . \implode('/', $segments);
        $query = (string) (\parse_url($uri, PHP_URL_QUERY) ?: '');
        $fragment = (string) (\parse_url($uri, PHP_URL_FRAGMENT) ?: '');
        return $normalized
            . ($query !== '' ? '?' . $query : '')
            . ($fragment !== '' ? '#' . $fragment : '');
    }
}

if (!\function_exists('wlsIsBackendReturnCurrencySegment')) {
    function wlsIsBackendReturnCurrencySegment(string $segment): bool
    {
        return \Weline\Framework\App\State::isAllowedCurrencyCode($segment);
    }
}

if (!\function_exists('wlsIsBackendReturnLocaleSegment')) {
    function wlsIsBackendReturnLocaleSegment(string $segment): bool
    {
        return (bool) \preg_match('/^[a-z]{2}(?:[_-][A-Za-z0-9]{2,8}){1,3}$/', $segment);
    }
}

if (!\function_exists('wlsRemoveBackendLoginReturnParams')) {
    function wlsRemoveBackendLoginReturnParams(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts) || empty($parts['query'])) {
            return $url;
        }

        \parse_str((string) $parts['query'], $params);
        unset($params['no_access_reason'], $params['return_url']);
        $query = \http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');
        if (isset($parts['port'])) {
            $base .= ':' . $parts['port'];
        }
        $base .= $parts['path'] ?? '';
        return $query === '' ? $base : $base . '?' . $query;
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
 * 进入 Fiber 请求上下文；有其他挂起请求 Fiber 时，省略会破坏同伴状态的 reset 回调。
 */
function wlsFiberRequestContextEnter(mixed $conn, int|string|null $connectionId = null): void
{
    $omitCallbacks = null;
    if (
        \Weline\Framework\Runtime\Runtime::isPersistent()
        && \Weline\Framework\Runtime\WlsConcurrency::getOtherSuspendedRequestFiberCount() > 0
    ) {
        $omitCallbacks = \Weline\Framework\Runtime\WlsConcurrency::callbackNamesOmittableWithPeerFibers();
    }
    \Weline\Framework\Runtime\StateManager::reset($omitCallbacks);

    \Weline\Framework\Runtime\RequestContext::cleanup();
    \Weline\Framework\Http\Url::resetWlsFiberInterleavedParserScratch();
    \Weline\Framework\Http\Sse\SseContext::reset();
    \Weline\Framework\Http\Sse\SseContext::setConnection($conn);
    \Weline\Framework\Http\Sse\SseContext::clearWriteCallback();
    \Weline\Framework\Http\Sse\SseContext::clearAliveCallback();

    $resolvedConnectionId = $connectionId;
    if ($resolvedConnectionId === null && \is_resource($conn)) {
        $resolvedConnectionId = \get_resource_id($conn);
    }

    $context = \Weline\Framework\Context::current();
    $context->set('meta.type', 'request');
    $context->set('meta.mode', 'wls');
    $context->set('runtime.connection_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    $context->set('runtime.chain_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    $context->setRuntimeAttr('connection_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    $context->setRuntimeAttr('chain_id', $resolvedConnectionId === null ? '' : (string)$resolvedConnectionId);
    \Weline\Framework\Runtime\RequestContext::setConnectionId(
        $resolvedConnectionId === null ? null : (string)$resolvedConnectionId
    );
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
