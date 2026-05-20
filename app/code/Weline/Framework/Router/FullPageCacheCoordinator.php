<?php

declare(strict_types=1);

namespace Weline\Framework\Router;

use Weline\Framework\Cache\Adapter\WlsMemoryAdapter;
use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\App\Env;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Response;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Session\SessionFactory;

final class FullPageCacheCoordinator
{
    private const LOCK_TTL_SECONDS = 15;
    private const LOCK_WAIT_TIMEOUT_MS = 10000;
    private const LOCK_WAIT_STEP_MS = 5;
    private const UNIFIED_CACHE_FPC_GZIP_B64_KEY = 'fpc_gzip_b64';
    private const UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY = 'fpc_html_urls_validated';
    private const GZIP_CACHE_MIN_BODY_BYTES = 1024;
    private const PROCESS_FPC_TTL_SECONDS = 3600;
    private const PROCESS_FPC_MAX_ITEMS = 128;
    private const PROCESS_FPC_MAX_BYTES = 33554432;

    /**
     * @var array<string, bool>
     */
    private const IGNORABLE_QUERY_PARAM_KEYS = [
        '_' => true,
        'ai_perf' => true,
        'fbclid' => true,
        'gbraid' => true,
        'gclid' => true,
        'igshid' => true,
        'mc_cid' => true,
        'mc_eid' => true,
        'msclkid' => true,
        'wbraid' => true,
        'yclid' => true,
    ];

    /**
     * @var list<string>
     */
    private const IGNORABLE_QUERY_PARAM_PREFIXES = [
        'utm_',
        'mtm_',
        'pk_',
    ];

    /**
     * @var array<string, bool>
     */
    private const EXCLUDED_HEADERS = [
        'content-length' => true,
        'connection' => true,
        'server' => true,
        'x-powered-by' => true,
        'set-cookie' => true,
        'x-weline-fpc' => true,
    ];

    /**
     * Headers below describe the current request, debug probes, worker routing,
     * or transient render/build stages. They must never be replayed from FPC.
     *
     * @var list<string>
     */
    private const EXCLUDED_HEADER_PREFIXES = [
        'x-wls-',
        'x-weline-account-',
        'x-weline-debug-',
        'x-weline-layout-',
        'x-weline-request-',
        'x-weline-route-',
    ];

    private ?CacheManager $cacheManager;
    private ?CachePoolInterface $cachePool;

    /** @var array<string, array<string, mixed>> */
    private static array $processFpcPayloadCache = [];

    /** @var array<string, float> */
    private static array $processFpcPayloadExpiresAt = [];

    /** @var array<string, int> */
    private static array $processFpcPayloadBytes = [];

    private static int $processFpcPayloadTotalBytes = 0;

    public function __construct(?CacheManager $cacheManager = null, ?CachePoolInterface $cachePool = null)
    {
        $this->cacheManager = $cacheManager;
        $this->cachePool = $cachePool;
    }

    public function getCachedResponse(string $method = 'GET'): ?Response
    {
        if (!$this->canServeCachedResponse($method)) {
            return null;
        }

        $response = $this->getUnifiedCachedResponse($method);
        if ($response !== null) {
            return $this->markCacheHit($response);
        }

        $legacyKey = $this->getLegacyCacheKey();
        if ($legacyKey === null) {
            return null;
        }

        $html = $this->cache()->get($legacyKey);
        if (!\is_string($html) || $html === '') {
            return null;
        }

        return $this->markCacheHit(Response::html($html)->setHeader('X-Weline-FPC', 'HIT'));
    }

    /**
     * @return array{driver:string,key:string,token?:string,handle?:resource}|null
     */
    public function acquireBuildLock(string $method = 'GET'): ?array
    {
        if (!$this->canBuildCachedResponse($method)) {
            return null;
        }

        $fullUri = $this->getCacheKeyFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return null;
        }

        $lockKey = $this->getBuildLockKey($method);
        $adapter = $this->resolveWlsMemoryAdapter();
        if ($adapter !== null) {
            $token = $this->generateLockToken();
            if ($adapter->compareAndSet($lockKey, null, $token, self::LOCK_TTL_SECONDS)) {
                $this->canonicalizeCurrentRequestForCacheBuild();
                return [
                    'driver' => 'shared',
                    'key' => $lockKey,
                    'token' => $token,
                ];
            }

            return null;
        }

        $handle = $this->acquireFileLock($lockKey);
        if ($handle === null) {
            return null;
        }

        $this->canonicalizeCurrentRequestForCacheBuild();
        return [
            'driver' => 'file',
            'key' => $lockKey,
            'handle' => $handle,
        ];
    }

    public function waitForPublishedResponse(string $method = 'GET', int $timeoutMs = self::LOCK_WAIT_TIMEOUT_MS): ?Response
    {
        if ($timeoutMs <= 0) {
            return $this->getCachedResponse($method);
        }

        $deadline = \microtime(true) + ($timeoutMs / 1000);
        do {
            $response = $this->getCachedResponse($method);
            if ($response !== null) {
                return $response;
            }

            $remainingMs = (int)\max(0, \ceil(($deadline - \microtime(true)) * 1000));
            if ($remainingMs <= 0) {
                break;
            }

            SchedulerSystem::yieldDelay(\min(self::LOCK_WAIT_STEP_MS, $remainingMs));
        } while (\microtime(true) < $deadline);

        return $this->getCachedResponse($method);
    }

    /**
     * @param array{driver:string,key:string,token?:string,handle?:resource}|null $lock
     */
    public function releaseBuildLock(?array $lock): void
    {
        if ($lock === null) {
            return;
        }

        if (($lock['driver'] ?? '') === 'shared') {
            $adapter = $this->resolveWlsMemoryAdapter();
            $token = (string)($lock['token'] ?? '');
            $key = (string)($lock['key'] ?? '');
            if ($adapter !== null && $token !== '' && $key !== '') {
                $adapter->compareAndSet($key, $token, null, 1);
            }

            return;
        }

        $handle = $lock['handle'] ?? null;
        if (\is_resource($handle)) {
            @\flock($handle, \LOCK_UN);
            @\fclose($handle);
        }
    }

    public function publishResponse(
        Response $response,
        string $url,
        array $rule,
        array $router,
        array $generatedParams,
        string $method = 'GET'
    ): void {
        if (!$this->canPublishResponse($response, $method)) {
            return;
        }

        $fullUri = $this->getCacheKeyFullUri();
        $body = $response->getBody();
        if ($body === '' || !KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return;
        }
        if ($this->bodyContainsIgnorableHtmlUrlQuery($body) || $this->bodyContainsRawIgnorableRequestQuery($body)) {
            $this->logFpcWarning('skip publish polluted seo url', [
                'cache_key_full_uri' => $fullUri,
                'raw_full_uri' => $this->getRawFullUri(),
                'method' => $method,
            ]);
            return;
        }

        $payload = [
            KeyBuilder::UNIFIED_CACHE_URL_KEY => $url,
            KeyBuilder::UNIFIED_CACHE_RULE_KEY => $rule,
            KeyBuilder::UNIFIED_CACHE_ROUTER_KEY => $router,
            KeyBuilder::UNIFIED_CACHE_PARAMS_KEY => $generatedParams,
            KeyBuilder::UNIFIED_CACHE_FPC_KEY => $body,
            KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => $this->sanitizeHeaders($response->getHeaders()),
            KeyBuilder::UNIFIED_CACHE_STATUS_KEY => $response->getStatusCode(),
            self::UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY => true,
        ];

        $gzipBody = $this->buildCachedGzipBody($body);
        if ($gzipBody !== null) {
            $payload[self::UNIFIED_CACHE_FPC_GZIP_B64_KEY] = \base64_encode($gzipBody);
        }

        $unifiedCacheKey = $this->getUnifiedCacheKey($method);
        $this->cache()->set($unifiedCacheKey, $payload, 3600);
        $this->setProcessCachedPayload($unifiedCacheKey, $payload);

        $legacyKey = $this->getLegacyCacheKey();
        if ($legacyKey !== null) {
            $this->cache()->set($legacyKey, $body, 5);
        }
    }

    public function canServeCachedResponse(string $method = 'GET'): bool
    {
        return $this->isFrontendResponseCacheAllowed($method) && !$this->hasLoggedInFrontendSession();
    }

    public function canBuildCachedResponse(string $method = 'GET'): bool
    {
        return $this->isFrontendResponseCacheAllowed($method)
            && !$this->hasLoggedInFrontendSession();
    }

    public function canPublishResponse(Response $response, string $method = 'GET'): bool
    {
        if (!$this->canBuildCachedResponse($method)) {
            return false;
        }

        if ($response->getStatusCode() !== 200 || $response->getBody() === '') {
            return false;
        }

        return !$this->responseDeclaresPrivateCache($response);
    }

    public static function clearProcessCache(): void
    {
        self::$processFpcPayloadCache = [];
        self::$processFpcPayloadExpiresAt = [];
        self::$processFpcPayloadBytes = [];
        self::$processFpcPayloadTotalBytes = 0;
    }

    private function isFrontendResponseCacheAllowed(string $method): bool
    {
        $method = \strtoupper($method ?: 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        if (!Runtime::isPersistent() && !PROD) {
            return false;
        }

        if (!Env::get('cache.status.router_cache', 1) || !Env::get('cache.status.frontend_cache', 1)) {
            return false;
        }

        if ((bool)WelineEnv::get('is_static_file', false) || (bool)WelineEnv::get('is_backend', false)) {
            return false;
        }

        $rawFullUri = $this->getRawFullUri();
        $cacheKeyFullUri = $this->getCacheKeyFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($rawFullUri) || !KeyBuilder::isValidFullPageCacheKey($cacheKeyFullUri)) {
            return false;
        }

        if ($this->requestHasSseAcceptHeader() || $this->isEditorOrPreviewRequest($rawFullUri)) {
            return false;
        }

        return !$this->isExcludedFrontendPath($rawFullUri);
    }

    private function isEditorOrPreviewRequest(string $fullUri): bool
    {
        if (\in_array((string)WelineEnv::get('editor_mode', ''), ['1', 'true'], true)) {
            return true;
        }

        $query = (string)(\parse_url($fullUri, \PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return false;
        }

        \parse_str($query, $params);
        foreach (['preview', 'visual_editor', 'editor_mode', 'workspace_preview', 'debug_hooks', 'no_cache', 'nocache'] as $key) {
            if (isset($params[$key]) && (string)$params[$key] !== '' && (string)$params[$key] !== '0') {
                return true;
            }
        }

        return false;
    }

    private function isExcludedFrontendPath(string $fullUri): bool
    {
        $path = (string)(\parse_url($fullUri, \PHP_URL_PATH) ?: '/');
        $path = \strtolower($this->stripLocaleAndCurrencyPrefixes('/' . \trim($path, '/')));
        if ($path === '/') {
            return false;
        }

        $firstSegment = \explode('/', \trim($path, '/'))[0] ?? '';
        $blockedPrefixes = \array_filter([
            \strtolower(\trim((string)Env::getAreaRoutePrefix('backend'), '/')),
            \strtolower(\trim((string)Env::getAreaRoutePrefix('rest_backend'), '/')),
            \strtolower(\trim((string)Env::getAreaRoutePrefix('rest_frontend'), '/')),
            'api',
            'customer',
            'account',
            'cart',
            'checkout',
            'wishlist',
            'auth',
            'logout',
            'login',
            'twofactor',
            'delivery',
            'tax',
            'rma',
        ]);
        if ($firstSegment !== '' && \in_array($firstSegment, $blockedPrefixes, true)) {
            return true;
        }

        return \str_contains($path, '/workspace-preview')
            || \str_contains($path, '/pagebuilder/backend/')
            || \str_contains($path, '/developer-workspace/');
    }

    private function stripLocaleAndCurrencyPrefixes(string $path): string
    {
        $segments = \array_values(\array_filter(\explode('/', \trim($path, '/')), static function (string $segment): bool {
            return $segment !== '';
        }));
        while ($segments !== []) {
            $segment = (string)$segments[0];
            if ($this->isLocalePrefix($segment) || $this->isCurrencyPrefix($segment)) {
                \array_shift($segments);
                continue;
            }

            break;
        }

        return $segments === [] ? '/' : '/' . \implode('/', $segments);
    }

    private function isLocalePrefix(string $segment): bool
    {
        return (bool)\preg_match('/^[a-z]{2}(?:[-_][a-z0-9]{2,5}){1,2}$/i', $segment);
    }

    private function isCurrencyPrefix(string $segment): bool
    {
        return (bool)\preg_match('/^[A-Z]{3}$/', $segment);
    }

    private function requestHasSseAcceptHeader(): bool
    {
        $accept = (string)(WelineEnv::server('HTTP_ACCEPT', '') ?: WelineEnv::get('server.http_accept', ''));
        return \stripos($accept, 'text/event-stream') !== false;
    }

    private function hasLoggedInFrontendSession(): bool
    {
        $cacheKey = 'fpc.frontend_logged_in';
        $cached = RequestContext::get($cacheKey);
        if (\is_bool($cached)) {
            return $cached;
        }

        if (!$this->hasFrontendSessionCookie()) {
            RequestContext::set($cacheKey, false);
            return false;
        }

        try {
            $loggedIn = SessionFactory::getInstance()->createFrontendSession()->isLoggedIn();
            RequestContext::set($cacheKey, $loggedIn);
            return $loggedIn;
        } catch (\Throwable) {
            RequestContext::set($cacheKey, true);
            return true;
        }
    }

    private function hasFrontendSessionCookie(): bool
    {
        if (isset($_COOKIE['WELINE_SESSID']) && (string)$_COOKIE['WELINE_SESSID'] !== '') {
            return true;
        }

        $cookieHeader = (string)(WelineEnv::server('HTTP_COOKIE', '') ?: WelineEnv::get('server.http_cookie', ''));
        return \stripos($cookieHeader, 'WELINE_SESSID=') !== false;
    }

    private function responseDeclaresPrivateCache(Response $response): bool
    {
        $cacheControl = $response->getHeader('Cache-Control');
        if (\is_array($cacheControl)) {
            $cacheControl = \implode(',', \array_map('strval', $cacheControl));
        }

        return \stripos((string)$cacheControl, 'private') !== false;
    }

    private function getUnifiedCachedResponse(string $method): ?Response
    {
        $cacheKey = $this->getUnifiedCacheKey($method);
        $cachePayloadFetched = false;
        $cachePayloadUpdated = false;
        $cached = $this->getProcessCachedPayload($cacheKey);
        $cacheSource = 'process';
        if ($cached === null) {
            $cached = $this->cache()->get($cacheKey);
            $cachePayloadFetched = \is_array($cached);
            $cacheSource = $cachePayloadFetched ? 'shared' : 'miss';
        }
        if (!\is_array($cached)) {
            RequestContext::set('wls.fpc.hit_source', 'miss');
            return null;
        }

        $body = (string)($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? '');
        if ($body === '') {
            RequestContext::set('wls.fpc.hit_source', 'invalid');
            return null;
        }
        $validateCachedHtmlUrls = $this->shouldValidateCachedHtmlUrlsOnHit($cached);
        if ($validateCachedHtmlUrls && $this->bodyContainsIgnorableHtmlUrlQuery($body)) {
            $this->deleteCurrentCacheEntries($method);
            $this->logFpcWarning('drop cached polluted seo url', [
                'cache_key_full_uri' => $this->getCacheKeyFullUri(),
                'raw_full_uri' => $this->getRawFullUri(),
                'method' => $method,
            ]);
            return null;
        }
        if ($validateCachedHtmlUrls) {
            $cached[self::UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY] = true;
            $cachePayloadUpdated = true;
        }
        if ($cachePayloadFetched || $cachePayloadUpdated) {
            $this->setProcessCachedPayload($cacheKey, $cached);
        }

        $statusCode = (int)($cached[KeyBuilder::UNIFIED_CACHE_STATUS_KEY] ?? 200);
        $gzipBody = $this->resolveCachedGzipBody($cached);
        $useGzipBody = $gzipBody !== null && $this->clientAcceptsGzip();

        $response = Response::fromContent($useGzipBody ? $gzipBody : $body, $statusCode, 'text/html; charset=utf-8');
        $this->applyCachedHeaders($response, $cached[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY] ?? []);
        if ($useGzipBody) {
            $response->setHeader('Content-Encoding', 'gzip');
            $response->setHeader('Content-Length', (string)\strlen($gzipBody));
            $this->ensureVaryAcceptEncoding($response);
        }
        $response->setHeader('X-Weline-FPC', 'HIT');
        RequestContext::set('wls.fpc.hit_source', $cacheSource);
        RequestContext::set('wls.fpc.process_items', \count(self::$processFpcPayloadCache));
        RequestContext::set('wls.fpc.process_bytes', self::$processFpcPayloadTotalBytes);

        return $response;
    }

    /**
     * Cache publishing validates rendered HTML before it enters FPC. Legacy
     * payloads may not have the marker, so keep compatibility checks where
     * they matter without reparsing full HTML on every persistent WLS hit.
     *
     * @param array<string, mixed> $cached
     */
    private function shouldValidateCachedHtmlUrlsOnHit(array $cached): bool
    {
        if (($cached[self::UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY] ?? false) === true) {
            return false;
        }

        if (!Runtime::isPersistent()) {
            return true;
        }

        return $this->requestUsesCanonicalizedCacheKey();
    }

    private function buildCachedGzipBody(string $body): ?string
    {
        if (\strlen($body) < self::GZIP_CACHE_MIN_BODY_BYTES || !\function_exists('gzencode')) {
            return null;
        }

        $gzipBody = \gzencode($body, 6);

        return \is_string($gzipBody) && $gzipBody !== '' ? $gzipBody : null;
    }

    /**
     * @param array<string, mixed> $cached
     */
    private function resolveCachedGzipBody(array $cached): ?string
    {
        $encoded = $cached[self::UNIFIED_CACHE_FPC_GZIP_B64_KEY] ?? null;
        if (!\is_string($encoded) || $encoded === '') {
            return null;
        }

        $decoded = \base64_decode($encoded, true);

        return \is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function clientAcceptsGzip(): bool
    {
        $acceptEncoding = (string)(
            WelineEnv::server('HTTP_ACCEPT_ENCODING', '')
            ?: WelineEnv::get('server.http_accept_encoding', '')
        );

        return \stripos($acceptEncoding, 'gzip') !== false;
    }

    private function ensureVaryAcceptEncoding(Response $response): void
    {
        $vary = $response->getHeader('Vary');
        $varyValue = \is_array($vary) ? \implode(', ', \array_map('strval', $vary)) : (string)($vary ?? '');
        if ($varyValue === '') {
            $response->setHeader('Vary', 'Accept-Encoding');
            return;
        }

        foreach (\array_map('trim', \explode(',', $varyValue)) as $part) {
            if (\strcasecmp($part, 'Accept-Encoding') === 0) {
                return;
            }
        }

        $response->setHeader('Vary', $varyValue . ', Accept-Encoding');
    }

    private function applyCachedHeaders(Response $response, mixed $headers): void
    {
        if (!\is_array($headers)) {
            return;
        }

        $normalized = [];
        $isList = \array_is_list($headers);
        if ($isList) {
            foreach ($headers as $headerLine) {
                if (!\is_string($headerLine) || !\str_contains($headerLine, ':')) {
                    continue;
                }

                [$name, $value] = \explode(':', $headerLine, 2);
                $name = \trim($name);
                if ($this->shouldSkipHeader($name)) {
                    continue;
                }

                $headerValue = \trim($value);
                if (!isset($normalized[$name])) {
                    $normalized[$name] = $headerValue;
                    continue;
                }

                if (\is_array($normalized[$name])) {
                    $normalized[$name][] = $headerValue;
                    continue;
                }

                $normalized[$name] = [$normalized[$name], $headerValue];
            }
        } else {
            foreach ($headers as $name => $value) {
                if (!\is_string($name) || $this->shouldSkipHeader($name)) {
                    continue;
                }

                if (\is_array($value)) {
                    $normalized[$name] = \array_values(\array_map('strval', $value));
                } else {
                    $normalized[$name] = (string)$value;
                }
            }
        }

        foreach ($normalized as $name => $value) {
            $response->setHeaders([$name => $value]);
        }
    }

    /**
     * @param array<string, string|array> $headers
     * @return array<string, string|array>
     */
    private function sanitizeHeaders(array $headers): array
    {
        $sanitized = [];
        foreach ($headers as $name => $value) {
            if (!\is_string($name) || $this->shouldSkipHeader($name)) {
                continue;
            }

            if (\is_array($value)) {
                $sanitized[$name] = \array_values(\array_map('strval', $value));
            } else {
                $sanitized[$name] = (string)$value;
            }
        }

        return $sanitized;
    }

    private function shouldSkipHeader(string $name): bool
    {
        $normalized = \strtolower(\trim($name));
        if ($normalized === '') {
            return true;
        }
        if (self::EXCLUDED_HEADERS[$normalized] ?? false) {
            return true;
        }

        foreach (self::EXCLUDED_HEADER_PREFIXES as $prefix) {
            if (\str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getProcessCachedPayload(string $cacheKey): ?array
    {
        $expiresAt = self::$processFpcPayloadExpiresAt[$cacheKey] ?? 0.0;
        if ($expiresAt <= \microtime(true)) {
            $this->deleteProcessCachedPayload($cacheKey);
            return null;
        }

        $payload = self::$processFpcPayloadCache[$cacheKey] ?? null;
        return \is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function setProcessCachedPayload(string $cacheKey, array $payload): void
    {
        $body = $payload[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? '';
        if (!\is_string($body) || $body === '') {
            return;
        }

        $bytes = $this->estimatePayloadBytes($payload);
        if ($bytes <= 0 || $bytes > self::PROCESS_FPC_MAX_BYTES) {
            return;
        }

        $this->deleteProcessCachedPayload($cacheKey);
        while ((\count(self::$processFpcPayloadCache) >= self::PROCESS_FPC_MAX_ITEMS
                || self::$processFpcPayloadTotalBytes + $bytes > self::PROCESS_FPC_MAX_BYTES)
            && self::$processFpcPayloadCache !== []
        ) {
            $oldestKey = (string)\array_key_first(self::$processFpcPayloadCache);
            $this->deleteProcessCachedPayload($oldestKey);
        }

        self::$processFpcPayloadCache[$cacheKey] = $payload;
        self::$processFpcPayloadExpiresAt[$cacheKey] = \microtime(true) + self::PROCESS_FPC_TTL_SECONDS;
        self::$processFpcPayloadBytes[$cacheKey] = $bytes;
        self::$processFpcPayloadTotalBytes += $bytes;
    }

    private function deleteProcessCachedPayload(string $cacheKey): void
    {
        if (!isset(self::$processFpcPayloadCache[$cacheKey])) {
            return;
        }

        self::$processFpcPayloadTotalBytes -= self::$processFpcPayloadBytes[$cacheKey] ?? 0;
        if (self::$processFpcPayloadTotalBytes < 0) {
            self::$processFpcPayloadTotalBytes = 0;
        }
        unset(
            self::$processFpcPayloadCache[$cacheKey],
            self::$processFpcPayloadExpiresAt[$cacheKey],
            self::$processFpcPayloadBytes[$cacheKey]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function estimatePayloadBytes(array $payload): int
    {
        $bytes = 0;
        foreach ($payload as $value) {
            if (\is_string($value)) {
                $bytes += \strlen($value);
                continue;
            }
            if (\is_array($value)) {
                $encoded = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
                $bytes += \is_string($encoded) ? \strlen($encoded) : 0;
                continue;
            }
            if (\is_scalar($value)) {
                $bytes += \strlen((string)$value);
            }
        }

        return $bytes;
    }

    private function markCacheHit(Response $response): Response
    {
        WelineEnv::set('response.from_cache', true, 'FullPageCacheCoordinator cache hit');

        return $response;
    }

    private function getUnifiedCacheKey(string $method): string
    {
        return KeyBuilder::buildUnifiedRequestCacheKey($this->getCacheKeyFullUri(), $method);
    }

    private function getLegacyCacheKey(): ?string
    {
        $fullUri = $this->getCacheKeyFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return null;
        }

        return KeyBuilder::build('router', 'fpc:' . $fullUri . ':' . Cookie::getLangLocal());
    }

    private function getBuildLockKey(string $method): string
    {
        return KeyBuilder::build('router', 'fpc-lock:' . $this->getCacheKeyFullUri() . ':' . $method);
    }

    private function deleteCurrentCacheEntries(string $method): void
    {
        try {
            $unifiedCacheKey = $this->getUnifiedCacheKey($method);
            $this->cache()->delete($unifiedCacheKey);
            $this->deleteProcessCachedPayload($unifiedCacheKey);
            $legacyKey = $this->getLegacyCacheKey();
            if ($legacyKey !== null) {
                $this->cache()->delete($legacyKey);
            }
        } catch (\Throwable $throwable) {
            $this->logFpcWarning('delete current cache entries failed', [
                'error' => $throwable->getMessage(),
                'cache_key_full_uri' => $this->getCacheKeyFullUri(),
                'method' => $method,
            ]);
        }
    }

    private function bodyContainsIgnorableHtmlUrlQuery(string $body): bool
    {
        foreach ($this->extractHtmlUrlAttributeValues($body) as $url) {
            if ($this->urlContainsIgnorableQuery($url)) {
                return true;
            }
        }

        return false;
    }

    private function bodyContainsRawIgnorableRequestQuery(string $body): bool
    {
        if (!$this->requestUsesCanonicalizedCacheKey()) {
            return false;
        }

        $query = (string)(\parse_url($this->getRawFullUri(), \PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return false;
        }

        $segments = \preg_split('/[&;]/', $query, -1, \PREG_SPLIT_NO_EMPTY);
        if ($segments === false) {
            return false;
        }

        foreach ($segments as $segment) {
            $rawKey = \explode('=', $segment, 2)[0] ?? '';
            if (!$this->isIgnorableCacheQueryParam((string)\urldecode($rawKey))) {
                continue;
            }

            if (\str_contains($body, $segment)
                || \str_contains($body, \htmlspecialchars($segment, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractHtmlUrlAttributeValues(string $body): array
    {
        if ($body === '' || !\preg_match_all('/<[^>]+>/i', $body, $matches)) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $tag) {
            $attributes = $this->parseHtmlTagAttributes((string)$tag);
            foreach (['href', 'src', 'action', 'content'] as $attribute) {
                if (isset($attributes[$attribute])) {
                    $urls[] = (string)$attributes[$attribute];
                }
            }
        }

        return $urls;
    }

    private function urlContainsIgnorableQuery(string $url): bool
    {
        $query = (string)(\parse_url($url, \PHP_URL_QUERY) ?: '');
        if ($query === '') {
            return false;
        }

        \parse_str($query, $params);
        foreach (\array_keys($params) as $key) {
            if ($this->isIgnorableCacheQueryParam((string)$key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function parseHtmlTagAttributes(string $tag): array
    {
        if (!\preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/', $tag, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $attributes = [];
        foreach ($matches as $match) {
            $name = \strtolower((string)$match[1]);
            $value = (string)$match[2];
            $quote = $value[0] ?? '';
            if (($quote === '"' || $quote === "'") && \substr($value, -1) === $quote) {
                $value = \substr($value, 1, -1);
            }
            $attributes[$name] = \html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        }

        return $attributes;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logFpcWarning(string $message, array $context = []): void
    {
        $context += [
            'request_id' => (string)(RequestContext::get('request_id', '') ?: WelineEnv::get('request_id', '')),
        ];
        if (\function_exists('w_log_warning')) {
            \w_log_warning('[FPC] ' . $message, $context, 'wls_fpc');
            return;
        }

        \error_log('[FPC] ' . $message . ' ' . \json_encode($context, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }

    private function getRawFullUri(): string
    {
        return (string)(\w_env('full_request_uri', '') ?? '');
    }

    private function getCacheKeyFullUri(): string
    {
        $fullUri = $this->getRawFullUri();
        if ($fullUri === '' || !\str_contains($fullUri, '?')) {
            return $fullUri;
        }

        $parts = \parse_url($fullUri);
        if (!\is_array($parts) || ($parts['scheme'] ?? '') === '' || ($parts['host'] ?? '') === '') {
            return $fullUri;
        }

        $query = (string)($parts['query'] ?? '');
        if ($query === '') {
            return $fullUri;
        }

        $filteredQuery = $this->filterQueryStringForCacheKey($query);
        if ($filteredQuery === $query) {
            return $fullUri;
        }

        return $this->buildFullUriFromParts($parts, $filteredQuery);
    }

    private function requestUsesCanonicalizedCacheKey(): bool
    {
        $rawFullUri = $this->getRawFullUri();
        if ($rawFullUri === '') {
            return false;
        }

        return $rawFullUri !== $this->getCacheKeyFullUri();
    }

    private function canonicalizeCurrentRequestForCacheBuild(): void
    {
        $rawFullUri = $this->getRawFullUri();
        $cacheKeyFullUri = $this->getCacheKeyFullUri();
        if ($rawFullUri === '' || $rawFullUri === $cacheKeyFullUri) {
            return;
        }

        $parts = \parse_url($cacheKeyFullUri);
        if (!\is_array($parts)) {
            return;
        }

        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = (string)($parts['query'] ?? '');
        $requestUri = $path . ($query !== '' ? '?' . $query : '');
        $originalGet = (array)WelineEnv::getGet(null, []);
        $filteredGet = $this->filterGetParamsForCacheKey($originalGet);

        $_SERVER['REQUEST_URI'] = $requestUri;
        $_SERVER['QUERY_STRING'] = $query;
        $_SERVER['WELINE_ORIGIN_REQUEST_URI'] = $requestUri;
        $_SERVER['WELINE_FULL_REQUEST_URI'] = $cacheKeyFullUri;
        $_GET = $filteredGet;

        WelineEnv::setServer('REQUEST_URI', $requestUri, 'FPC canonical cache build');
        WelineEnv::setServer('QUERY_STRING', $query, 'FPC canonical cache build');
        WelineEnv::setServer('WELINE_ORIGIN_REQUEST_URI', $requestUri, 'FPC canonical cache build');
        WelineEnv::setServer('WELINE_FULL_REQUEST_URI', $cacheKeyFullUri, 'FPC canonical cache build');
        WelineEnv::set('request.uri', $requestUri, 'FPC canonical cache build');
        WelineEnv::set('request.query_string', $query, 'FPC canonical cache build');
        WelineEnv::set('origin_request_uri', $requestUri, 'FPC canonical cache build');
        WelineEnv::set('full_request_uri', $cacheKeyFullUri, 'FPC canonical cache build');
        WelineEnv::replaceGet($filteredGet);

        RequestContext::set('fpc.canonicalized_request', true);
        RequestContext::set('fpc.raw_full_uri', $rawFullUri);
        RequestContext::set('fpc.cache_key_full_uri', $cacheKeyFullUri);

        try {
            $request = ObjectManager::getInstance(\Weline\Framework\Http\Request::class);
            if (\method_exists($request, 'invalidateUriCache')) {
                $request->invalidateUriCache();
            }
            if (\method_exists($request, 'resetParameterBag')) {
                $request->resetParameterBag();
            }

            $removedKeys = $this->getIgnorableGetParamKeys($originalGet);
            if (\method_exists($request, 'unsetData')) {
                $request->unsetData(\array_merge($removedKeys, ['params']));
            }
        } catch (\Throwable) {
            // Request may not be available in early observer contexts; Context/Env updates above are sufficient.
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function filterGetParamsForCacheKey(array $params): array
    {
        foreach (\array_keys($params) as $key) {
            if ($this->isIgnorableCacheQueryParam((string)$key)) {
                unset($params[$key]);
            }
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>
     */
    private function getIgnorableGetParamKeys(array $params): array
    {
        $keys = [];
        foreach (\array_keys($params) as $key) {
            $key = (string)$key;
            if ($this->isIgnorableCacheQueryParam($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function filterQueryStringForCacheKey(string $query): string
    {
        $segments = \preg_split('/[&;]/', $query, -1, \PREG_SPLIT_NO_EMPTY);
        if ($segments === false || $segments === []) {
            return '';
        }

        $kept = [];
        foreach ($segments as $segment) {
            $rawKey = \explode('=', $segment, 2)[0] ?? '';
            if ($this->isIgnorableCacheQueryParam((string)\urldecode($rawKey))) {
                continue;
            }

            $kept[] = $segment;
        }

        return \implode('&', $kept);
    }

    private function isIgnorableCacheQueryParam(string $key): bool
    {
        $key = \strtolower(\trim($key));
        if ($key === '') {
            return false;
        }

        if (self::IGNORABLE_QUERY_PARAM_KEYS[$key] ?? false) {
            return true;
        }

        foreach (self::IGNORABLE_QUERY_PARAM_PREFIXES as $prefix) {
            if (\str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int|string> $parts
     */
    private function buildFullUriFromParts(array $parts, string $query): string
    {
        $uri = (string)$parts['scheme'] . '://';
        if (isset($parts['user'])) {
            $uri .= (string)$parts['user'];
            if (isset($parts['pass'])) {
                $uri .= ':' . (string)$parts['pass'];
            }
            $uri .= '@';
        }

        $uri .= (string)$parts['host'];
        if (isset($parts['port'])) {
            $uri .= ':' . (string)$parts['port'];
        }

        $path = (string)($parts['path'] ?? '/');
        $uri .= $path === '' ? '/' : $path;
        if ($query !== '') {
            $uri .= '?' . $query;
        }

        return $uri;
    }

    private function cache(): CachePoolInterface
    {
        if ($this->cachePool === null) {
            $this->cachePool = $this->cacheManager()->pool('router');
        }

        return $this->cachePool;
    }

    private function cacheManager(): CacheManager
    {
        if ($this->cacheManager === null) {
            $this->cacheManager = ObjectManager::getInstance(CacheManager::class);
        }

        return $this->cacheManager;
    }

    private function resolveWlsMemoryAdapter(): ?WlsMemoryAdapter
    {
        $cache = $this->cache();
        if (!\method_exists($cache, 'getAdapter')) {
            return null;
        }

        $adapter = $cache->getAdapter();
        return $adapter instanceof WlsMemoryAdapter ? $adapter : null;
    }

    /**
     * @return resource|null
     */
    private function acquireFileLock(string $lockKey)
    {
        $lockDir = BP . 'var' . \DIRECTORY_SEPARATOR . 'cache' . \DIRECTORY_SEPARATOR . 'router-fpc-locks';
        if (!\is_dir($lockDir) && !@\mkdir($lockDir, 0775, true) && !\is_dir($lockDir)) {
            return null;
        }

        $lockFile = $lockDir . \DIRECTORY_SEPARATOR . \hash('sha256', $lockKey) . '.lock';
        $handle = @\fopen($lockFile, 'c+');
        if ($handle === false) {
            return null;
        }

        if (!@\flock($handle, \LOCK_EX | \LOCK_NB)) {
            @\fclose($handle);
            return null;
        }

        return $handle;
    }

    private function generateLockToken(): string
    {
        try {
            return \bin2hex(\random_bytes(16));
        } catch (\Throwable) {
            return \md5(\uniqid('fpc-lock', true));
        }
    }
}
