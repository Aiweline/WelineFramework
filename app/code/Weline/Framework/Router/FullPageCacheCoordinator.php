<?php

declare(strict_types=1);

namespace Weline\Framework\Router;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\Contract\AtomicCacheAdapterInterface;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Cache\Namespace\NamespaceGenerationRepository;
use Weline\Framework\Cache\Namespace\NamespaceKeyDecorator;
use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Cache\StorefrontCacheKeyContext;
use Weline\Framework\Cache\StorefrontCacheKeyContextResolver;
use Weline\Framework\App\Env;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Env\WelineEnv;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Security\SecurityHeaderPolicyService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\InternalHomepagePrime;
use Weline\Framework\Runtime\Runtime;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\SchedulerSystem;
use Weline\Framework\Runtime\StorefrontScopeInstallerInterface;
use Weline\Framework\Session\Auth\AreaConfig;
use Weline\Framework\Session\SessionCookieNameResolver;
use Weline\Framework\Session\SessionFactory;

final class FullPageCacheCoordinator
{
    private const LOCK_TTL_SECONDS = 15;
    private const LOCK_WAIT_TIMEOUT_MS = 50;
    private const PERSISTENT_LOCK_WAIT_TIMEOUT_MS = 0;
    private const LOCK_WAIT_STEP_MS = 20;
    private const UNIFIED_CACHE_FPC_GZIP_B64_KEY = 'fpc_gzip_b64';
    private const UNIFIED_CACHE_FPC_BODY_FILE_KEY = 'fpc_body_file';
    private const UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY = 'fpc_html_urls_validated';
    private const UNIFIED_CACHE_EXPIRES_AT_KEY = 'fpc_expires_at';
    private const GZIP_CACHE_MIN_BODY_BYTES = 1024;
    private const FAST_HTTP_GZIP_KEEPALIVE_SUFFIX = ':http:gzip:keepalive:v1';
    private const STALE_CACHE_SUFFIX = ':stale:v1';
    private const SCHEMA_NEUTRAL_STALE_CACHE_PREFIX = 'unified-fpc-schema-neutral-stale:';
    private const DEFAULT_STALE_TTL_SECONDS = 86400;
    private const DEFAULT_PRIVATE_SESSION_TTL_SECONDS = 300;
    private const PROCESS_FPC_TTL_SECONDS = 3600;
    private const PROCESS_FPC_MAX_ITEMS = 128;
    private const PROCESS_FPC_MAX_BYTES = 33554432;
    private const PROCESS_FORMATTED_FPC_MAX_ITEMS = 192;
    private const PROCESS_FORMATTED_FPC_MAX_BYTES = 16777216;
    private const FRONTEND_LOGIN_SESSION_POSITIVE_TTL_SECONDS = 1.0;
    private const FRONTEND_LOGIN_SESSION_CACHE_MAX_ITEMS = 1024;
    private const INTERNAL_HOMEPAGE_RECEIPT_CONTEXT_KEY = 'wls.fpc.internal_homepage_receipt';
    private const DEFAULT_LANG = 'zh_Hans_CN';
    private const DEFAULT_CURRENCY = 'CNY';
    private const VARIANT_PAYLOAD_KEY = 'fpc_variant';
    private const FPC_CACHE_SCHEMA_VERSION = '20260722-scope-variant-v2';

    /**
     * @var array<string, bool>
     */
    private const IGNORABLE_QUERY_PARAM_KEYS = [
        '_' => true,
        'ai_perf' => true,
        'browser_perf' => true,
        'codex_perf' => true,
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
        'codex_',
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
        'x-frame-options' => true,
        'x-content-type-options' => true,
        'x-xss-protection' => true,
        'content-security-policy' => true,
        'content-security-policy-report-only' => true,
        'access-control-allow-origin' => true,
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
    private ?NamespaceKeyDecorator $namespaceKeyDecorator;
    private ?RuntimeProviderResolver $runtimeProviderResolver;
    private ?StorefrontCacheKeyContextResolver $storefrontCacheKeyContextResolver;
    private ?RuntimeProviderResolution $storefrontProviderResolution = null;

    /** @var array<string, array<string, mixed>> */
    private static array $processFpcPayloadCache = [];

    /** @var array<string, float> */
    private static array $processFpcPayloadExpiresAt = [];

    /** @var array<string, int> */
    private static array $processFpcPayloadBytes = [];

    private static int $processFpcPayloadTotalBytes = 0;

    /** @var array<string, string> */
    private static array $processFormattedFpcCache = [];

    /** @var array<string, float> */
    private static array $processFormattedFpcExpiresAt = [];

    /** @var array<string, int> */
    private static array $processFormattedFpcBytes = [];

    private static int $processFormattedFpcTotalBytes = 0;

    /** @var array<string, bool> */
    private static array $frontendLoginSessionCache = [];

    /** @var array<string, float> */
    private static array $frontendLoginSessionCacheExpiresAt = [];

    public function __construct(
        ?CacheManager $cacheManager = null,
        ?CachePoolInterface $cachePool = null,
        ?NamespaceGenerationRepository $namespaceGenerationRepository = null,
        ?NamespaceKeyDecorator $namespaceKeyDecorator = null,
        ?NamespacePath $namespacePath = null,
        ?RuntimeProviderResolver $runtimeProviderResolver = null,
        ?StorefrontCacheKeyContextResolver $storefrontCacheKeyContextResolver = null,
    ) {
        $this->cacheManager = $cacheManager;
        $this->cachePool = $cachePool;
        $this->namespaceKeyDecorator = $namespaceKeyDecorator;
        $this->runtimeProviderResolver = $runtimeProviderResolver;
        $this->storefrontCacheKeyContextResolver = $storefrontCacheKeyContextResolver
            ?? ($namespaceGenerationRepository instanceof NamespaceGenerationRepository
                && $namespacePath instanceof NamespacePath
                ? new StorefrontCacheKeyContextResolver($namespaceGenerationRepository, $namespacePath)
                : null);
    }

    public function getCachedResponse(string $method = 'GET', bool $processOnly = false): ?Response
    {
        if (!$this->canServeCachedResponse($method)) {
            return null;
        }

        $response = $this->getUnifiedCachedResponse($method, $processOnly);
        if ($response !== null) {
            return $this->markCacheHit($response);
        }

        return null;
    }

    /**
     * @return array{driver:string,key:string,token?:string,handle?:resource}|null
     */
    public function acquireBuildLock(string $method = 'GET'): ?array
    {
        if (!$this->canBuildCachedResponse($method)) {
            return null;
        }

        $method = $this->normalizeBuildMethod($method);
        $fullUri = $this->getCacheKeyFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return null;
        }

        $lockKey = $this->getBuildLockKey($method);
        // The shared-state backend currently applies TTL at namespace scope.
        // Keeping the short-lived build lock in cache:router would let the
        // one-second release tombstone expire every route/FPC entry in that
        // namespace. Isolate locks in the dedicated single-flight pool so
        // cache payload lifetime can never be shortened by lock lifecycle.
        $adapter = $this->resolveAtomicCacheAdapter('single_flight');
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
        $method = $this->normalizeCacheMethod($method);
        $timeoutMs = $this->resolvePublishedResponseWaitTimeoutMs($timeoutMs);
        if ($timeoutMs <= 0) {
            return $this->getCachedResponse($method, true) ?? $this->getStaleCachedResponse($method);
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

        $response = $this->getCachedResponse($method) ?? $this->getStaleCachedResponse($method);
        if ($response === null && $this->internalFpcWarmupMode() === 'prime') {
            throw new \RuntimeException('Homepage warmup peer timed out waiting for the shared FPC publisher.');
        }

        return $response;
    }

    private function resolvePublishedResponseWaitTimeoutMs(int $timeoutMs): int
    {
        if ($timeoutMs === self::LOCK_WAIT_TIMEOUT_MS && $this->internalFpcWarmupMode() === 'prime') {
            return \max(250, \min(10000, (int)Env::get('wls.worker.homepage_warmup_peer_wait_ms', 3000)));
        }
        if ($timeoutMs !== self::LOCK_WAIT_TIMEOUT_MS || !Runtime::isPersistent()) {
            return \max(0, $timeoutMs);
        }

        $configured = (int)Env::get(
            'wls.performance.fpc_build_wait_timeout_ms',
            self::PERSISTENT_LOCK_WAIT_TIMEOUT_MS
        );

        return \min(\max(0, $configured), 250);
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
            $adapter = $this->resolveAtomicCacheAdapter('single_flight');
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
        $method = $this->normalizeBuildMethod($method);
        if (!$this->canPublishResponse($response, $method)) {
            return;
        }

        $variant = null;
        if ($this->isFrontendResponseCacheAllowed($method)) {
            $variant = $this->buildCurrentFpcVariant();
            $response->setHeader('X-Wls-Performance-Fpc-Variant', $this->variantDebugToken($variant));
            $this->ensureVaryHeader($response, 'Cookie');
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

        if ($variant === null) {
            $variant = $this->buildCurrentFpcVariant();
            $response->setHeader('X-Wls-Performance-Fpc-Variant', $this->variantDebugToken($variant));
            $this->ensureVaryHeader($response, 'Cookie');
        }

        self::cooperativeBuildYield();
        // This key is owned exclusively by the response FPC. Route, rule and
        // generated-parameter caches have independent keys in Router\Core and
        // are never consumed while serving an FPC hit. Duplicating those data
        // structures here made a small homepage response exceed the bounded
        // shared-memory frame (1.6MB on Windows), delaying startup and leaving
        // followers without a publishable homepage. Keep the shared response
        // payload compact and let the dedicated route caches own route data.
        $payload = [
            KeyBuilder::UNIFIED_CACHE_FPC_KEY => $body,
            KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => $this->sanitizeHeaders($response->getHeaders()),
            KeyBuilder::UNIFIED_CACHE_STATUS_KEY => $response->getStatusCode(),
            self::VARIANT_PAYLOAD_KEY => $variant,
            self::UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY => true,
        ];

        self::cooperativeBuildYield();
        $gzipBody = $this->buildCachedGzipBody($body);
        if ($gzipBody !== null) {
            $payload[self::UNIFIED_CACHE_FPC_GZIP_B64_KEY] = \base64_encode($gzipBody);
        }

        $ttl = $this->privateSessionTokenFromVariant($variant) !== ''
            ? $this->privateSessionFpcTtlSeconds()
            : 3600;
        $payload[self::UNIFIED_CACHE_EXPIRES_AT_KEY] = \microtime(true) + $ttl;
        $unifiedCacheKey = $this->getUnifiedCacheKey($method);
        self::cooperativeBuildYield();
        $sharedPublished = $this->cache()->set(
            $unifiedCacheKey,
            $this->externalizeSharedPayload($unifiedCacheKey, $payload),
            $ttl
        );
        if (!$sharedPublished && InternalHomepagePrime::isCurrentRequest()) {
            throw new \RuntimeException('Homepage warmup could not publish the compact shared FPC payload.');
        }
        if ($this->privateSessionTokenFromVariant($variant) === '' && $this->shouldPublishSharedStalePayload($body)) {
            self::cooperativeBuildYield();
            $staleCacheKey = $this->buildStaleCacheKey($unifiedCacheKey);
            $staleTtl = $this->staleTtlSeconds();
            $this->cache()->set($staleCacheKey, $this->externalizeSharedPayload($staleCacheKey, $payload), $staleTtl);
            self::cooperativeBuildYield();
            $schemaNeutralStaleKey = $this->buildSchemaNeutralStaleCacheKey($fullUri, $method, $variant);
            $this->cache()->set(
                $schemaNeutralStaleKey,
                $this->externalizeSharedPayload($schemaNeutralStaleKey, $payload),
                $staleTtl
            );
        }
        $this->setProcessCachedPayload($unifiedCacheKey, $payload);
        if (InternalHomepagePrime::isCurrentRequest()) {
            RequestContext::set(
                self::INTERNAL_HOMEPAGE_RECEIPT_CONTEXT_KEY,
                $this->buildInternalHomepageWarmupReceipt($fullUri, $variant, $unifiedCacheKey)
            );
        }
        self::cooperativeBuildYield();
        $formattedGzip = $this->buildFormattedGzipKeepAliveResponse($payload, 'shared');
        if ($formattedGzip !== null) {
            $formattedKey = $this->buildFormattedFastHttpCacheKey($unifiedCacheKey);
            $this->setProcessCachedFormattedResponse($formattedKey, $formattedGzip, $ttl);
            if ($this->shouldPublishSharedFormattedResponse($formattedGzip)) {
                self::cooperativeBuildYield();
                $this->cache()->set($formattedKey, $formattedGzip, $ttl);
            }
        }
    }

    private static function cooperativeBuildYield(): void
    {
        if (!(bool)Env::get('wls.performance.fpc_cooperative_yield_enabled', false)) {
            return;
        }

        if (!Runtime::isPersistent() || !SchedulerSystem::isSchedulerActive() || !\Fiber::getCurrent()) {
            return;
        }

        static $fiberYieldAt = null;
        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return;
        }
        if (!$fiberYieldAt instanceof \WeakMap) {
            $fiberYieldAt = new \WeakMap();
        }

        $now = \microtime(true);
        $lastYieldAt = (float)($fiberYieldAt[$fiber] ?? 0.0);
        if ($lastYieldAt <= 0.0) {
            $fiberYieldAt[$fiber] = $now;
            return;
        }
        if (($now - $lastYieldAt) < 0.01) {
            return;
        }

        $fiberYieldAt[$fiber] = $now;
        SchedulerSystem::yield();
    }

    public function canServeCachedResponse(string $method = 'GET'): bool
    {
        $warmupMode = $this->internalFpcWarmupMode();
        if (($this->shouldBypassForDynamicFirstRender()
                && $warmupMode !== 'prime')
            || $this->shouldBypassCachedResponseForClientCacheControl()
        ) {
            return false;
        }

        if (!$this->isFrontendResponseCacheAllowed($method)) {
            return false;
        }

        return !$this->hasLoggedInFrontendSession() && $this->hasCompleteFrozenStorefrontScope();
    }

    public function canBuildCachedResponse(string $method = 'GET'): bool
    {
        if (($this->shouldBypassForDynamicFirstRender() && $this->internalFpcWarmupMode() !== 'prime')
            || $this->shouldSkipCacheBuildForClientCacheControl()
        ) {
            return false;
        }

        return $this->normalizeHttpMethod($method) === 'GET'
            && $this->isFrontendResponseCacheAllowed($method)
            && !$this->hasLoggedInFrontendSession()
            && $this->hasCompleteFrozenStorefrontScope();
    }

    public function canPublishResponse(Response $response, string $method = 'GET'): bool
    {
        if (!$this->canBuildCachedResponse($method)) {
            return false;
        }

        if ($response->getStatusCode() !== 200 || $response->getBody() === '') {
            return false;
        }

        return $this->responseAllowsSharedPageCache($response);
    }

    public function canUsePrivateSessionCachedResponse(): bool
    {
        return false;
    }

    public function shouldBypassForDynamicFirstRender(): bool
    {
        // READY-gate warmup runs before the public accept loop and may execute
        // on the persistent Worker main thread. WelineEnv intentionally rejects
        // persistent main-thread fallback to avoid cross-request globals, so
        // read the immutable active request Context first (the same invariant
        // used by InternalHomepagePrime) and only then use the normal accessor.
        $contextServer = Context::getCurrent()?->server();
        foreach ([
            'WLS_FPC_BYPASS',
            'WLS_INTERNAL_DYNAMIC_WARMUP',
            'HTTP_X_WLS_FPC_BYPASS',
            'HTTP_X_WLS_INTERNAL_FPC_BYPASS',
            'HTTP_X_WLS_DYNAMIC_WARMUP',
            'HTTP_X_WLS_INTERNAL_DYNAMIC_WARMUP',
            'HTTP_X_WLS_DYNAMIC_BENCHMARK',
            'HTTP_X_WLS_FPC_PRIME',
            // Defensive aliases accepted by WorkerFullPageCacheFastPath.
            'HTTP_WLS_FPC_BYPASS',
            'HTTP_WLS_INTERNAL_DYNAMIC_WARMUP',
            'HTTP_HTTP_X_WLS_FPC_BYPASS',
            'HTTP_HTTP_X_WLS_DYNAMIC_WARMUP',
            'HTTP_HTTP_X_WLS_DYNAMIC_BENCHMARK',
        ] as $key) {
            $flag = \is_array($contextServer) && \array_key_exists($key, $contextServer)
                ? $contextServer[$key]
                : WelineEnv::server($key, null);
            if (\is_scalar($flag)
                && \in_array(\strtolower(\trim((string)$flag)), ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
        }

        return false;
    }

    private function internalFpcWarmupMode(): string
    {
        return InternalHomepagePrime::isCurrentRequest() ? 'prime' : '';
    }

    public function shouldBypassCachedResponseForClientCacheControl(): bool
    {
        return $this->clientCacheControlRequestsBypass()
            || $this->clientPragmaRequestsNoCache();
    }

    private function shouldSkipCacheBuildForClientCacheControl(): bool
    {
        return $this->clientCacheControlRequestsNoStore();
    }

    private function clientCacheControlRequestsBypass(): bool
    {
        $cacheControl = $this->normalizedRequestHeader('HTTP_CACHE_CONTROL');
        if ($cacheControl === '') {
            return false;
        }

        foreach (\explode(',', $cacheControl) as $directive) {
            $directive = \trim($directive);
            if ($directive === 'no-cache'
                || \str_starts_with($directive, 'no-cache=')
                || $directive === 'no-store'
                || \str_starts_with($directive, 'no-store=')
                || \preg_match('/^max-age\s*=\s*0+$/', $directive) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function clientCacheControlRequestsNoStore(): bool
    {
        $cacheControl = $this->normalizedRequestHeader('HTTP_CACHE_CONTROL');
        if ($cacheControl === '') {
            return false;
        }

        foreach (\explode(',', $cacheControl) as $directive) {
            $directive = \trim($directive);
            if ($directive === 'no-store' || \str_starts_with($directive, 'no-store=')) {
                return true;
            }
        }

        return false;
    }

    private function clientPragmaRequestsNoCache(): bool
    {
        $pragma = $this->normalizedRequestHeader('HTTP_PRAGMA');
        if ($pragma === '') {
            return false;
        }

        foreach (\explode(',', $pragma) as $directive) {
            if (\trim($directive) === 'no-cache') {
                return true;
            }
        }

        return false;
    }

    private function normalizedRequestHeader(string $serverKey): string
    {
        $value = WelineEnv::server($serverKey, '');
        if (!\is_scalar($value)) {
            return '';
        }

        return \strtolower(\trim((string)$value));
    }

    public function hasLoggedInFrontendSessionForCache(): bool
    {
        return $this->hasLoggedInFrontendSession();
    }

    public static function clearProcessCache(): void
    {
        self::$processFpcPayloadCache = [];
        self::$processFpcPayloadExpiresAt = [];
        self::$processFpcPayloadBytes = [];
        self::$processFpcPayloadTotalBytes = 0;
        self::$processFormattedFpcCache = [];
        self::$processFormattedFpcExpiresAt = [];
        self::$processFormattedFpcBytes = [];
        self::$processFormattedFpcTotalBytes = 0;
        self::$frontendLoginSessionCache = [];
        self::$frontendLoginSessionCacheExpiresAt = [];
    }

    private function getStaleCachedResponse(string $method): ?Response
    {
        if (!$this->canServeCachedResponse($method)) {
            return null;
        }
        if ($this->internalFpcWarmupMode() === 'prime') {
            return null;
        }
        if ($this->canUsePrivateSessionFpc()) {
            return null;
        }

        $freshCacheKey = $this->getUnifiedCacheKey($method);
        $staleCacheKey = $this->buildStaleCacheKey($freshCacheKey);
        $cached = $this->getProcessCachedPayload($staleCacheKey);
        $cacheSource = 'stale-process';
        $cachePayloadFetched = false;
        if ($cached === null) {
            $cached = $this->cache()->get($staleCacheKey);
            $cachePayloadFetched = \is_array($cached);
            $cacheSource = $cachePayloadFetched ? 'stale-shared' : 'stale-miss';
            if ($cachePayloadFetched) {
                $cached = $this->hydrateSharedPayload($cached);
            }
        }
        if (!\is_array($cached)) {
            $schemaNeutralStaleKey = $this->buildSchemaNeutralStaleCacheKey(
                $this->getCacheKeyFullUri(),
                $method,
                $this->buildCurrentFpcVariant()
            );
            $cached = $this->getProcessCachedPayload($schemaNeutralStaleKey);
            $cacheSource = 'stale-schema-neutral-process';
            if ($cached === null) {
                $cached = $this->cache()->get($schemaNeutralStaleKey);
                $cachePayloadFetched = \is_array($cached);
                $cacheSource = $cachePayloadFetched ? 'stale-schema-neutral-shared' : 'stale-schema-neutral-miss';
                if ($cachePayloadFetched) {
                    $cached = $this->hydrateSharedPayload($cached);
                }
            }
            if (\is_array($cached)) {
                $staleCacheKey = $schemaNeutralStaleKey;
            }
        }
        if (!\is_array($cached)) {
            return null;
        }

        $body = (string)($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? '');
        if ($body === '') {
            return null;
        }

        $cached = $this->prepareCachedPayloadForFrameworkHit(
            $staleCacheKey,
            $cached,
            $body,
            false
        );
        if ($cached === null) {
            return null;
        }
        if ($cachePayloadFetched) {
            $this->setProcessCachedPayload($staleCacheKey, $cached);
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
        $response->setHeader('X-Weline-FPC', 'STALE');
        $response->setHeader('X-Wls-Performance-Fpc-Hit', '1');
        $response->setHeader('X-Wls-Performance-Fpc-Stale', '1');
        $response->setHeader('X-Wls-Performance-Fpc-Source', $cacheSource);
        $response->setHeader('X-Wls-Performance-Fpc-Variant', $this->variantDebugToken($this->buildCurrentFpcVariant()));
        $response->setHeader('X-Wls-Performance-Urlparser', '0');
        $response->setHeader('X-Wls-Performance-Urlparserapply', '0');
        $this->ensureVaryHeader($response, 'Cookie');
        RequestContext::set('wls.fpc.hit_source', $cacheSource);
        RequestContext::set('wls.fpc.stale', true);

        return $this->markCacheHit($response);
    }

    public function getStaleCachedResponseForRebuild(string $method = 'GET'): ?Response
    {
        return $this->getStaleCachedResponse($this->normalizeCacheMethod($method));
    }

    /**
     * Rehydrates the anonymous process cache from the fresh shared key.
     * forceSharedRead never falls back to stale data and never renders.
     */
    public function warmProcessCacheForFullUri(
        string $fullUri,
        string $method = 'GET',
        string $cookieHeader = '',
        bool $forceSharedRead = false
    ): bool
    {
        $fullUri = $this->canonicalizeFullUriForCacheKey(\trim($fullUri));
        $method = $this->normalizeCacheMethod(\trim($method) ?: 'GET');
        if ($method !== 'GET'
            || !KeyBuilder::isValidFullPageCacheKey($fullUri)
            || $this->isEditorOrPreviewRequest($fullUri)
            || $this->isExcludedFrontendPath($fullUri)
            || $this->cookieHeaderHasLoggedInFrontendSession($cookieHeader, $fullUri)
            || !$this->legacyPreRouterFpcAllowed()
        ) {
            return false;
        }

        $variant = $this->buildFpcVariantFromCookieHeader($cookieHeader, $fullUri);
        $cacheKey = $this->buildUnifiedFpcCacheKey($fullUri, $method, $variant);
        if (!$forceSharedRead && $this->getProcessCachedPayload($cacheKey) !== null) {
            return true;
        }

        $cached = $this->cache()->get($cacheKey);
        if (\is_array($cached)) {
            $cached = $this->hydrateSharedPayload($cached);
        }
        $body = \is_array($cached) ? ($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? null) : null;
        if (!\is_string($body) || $body === '') {
            // SharedState 短断或单次读失败时保留仍在自身 TTL 内的 process FPC；
            // 显式 cache-clear IPC 仍会立即清理进程缓存，不用一次瞬时故障制造下一个冷请求。
            return false;
        }

        $this->setProcessCachedPayload($cacheKey, $cached);
        $this->deleteProcessCachedFormattedResponse($this->buildFormattedFastHttpCacheKey($cacheKey));
        return $this->getProcessCachedPayload($cacheKey) !== null;
    }

    /**
     * Rehydrate the exact anonymous FPC entry published by an internal
     * homepage prime. Unlike the public pre-router fast path, this method does
     * not reconstruct Store/Channel scope from raw cookies: it accepts only a
     * receipt whose cache key matches its SHA-256 identity digest.
     *
     * @param array<string, mixed> $receipt
     */
    public function warmProcessCacheForInternalReceipt(
        array $receipt,
        bool $forceSharedRead = false
    ): bool {
        $cacheKey = $this->internalHomepageReceiptCacheKey($receipt);
        if ($cacheKey === null) {
            return false;
        }
        if (!$forceSharedRead && $this->getProcessCachedPayload($cacheKey) !== null) {
            return true;
        }

        $cached = $this->cache()->get($cacheKey);
        if (\is_array($cached)) {
            $cached = $this->hydrateSharedPayload($cached);
        }
        $body = \is_array($cached) ? ($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? null) : null;
        if (!\is_string($body) || $body === '') {
            return false;
        }

        $cached = $this->prepareCachedPayloadForFastHit($cacheKey, $cached, $body, true);
        if ($cached === null) {
            return false;
        }

        $this->setProcessCachedPayload($cacheKey, $cached);
        $this->deleteProcessCachedFormattedResponse($this->buildFormattedFastHttpCacheKey($cacheKey));
        return $this->getProcessCachedPayload($cacheKey) !== null;
    }

    /**
     * Format only the process-local entry identified by an internal receipt.
     * It never falls back to Shared L2, stale data, rendering, or a public
     * pre-router scope guess, so READY proves the preceding exact rehydrate.
     *
     * @param array<string, mixed> $receipt
     * @return array{response:string,source:string,bytes:int}|null
     */
    public function getFormattedProcessCachedResponseForInternalReceipt(
        array $receipt,
        bool $keepAlive = false
    ): ?array {
        $cacheKey = $this->internalHomepageReceiptCacheKey($receipt);
        if ($cacheKey === null) {
            return null;
        }

        $cached = $this->getProcessCachedPayload($cacheKey);
        if (!\is_array($cached)) {
            return null;
        }
        $body = (string)($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? '');
        if ($body === '') {
            return null;
        }
        $cached = $this->prepareCachedPayloadForFastHit($cacheKey, $cached, $body, false);
        if ($cached === null) {
            return null;
        }

        $variant = $cached[self::VARIANT_PAYLOAD_KEY] ?? [];
        if (!\is_array($variant)) {
            return null;
        }
        $statusCode = (int)($cached[KeyBuilder::UNIFIED_CACHE_STATUS_KEY] ?? 200);
        $response = Response::fromContent($body, $statusCode, 'text/html; charset=utf-8');
        $this->applyCachedHeaders($response, $cached[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY] ?? []);
        $response->setHeader('X-Weline-FPC', 'HIT');
        $response->setHeader('X-Wls-Performance-Fpc-Hit', '1');
        $response->setHeader('X-Wls-Performance-Fpc-Source', 'process');
        $response->setHeader('X-Wls-Performance-Fpc-Variant', $this->variantDebugToken($variant));
        $response->setHeader('X-Wls-Performance-Urlparser', '0');
        $response->setHeader('X-Wls-Performance-Urlparserapply', '0');
        $this->ensureVaryHeader($response, 'Cookie');
        $response->markTelemetryPrepared();

        return [
            'response' => $response->toHttpString($keepAlive),
            'source' => 'process',
            'bytes' => \strlen($body),
        ];
    }

    /**
     * Return the exact anonymous FPC identity used by the current homepage
     * prime. Public requests can never obtain this receipt: the predicate
     * requires transport-injected server markers which HTTP headers cannot
     * create on their own.
     *
     * @return array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string,cache_key:string}|null
     */
    public function currentInternalHomepageWarmupReceipt(): ?array
    {
        if (!InternalHomepagePrime::isCurrentRequest()) {
            return null;
        }

        $publishedReceipt = RequestContext::get(self::INTERNAL_HOMEPAGE_RECEIPT_CONTEXT_KEY);
        if (\is_array($publishedReceipt)
            && (int)($publishedReceipt['version'] ?? 0) === 1
            && (string)($publishedReceipt['method'] ?? '') === 'GET'
            && KeyBuilder::isValidFullPageCacheKey((string)($publishedReceipt['full_uri'] ?? ''))
            && \preg_match('/^[a-f0-9]{64}$/D', (string)($publishedReceipt['identity_digest'] ?? '')) === 1
        ) {
            return $publishedReceipt;
        }

        $fullUri = $this->getCacheKeyFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return null;
        }

        $variant = $this->buildCurrentFpcVariant();
        if ($this->privateSessionTokenFromVariant($variant) !== '') {
            return null;
        }
        $cacheKey = $this->buildUnifiedFpcCacheKey($fullUri, 'GET', $variant);

        return $this->buildInternalHomepageWarmupReceipt($fullUri, $variant, $cacheKey);
    }

    /**
     * Capture the exact identity that was used to publish or serve the
     * internal homepage prime. The caller supplies the already-resolved
     * full URI, variant and unified key so later Context mutations cannot
     * produce a different receipt.
     *
     * @param array<string, mixed> $variant
     * @return array{version:int,full_uri:string,method:string,cookie_header:string,identity_digest:string,cache_key:string}|null
     */
    private function buildInternalHomepageWarmupReceipt(
        string $fullUri,
        array $variant,
        string $unifiedCacheKey
    ): ?array {
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)
            || $unifiedCacheKey === ''
            || $this->privateSessionTokenFromVariant($variant) !== ''
        ) {
            return null;
        }

        $lang = $this->normalizeVariantLang((string)($variant['lang'] ?? ''));
        $currency = $this->normalizeVariantCurrency((string)($variant['currency'] ?? ''));
        $cookieHeader = 'WELINE_USER_LANG=' . \rawurlencode($lang)
            . '; WELINE_USER_CURRENCY=' . \rawurlencode($currency);

        return [
            'version' => 1,
            'full_uri' => $fullUri,
            'method' => 'GET',
            'cookie_header' => $cookieHeader,
            'identity_digest' => \hash('sha256', $unifiedCacheKey),
            'cache_key' => $unifiedCacheKey,
        ];
    }

    /**
     * @param array<string, mixed> $receipt
     */
    private function internalHomepageReceiptCacheKey(array $receipt): ?string
    {
        if ((int)($receipt['version'] ?? 0) !== 1
            || \strtoupper(\trim((string)($receipt['method'] ?? ''))) !== 'GET'
        ) {
            return null;
        }

        $fullUri = $this->canonicalizeFullUriForCacheKey(\trim((string)($receipt['full_uri'] ?? '')));
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)
            || $this->isEditorOrPreviewRequest($fullUri)
            || $this->isExcludedFrontendPath($fullUri)
            || $this->cookieHeaderHasLoggedInFrontendSession(
                (string)($receipt['cookie_header'] ?? ''),
                $fullUri,
            )
        ) {
            return null;
        }

        $cacheKey = \strtolower(\trim((string)($receipt['cache_key'] ?? '')));
        $identityDigest = \strtolower(\trim((string)($receipt['identity_digest'] ?? '')));
        if (\preg_match('/^[a-f0-9]{16}$/D', $cacheKey) !== 1
            || \preg_match('/^[a-f0-9]{64}$/D', $identityDigest) !== 1
            || !\hash_equals($identityDigest, \hash('sha256', $cacheKey))
        ) {
            return null;
        }

        return $cacheKey;
    }

    /**
     * Worker-native FPC hit path. It intentionally depends only on the raw
     * request facts so a cached page can bypass App/Router bootstrap entirely.
     *
     * @return array{response:string,source:string,bytes:int}|null
     */
    public function getFormattedCachedResponseForFullUri(
        string $fullUri,
        string $method,
        string $acceptHeader,
        string $acceptEncoding,
        string $cookieHeader,
        bool $keepAlive,
        bool $processOnly = false
    ): ?array {
        $method = \strtoupper(\trim($method) ?: 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return null;
        }
        if (!$this->acceptHeaderAllowsHtml($acceptHeader)) {
            return null;
        }
        $cacheMethod = $this->normalizeCacheMethod($method);

        $fullUri = $this->canonicalizeFullUriForCacheKey(\trim($fullUri));
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return null;
        }
        if (!Env::get('cache.status.router_cache', 1) || !Env::get('cache.status.frontend_cache', 1)) {
            return null;
        }
        if (\stripos($acceptHeader, 'text/event-stream') !== false) {
            return null;
        }
        $loggedInFrontendSession = $this->cookieHeaderHasLoggedInFrontendSession($cookieHeader, $fullUri);
        $privateSessionToken = '';
        if ($loggedInFrontendSession || !$this->legacyPreRouterFpcAllowed()) {
            return null;
        }
        if ($this->isEditorOrPreviewRequest($fullUri) || $this->isExcludedFrontendPath($fullUri)) {
            return null;
        }

        $variant = $this->buildFpcVariantFromCookieHeader($cookieHeader, $fullUri);
        $cacheKey = $this->buildUnifiedFpcCacheKey($fullUri, $cacheMethod, $variant);
        $payloadCacheKey = $cacheKey;
        $source = 'process';
        $cached = $this->getProcessCachedPayload($cacheKey);
        if ($cached === null) {
            if ($processOnly) {
                return null;
            }

            if ($method === 'GET' && \stripos($acceptEncoding, 'gzip') !== false) {
                $formattedKey = $this->buildFormattedFastHttpCacheKey($cacheKey);
                $formatted = $this->getProcessCachedFormattedResponse($formattedKey);
                if ($formatted !== null) {
                    return [
                        'response' => $this->withFormattedResponseConnection($formatted, $keepAlive),
                        'source' => 'process-formatted',
                        'bytes' => \strlen($formatted),
                    ];
                }

                self::cooperativeBuildYield();
                $formatted = $this->cache()->get($formattedKey);
                if (\is_string($formatted) && \str_starts_with($formatted, 'HTTP/1.1 ')) {
                    $this->setProcessCachedFormattedResponse($formattedKey, $formatted);
                    return [
                        'response' => $this->withFormattedResponseConnection($formatted, $keepAlive),
                        'source' => 'shared',
                        'bytes' => \strlen($formatted),
                    ];
                }
            }

            self::cooperativeBuildYield();
            $cached = $this->cache()->get($cacheKey);
            if (\is_array($cached)) {
                $cached = $this->hydrateSharedPayload($cached);
            }
            if (!\is_array($cached)) {
                if ($privateSessionToken !== '') {
                    return null;
                }

                // Do not spend the fast path probing a build lock. Public pages
                // can directly try stale and fall through to normal rendering.
                $staleCacheKey = $this->buildStaleCacheKey($cacheKey);
                $payloadCacheKey = $staleCacheKey;
                $cached = $this->getProcessCachedPayload($staleCacheKey);
                $source = 'stale-process';
                if ($cached === null) {
                    self::cooperativeBuildYield();
                    $cached = $this->cache()->get($staleCacheKey);
                    if (\is_array($cached)) {
                        $cached = $this->hydrateSharedPayload($cached);
                    }
                    $source = \is_array($cached) ? 'stale-shared' : 'stale-miss';
                }
                if (!\is_array($cached)) {
                    return null;
                }
            } else {
                $source = 'shared';
            }
        }
        if (!\is_array($cached)) {
            return null;
        }

        $body = (string)($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? '');
        if ($body === '') {
            return null;
        }

        $cached = $this->prepareCachedPayloadForFastHit(
            $payloadCacheKey,
            $cached,
            $body,
            $source === 'shared'
        );
        if ($cached === null) {
            return null;
        }
        if ($source === 'shared') {
            $this->setProcessCachedPayload($cacheKey, $cached);
            if ($method === 'GET' && \stripos($acceptEncoding, 'gzip') !== false) {
                $formatted = $this->buildFormattedGzipKeepAliveResponse($cached, 'shared');
                if ($formatted !== null) {
                    $formattedKey = $this->buildFormattedFastHttpCacheKey($cacheKey);
                    $this->setProcessCachedFormattedResponse($formattedKey, $formatted);
                    if ($this->shouldPublishSharedFormattedResponse($formatted)) {
                        $this->cache()->set($formattedKey, $formatted, 3600);
                    }
                }
            }
        } elseif ($source === 'stale-shared') {
            $this->setProcessCachedPayload($payloadCacheKey, $cached);
            if ($method === 'GET' && \stripos($acceptEncoding, 'gzip') !== false) {
                $formatted = $this->buildFormattedGzipKeepAliveResponse($cached, 'stale-shared');
                if ($formatted !== null) {
                    $formattedKey = $this->buildFormattedFastHttpCacheKey($payloadCacheKey);
                    $this->setProcessCachedFormattedResponse($formattedKey, $formatted, $this->staleTtlSeconds());
                    if ($this->shouldPublishSharedFormattedResponse($formatted)) {
                        $this->cache()->set($formattedKey, $formatted, $this->staleTtlSeconds());
                    }
                }
            }
        }

        if ($method === 'GET' && \stripos($acceptEncoding, 'gzip') !== false) {
            $formattedKey = $this->buildFormattedFastHttpCacheKey($payloadCacheKey);
            $formatted = $this->getProcessCachedFormattedResponse($formattedKey);
            if ($formatted === null) {
                $formatted = $this->buildFormattedGzipKeepAliveResponse($cached, $source);
                if ($formatted !== null) {
                    $ttl = \str_starts_with($source, 'stale-') ? $this->staleTtlSeconds() : null;
                    $this->setProcessCachedFormattedResponse($formattedKey, $formatted, $ttl);
                }
            }
            if ($formatted !== null) {
                return [
                    'response' => $this->withFormattedResponseConnection($formatted, $keepAlive),
                    'source' => $source . '-formatted',
                    'bytes' => \strlen($formatted),
                ];
            }
        }

        $statusCode = (int)($cached[KeyBuilder::UNIFIED_CACHE_STATUS_KEY] ?? 200);
        $gzipBody = $this->resolveCachedGzipBody($cached);
        $useGzipBody = $gzipBody !== null && \stripos($acceptEncoding, 'gzip') !== false;
        $responseBody = $useGzipBody ? $gzipBody : $body;

        $response = Response::fromContent($responseBody, $statusCode, 'text/html; charset=utf-8');
        $this->applyCachedHeaders($response, $cached[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY] ?? []);
        if ($useGzipBody) {
            $response->setHeader('Content-Encoding', 'gzip');
            $response->setHeader('Content-Length', (string)\strlen($gzipBody));
            $this->ensureVaryAcceptEncoding($response);
        }
        $isStale = \str_starts_with($source, 'stale-');
        $response->setHeader('X-Weline-FPC', $isStale ? 'STALE' : 'HIT');
        $response->setHeader('X-Wls-Performance-Fpc-Hit', '1');
        if ($isStale) {
            $response->setHeader('X-Wls-Performance-Fpc-Stale', '1');
        }
        $response->setHeader('X-Wls-Performance-Fpc-Source', $source);
        $response->setHeader('X-Wls-Performance-Fpc-Variant', $this->variantDebugToken($variant));
        $response->setHeader('X-Wls-Performance-Urlparser', '0');
        $response->setHeader('X-Wls-Performance-Urlparserapply', '0');
        $this->ensureVaryHeader($response, 'Cookie');
        $response->markTelemetryPrepared();

        $http = $response->toHttpString($keepAlive);
        if ($method === 'HEAD') {
            $headerEnd = \strpos($http, "\r\n\r\n");
            if ($headerEnd !== false) {
                $http = \substr($http, 0, $headerEnd + 4);
            }
        }

        return [
            'response' => $http,
            'source' => $source,
            'bytes' => \strlen($responseBody),
        ];
    }

    public function hasSharedCachedResponseForFullUri(
        string $fullUri,
        string $method = 'GET',
        string $cookieHeader = ''
    ): bool {
        $method = \strtoupper(\trim($method) ?: 'GET');
        if ($method !== 'GET' && $method !== 'HEAD') {
            return false;
        }

        $fullUri = $this->canonicalizeFullUriForCacheKey(\trim($fullUri));
        if (!KeyBuilder::isValidFullPageCacheKey($fullUri)) {
            return false;
        }
        if (!Env::get('cache.status.router_cache', 1) || !Env::get('cache.status.frontend_cache', 1)) {
            return false;
        }
        if ($this->isEditorOrPreviewRequest($fullUri) || $this->isExcludedFrontendPath($fullUri)) {
            return false;
        }
        if ($this->cookieHeaderHasLoggedInFrontendSession($cookieHeader, $fullUri)
            || !$this->legacyPreRouterFpcAllowed()) {
            return false;
        }

        $variant = $this->buildFpcVariantFromCookieHeader($cookieHeader, $fullUri);
        $cacheKey = $this->buildUnifiedFpcCacheKey($fullUri, $this->normalizeCacheMethod($method), $variant);
        $cached = $this->cache()->get($cacheKey);
        if (\is_array($cached)) {
            $cached = $this->hydrateSharedPayload($cached);
        }

        return \is_array($cached)
            && \is_string($cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? null)
            && (string)$cached[KeyBuilder::UNIFIED_CACHE_FPC_KEY] !== '';
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
        if (trim((string)(Context::getCurrent()?->server('HTTP_AUTHORIZATION', '')
            ?: WelineEnv::server('HTTP_AUTHORIZATION', '')
            ?: WelineEnv::get('server.http_authorization', ''))) !== '') {
            return false;
        }

        $rawFullUri = $this->getRawFullUri();
        $cacheKeyFullUri = $this->getCacheKeyFullUri();
        if (!KeyBuilder::isValidFullPageCacheKey($rawFullUri) || !KeyBuilder::isValidFullPageCacheKey($cacheKeyFullUri)) {
            return false;
        }

        if (!$this->requestAcceptsFrontendHtml()
            || $this->requestHasSseAcceptHeader()
            || $this->isEditorOrPreviewRequest($rawFullUri)
        ) {
            return false;
        }

        return !$this->isExcludedFrontendPath($rawFullUri);
    }

    private function isEditorOrPreviewRequest(string $fullUri): bool
    {
        if (\in_array((string)WelineEnv::get('editor_mode', ''), ['1', 'true'], true)) {
            return true;
        }

        $bypassKeys = ['preview', 'visual_editor', 'editor_mode', 'workspace_preview', 'debug_hooks', 'no_cache', 'nocache'];

        $getParams = WelineEnv::getGet(null, []);
        if (\is_array($getParams)) {
            foreach ($bypassKeys as $key) {
                if (isset($getParams[$key]) && (string)$getParams[$key] !== '' && (string)$getParams[$key] !== '0') {
                    return true;
                }
            }
        }

        $query = (string)(\parse_url($fullUri, \PHP_URL_QUERY) ?: '');
        if ($query === '') {
            $query = (string)WelineEnv::server('QUERY_STRING', '');
        }
        if ($query !== '') {
            \parse_str($query, $params);
            foreach ($bypassKeys as $key) {
                if (isset($params[$key]) && (string)$params[$key] !== '' && (string)$params[$key] !== '0') {
                    return true;
                }
            }
        }

        // WLS 部分请求在到达 FPC 时请求参数尚未填充，但 QUERY_STRING 已含 nocache 等参数
        $rawQuery = (string)WelineEnv::server('QUERY_STRING', '');
        if ($rawQuery !== '') {
            foreach ($bypassKeys as $key) {
                if (\preg_match('/(?:^|[&;])' . \preg_quote($key, '/') . '(?:=|&|;|$)/i', $rawQuery) === 1) {
                    return true;
                }
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

        foreach (['/_wls', '/.well-known', '/static', '/media', '/pub/static', '/pub/media'] as $prefix) {
            if ($path === $prefix || \str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        if (\in_array($path, ['/favicon.ico', '/robots.txt', '/sitemap.xml', '/sitemap_index.xml'], true)
            || \preg_match('/\.(?:avif|bmp|css|csv|docx?|eot|gif|gz|html?|ico|jpe?g|js|json|m3u8|map|mp3|mp4|ogg|otf|pdf|png|pptx?|rar|svg|tar|ttf|txt|wasm|webmanifest|webm|webp|woff2?|xlsx?|xml|zip|7z)$/i', $path) === 1
        ) {
            return true;
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
        return State::isAllowedCurrencyCode($segment);
    }

    private function requestHasSseAcceptHeader(): bool
    {
        $accept = (string)(WelineEnv::server('HTTP_ACCEPT', '') ?: WelineEnv::get('server.http_accept', ''));
        return \stripos($accept, 'text/event-stream') !== false;
    }

    private function requestAcceptsFrontendHtml(): bool
    {
        $accept = (string)(WelineEnv::server('HTTP_ACCEPT', '') ?: WelineEnv::get('server.http_accept', ''));
        return $this->acceptHeaderAllowsHtml($accept);
    }

    private function acceptHeaderAllowsHtml(string $accept): bool
    {
        $accept = \strtolower(\trim($accept));
        return $accept === ''
            || $accept === '*/*'
            || \str_contains($accept, 'text/html')
            || \str_contains($accept, 'application/xhtml+xml');
    }

    private function hasLoggedInFrontendSession(): bool
    {
        $cacheKey = 'fpc.frontend_logged_in';
        $cached = RequestContext::get($cacheKey);
        if (\is_bool($cached)) {
            return $cached;
        }

        $cookieHeader = (string)(
            Context::getCurrent()?->server('HTTP_COOKIE', '')
            ?: WelineEnv::server('HTTP_COOKIE', '')
            ?: WelineEnv::get('server.http_cookie', '')
        );
        $cookieName = SessionCookieNameResolver::resolve();
        if ($cookieHeader === '' && !Runtime::isPersistent() && isset($_COOKIE[$cookieName])) {
            $sessionId = (string)$_COOKIE[$cookieName];
            $cookieHeader = $sessionId === ''
                ? ''
                : \rawurlencode($cookieName) . '=' . \rawurlencode($sessionId);
        }
        if ($cookieHeader === '') {
            RequestContext::set($cacheKey, false);
            return false;
        }

        $loggedIn = $this->cookieHeaderHasLoggedInFrontendSession($cookieHeader);
        RequestContext::set($cacheKey, $loggedIn);
        return $loggedIn;
    }

    private function sessionCookieNameForFullUri(string $fullUri = ''): string
    {
        $fullUri = trim($fullUri);
        if ($fullUri === '') {
            return SessionCookieNameResolver::resolve();
        }
        try {
            $parts = parse_url($fullUri);
        } catch (\ValueError) {
            return SessionCookieNameResolver::resolve();
        }
        if (!is_array($parts)) {
            return SessionCookieNameResolver::resolve();
        }
        $host = trim((string)($parts['host'] ?? ''));
        if ($host === '') {
            return SessionCookieNameResolver::resolve();
        }
        $authority = str_contains($host, ':') ? '[' . trim($host, '[]') . ']' : $host;
        if (isset($parts['port'])) {
            $authority .= ':' . (int)$parts['port'];
        }
        return SessionCookieNameResolver::resolve($authority);
    }

    private function cookieHeaderHasLoggedInFrontendSession(string $cookieHeader, string $fullUri = ''): bool
    {
        $cookies = $this->parseCookieHeader($cookieHeader);
        $cookieName = $this->sessionCookieNameForFullUri($fullUri);
        if (!array_key_exists($cookieName, $cookies)) {
            return false;
        }
        $sessionId = (string)$cookies[$cookieName];
        if (!\preg_match('/^[a-f0-9]{32}$/i', $sessionId)) {
            return true;
        }

        $now = \microtime(true);
        if (isset(self::$frontendLoginSessionCache[$sessionId], self::$frontendLoginSessionCacheExpiresAt[$sessionId])
            && self::$frontendLoginSessionCacheExpiresAt[$sessionId] > $now) {
            return self::$frontendLoginSessionCache[$sessionId];
        }

        try {
            $sessionData = SessionFactory::getInstance()->createStorage('wls')->read($sessionId);
            if ($sessionData === []) {
                return false;
            }

            $areaConfig = new AreaConfig('frontend');
            $loginName = $sessionData[$areaConfig->getLoginKey()] ?? null;
            $loginId = $sessionData[$areaConfig->getLoginIdKey()] ?? null;

            $loggedIn = $loginName !== null && $loginName !== '' && $loginId !== null && $loginId !== '';
            if ($loggedIn) {
                $this->rememberFrontendLoginSessionState($sessionId);
            }
            return $loggedIn;
        } catch (\Throwable) {
            // If session state is unavailable, prefer correctness over serving a public cache to a logged-in user.
            return true;
        }
    }

    private function rememberFrontendLoginSessionState(string $sessionId): void
    {
        if (\count(self::$frontendLoginSessionCache) >= self::FRONTEND_LOGIN_SESSION_CACHE_MAX_ITEMS) {
            $oldestSessionId = \array_key_first(self::$frontendLoginSessionCache);
            if (\is_string($oldestSessionId)) {
                unset(self::$frontendLoginSessionCache[$oldestSessionId], self::$frontendLoginSessionCacheExpiresAt[$oldestSessionId]);
            }
        }

        self::$frontendLoginSessionCache[$sessionId] = true;
        self::$frontendLoginSessionCacheExpiresAt[$sessionId] = \microtime(true)
            + self::FRONTEND_LOGIN_SESSION_POSITIVE_TTL_SECONDS;
    }

    private function responseAllowsSharedPageCache(Response $response): bool
    {
        $contentType = $response->getHeader('Content-Type');
        if (is_array($contentType)) {
            $contentType = implode(',', array_map('strval', $contentType));
        }
        $contentType = strtolower((string)$contentType);
        if ($contentType === ''
            || (!str_contains($contentType, 'text/html')
            && !str_contains($contentType, 'application/xhtml+xml')
            )
        ) {
            return false;
        }

        $cacheControl = $response->getHeader('Cache-Control');
        if (\is_array($cacheControl)) {
            $cacheControl = \implode(',', \array_map('strval', $cacheControl));
        }
        foreach (explode(',', strtolower((string)$cacheControl)) as $directive) {
            $directive = trim($directive);
            if ($directive === 'private'
                || str_starts_with($directive, 'private=')
                || $directive === 'no-store'
                || str_starts_with($directive, 'no-store=')
                || $directive === 'no-cache'
                || str_starts_with($directive, 'no-cache=')
                || preg_match('/^(?:s-)?max-age\s*=\s*0+$/D', $directive) === 1
            ) {
                return false;
            }
        }

        $pragma = $response->getHeader('Pragma');
        if (is_array($pragma)) {
            $pragma = implode(',', array_map('strval', $pragma));
        }
        if (in_array('no-cache', array_map('trim', explode(',', strtolower((string)$pragma))), true)) {
            return false;
        }

        if ($response->getHeader('Set-Cookie') !== null || $response->getCookies() !== []) {
            return false;
        }

        $vary = $response->getHeader('Vary');
        if (is_array($vary)) {
            $vary = implode(',', array_map('strval', $vary));
        }
        foreach (explode(',', strtolower((string)$vary)) as $field) {
            $field = trim($field);
            if ($field !== '' && !in_array($field, ['cookie', 'accept-encoding'], true)) {
                return false;
            }
        }

        return true;
    }

    private function getUnifiedCachedResponse(string $method, bool $processOnly = false): ?Response
    {
        $cacheKey = $this->getUnifiedCacheKey($method);
        $cachePayloadFetched = false;
        $cachePayloadUpdated = false;
        // The fenced READY prime must prove that a shared entry exists or
        // republish it. A process-only hit can outlive a transient shared
        // publish and would otherwise make every later receipt rehydrate fail.
        $forceSharedPrime = InternalHomepagePrime::isCurrentRequest();
        if ($forceSharedPrime) {
            $processOnly = false;
        }
        $cached = $forceSharedPrime ? null : $this->getProcessCachedPayload($cacheKey);
        $cacheSource = 'process';
        if ($cached === null) {
            if ($processOnly) {
                RequestContext::set('wls.fpc.hit_source', 'process-miss');
                return null;
            }

            self::cooperativeBuildYield();
            $cached = $this->cache()->get($cacheKey);
            if (\is_array($cached)) {
                $cached = $this->hydrateSharedPayload($cached);
            }
            self::cooperativeBuildYield();
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
        $cached = $this->prepareCachedPayloadForFrameworkHit(
            $cacheKey,
            $cached,
            $body,
            $cachePayloadFetched
        );
        if ($cached === null) {
            return null;
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
        $response->setHeader('X-Wls-Performance-Fpc-Hit', '1');
        $response->setHeader('X-Wls-Performance-Fpc-Source', $cacheSource);
        $response->setHeader('X-Wls-Performance-Fpc-Variant', $this->variantDebugToken($this->buildCurrentFpcVariant()));
        $response->setHeader('X-Wls-Performance-Urlparser', '0');
        $response->setHeader('X-Wls-Performance-Urlparserapply', '0');
        $this->ensureVaryHeader($response, 'Cookie');
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

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed>|null
     */
    private function prepareCachedPayloadForFastHit(
        string $cacheKey,
        array $cached,
        string $body,
        bool $writeShared
    ): ?array {
        return $this->prepareCachedPayloadForHit($cacheKey, $cached, $body, false, $writeShared);
    }

    /**
     * @param array<string, mixed> $cached
     * @return array<string, mixed>|null
     */
    private function prepareCachedPayloadForFrameworkHit(
        string $cacheKey,
        array $cached,
        string $body,
        bool $writeShared
    ): ?array {
        return $this->prepareCachedPayloadForHit(
            $cacheKey,
            $cached,
            $body,
            $this->shouldValidateCachedHtmlUrlsOnHit($cached),
            $writeShared
        );
    }

    /**
     * Legacy FPC payloads may predate URL validation or cached gzip bodies.
     * Normalize them once at the cache boundary so worker fastpath does not
     * fall back into full App/Router rendering for already-cacheable pages.
     *
     * @param array<string, mixed> $cached
     * @return array<string, mixed>|null
     */
    private function prepareCachedPayloadForHit(
        string $cacheKey,
        array $cached,
        string $body,
        bool $validateUrls,
        bool $writeShared
    ): ?array {
        $remainingTtl = $this->payloadRemainingTtlSeconds($cached);
        if ($remainingTtl !== null && $remainingTtl <= 0) {
            $this->deleteCachedPayloadByKey($cacheKey);
            return null;
        }
        $updated = false;

        if (($cached[self::UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY] ?? false) !== true) {
            if (!$validateUrls) {
                $cached[self::UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY] = true;
                $updated = true;
            } elseif ($this->bodyContainsIgnorableHtmlUrlQuery($body)) {
                $this->deleteCachedPayloadByKey($cacheKey);
                $this->logFpcWarning('drop cached polluted seo url', [
                    'cache_key' => $cacheKey,
                ]);
                return null;
            }
            if ($validateUrls) {
                $cached[self::UNIFIED_CACHE_HTML_URLS_VALIDATED_KEY] = true;
                $updated = true;
            }
        }

        if (($cached[self::UNIFIED_CACHE_FPC_GZIP_B64_KEY] ?? '') === '') {
            $gzipBody = $this->buildCachedGzipBody($body);
            if ($gzipBody !== null) {
                $cached[self::UNIFIED_CACHE_FPC_GZIP_B64_KEY] = \base64_encode($gzipBody);
                $updated = true;
            }
        }

        if ($updated) {
            $this->setProcessCachedPayload($cacheKey, $cached);
            if ($writeShared) {
                $this->cache()->set(
                    $cacheKey,
                    $this->externalizeSharedPayload($cacheKey, $cached),
                    $remainingTtl ?? 3600
                );
            }
        }

        return $cached;
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

    /**
     * @param array<string, mixed> $cached
     */
    private function buildFormattedGzipKeepAliveResponse(array $cached, string $source): ?string
    {
        $gzipBody = $this->resolveCachedGzipBody($cached);
        if ($gzipBody === null) {
            return null;
        }

        $statusCode = (int)($cached[KeyBuilder::UNIFIED_CACHE_STATUS_KEY] ?? 200);
        $response = Response::fromContent($gzipBody, $statusCode, 'text/html; charset=utf-8');
        $this->applyCachedHeaders($response, $cached[KeyBuilder::UNIFIED_CACHE_HEADERS_KEY] ?? []);
        $response->setHeader('Content-Encoding', 'gzip');
        $response->setHeader('Content-Length', (string)\strlen($gzipBody));
        $this->ensureVaryAcceptEncoding($response);
        $response->setHeader('X-Weline-FPC', 'HIT');
        $response->setHeader('X-Wls-Performance-Fpc-Hit', '1');
        $response->setHeader('X-Wls-Performance-Fpc-Source', $source);
        $variant = \is_array($cached[self::VARIANT_PAYLOAD_KEY] ?? null)
            ? $cached[self::VARIANT_PAYLOAD_KEY]
            : [];
        $response->setHeader('X-Wls-Performance-Fpc-Variant', $this->variantDebugToken($variant));
        $response->setHeader('X-Wls-Performance-Urlparser', '0');
        $response->setHeader('X-Wls-Performance-Urlparserapply', '0');
        $this->ensureVaryHeader($response, 'Cookie');
        $response->markTelemetryPrepared();

        return $response->toHttpString(true);
    }

    private function buildFormattedFastHttpCacheKey(string $cacheKey): string
    {
        $headers = (new SecurityHeaderPolicyService())->resolveCurrentResponseHeaders();
        \ksort($headers, \SORT_STRING);
        $securityVariant = \substr(\hash('sha256', \serialize($headers)), 0, 16);

        return $cacheKey . self::FAST_HTTP_GZIP_KEEPALIVE_SUFFIX . ':' . $securityVariant;
    }

    private function withFormattedResponseConnection(string $http, bool $keepAlive): string
    {
        if ($keepAlive) {
            return $http;
        }

        $connection = $keepAlive ? 'keep-alive' : 'close';
        $replacement = "Connection: {$connection}\r\n";
        $updated = \preg_replace('/^Connection:\s*[^\r\n]*\r\n/mi', $replacement, $http, 1);
        if (\is_string($updated) && $updated !== '') {
            return $updated;
        }

        $headerEnd = \strpos($http, "\r\n\r\n");
        if ($headerEnd === false) {
            return $http;
        }

        return \substr($http, 0, $headerEnd + 2) . $replacement . \substr($http, $headerEnd + 2);
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
        $this->ensureVaryHeader($response, 'Accept-Encoding');
    }

    private function ensureVaryHeader(Response $response, string $headerName): void
    {
        $headerName = \trim($headerName);
        if ($headerName === '') {
            return;
        }

        $vary = $response->getHeader('Vary');
        $varyValue = \is_array($vary) ? \implode(', ', \array_map('strval', $vary)) : (string)($vary ?? '');
        if ($varyValue === '') {
            $response->setHeader('Vary', $headerName);
            return;
        }

        foreach (\array_map('trim', \explode(',', $varyValue)) as $part) {
            if (\strcasecmp($part, $headerName) === 0) {
                return;
            }
        }

        $response->setHeader('Vary', $varyValue . ', ' . $headerName);
    }

    private function applyCachedHeaders(Response $response, mixed $headers): void
    {
        $normalized = [];
        if (\is_array($headers)) {
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
        }

        foreach ($normalized as $name => $value) {
            $response->setHeaders([$name => $value]);
        }

        $securityHeaders = (new SecurityHeaderPolicyService())->resolveCurrentResponseHeaders();
        foreach ($securityHeaders as $name => $value) {
            if (\strcasecmp($name, 'Vary') === 0) {
                $this->ensureVaryHeader($response, $value);
                continue;
            }

            $response->setHeader($name, $value);
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
        $remainingTtl = $this->payloadRemainingTtlSeconds($payload);
        if ($remainingTtl !== null && $remainingTtl <= 0) {
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
        $processTtl = $remainingTtl === null
            ? self::PROCESS_FPC_TTL_SECONDS
            : \min(self::PROCESS_FPC_TTL_SECONDS, $remainingTtl);
        self::$processFpcPayloadExpiresAt[$cacheKey] = \microtime(true) + $processTtl;
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

    private function getProcessCachedFormattedResponse(string $cacheKey): ?string
    {
        $expiresAt = self::$processFormattedFpcExpiresAt[$cacheKey] ?? 0.0;
        if ($expiresAt <= \microtime(true)) {
            $this->deleteProcessCachedFormattedResponse($cacheKey);
            return null;
        }

        $formatted = self::$processFormattedFpcCache[$cacheKey] ?? null;
        return \is_string($formatted) && \str_starts_with($formatted, 'HTTP/1.1 ') ? $formatted : null;
    }

    private function setProcessCachedFormattedResponse(string $cacheKey, string $formatted, ?int $ttl = null): void
    {
        if ($formatted === '' || !\str_starts_with($formatted, 'HTTP/1.1 ')) {
            return;
        }

        $bytes = \strlen($formatted);
        if ($bytes <= 0 || $bytes > self::PROCESS_FORMATTED_FPC_MAX_BYTES) {
            return;
        }

        $this->deleteProcessCachedFormattedResponse($cacheKey);
        while ((\count(self::$processFormattedFpcCache) >= self::PROCESS_FORMATTED_FPC_MAX_ITEMS
                || self::$processFormattedFpcTotalBytes + $bytes > self::PROCESS_FORMATTED_FPC_MAX_BYTES)
            && self::$processFormattedFpcCache !== []
        ) {
            $oldestKey = (string)\array_key_first(self::$processFormattedFpcCache);
            $this->deleteProcessCachedFormattedResponse($oldestKey);
        }

        self::$processFormattedFpcCache[$cacheKey] = $formatted;
        self::$processFormattedFpcExpiresAt[$cacheKey] = \microtime(true) + \max(1, \min(self::PROCESS_FPC_TTL_SECONDS, $ttl ?? self::PROCESS_FPC_TTL_SECONDS));
        self::$processFormattedFpcBytes[$cacheKey] = $bytes;
        self::$processFormattedFpcTotalBytes += $bytes;
    }

    private function deleteProcessCachedFormattedResponse(string $cacheKey): void
    {
        if (!isset(self::$processFormattedFpcCache[$cacheKey])) {
            return;
        }

        self::$processFormattedFpcTotalBytes -= self::$processFormattedFpcBytes[$cacheKey] ?? 0;
        if (self::$processFormattedFpcTotalBytes < 0) {
            self::$processFormattedFpcTotalBytes = 0;
        }
        unset(
            self::$processFormattedFpcCache[$cacheKey],
            self::$processFormattedFpcExpiresAt[$cacheKey],
            self::$processFormattedFpcBytes[$cacheKey]
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

        // Early FPC responses intentionally bypass App::run_after. Give
        // request-scoped response decorators a narrow hook without replaying
        // unrelated normal-response observers or mutating the cached payload.
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        if ($eventManager->hasObservers('Weline_Framework_Fpc::cache_hit_response')) {
            $eventData = ['response' => $response];
            $eventManager->dispatch('Weline_Framework_Fpc::cache_hit_response', $eventData);
            $decorated = $eventData['response'] ?? null;
            if (!$decorated instanceof Response) {
                throw new \RuntimeException(__('FPC 命中响应装饰器返回了无效响应'));
            }
            $response = $decorated;
        }

        return $response;
    }

    private function getUnifiedCacheKey(string $method): string
    {
        return $this->buildUnifiedFpcCacheKey(
            $this->getCacheKeyFullUri(),
            $this->normalizeCacheMethod($method),
            $this->buildCurrentFpcVariant()
        );
    }

    private function getBuildLockKey(string $method): string
    {
        return $this->buildBuildLockKeyForFullUri(
            $this->getCacheKeyFullUri(),
            $this->normalizeBuildMethod($method),
            $this->buildCurrentFpcVariant()
        );
    }

    /**
     * @param array<string, string> $variant
     */
    private function buildBuildLockKeyForFullUri(string $fullUri, string $method, array $variant): string
    {
        return KeyBuilder::build(
            'router',
            $this->decorateFpcLogicalKey(
                'fpc-lock:'
                . $fullUri
                . ':'
                . $this->variantSuffix($variant)
                . ':'
                . $this->normalizeBuildMethod($method),
                $fullUri,
            )
        );
    }

    private function buildStaleCacheKey(string $cacheKey): string
    {
        return $cacheKey . self::STALE_CACHE_SUFFIX;
    }

    /**
     * @param array<string, string> $variant
     */
    private function buildSchemaNeutralStaleCacheKey(string $fullUri, string $method, array $variant): string
    {
        return KeyBuilder::build(
            'router',
            $this->decorateFpcLogicalKey(
                self::SCHEMA_NEUTRAL_STALE_CACHE_PREFIX
                . $fullUri
                . ':'
                . $this->variantSuffixWithoutSchema($variant)
                . ':'
                . \strtoupper($this->normalizeBuildMethod($method) ?: 'GET'),
                $fullUri,
            )
        );
    }

    private function staleTtlSeconds(): int
    {
        $configured = (int)Env::get('wls.performance.fpc_stale_ttl_seconds', self::DEFAULT_STALE_TTL_SECONDS);
        return \max(60, \min($configured, 604800));
    }

    private function shouldPublishSharedStalePayload(string $body): bool
    {
        $maxBytes = (int)(Env::get('wls.performance.fpc_shared_stale_max_body_bytes', 1048576) ?: 0);
        return $maxBytes > 0 && \strlen($body) <= $maxBytes;
    }

    private function shouldPublishSharedFormattedResponse(string $formattedResponse): bool
    {
        $maxBytes = (int)(Env::get('wls.performance.fpc_shared_formatted_max_bytes', 0) ?: 0);
        return $maxBytes > 0 && \strlen($formattedResponse) <= $maxBytes;
    }

    /**
     * Keep large rendered HTML out of MemoryService values. The shared cache
     * stores a small pointer, while each worker hydrates the payload once into
     * its process-local FPC cache.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function externalizeSharedPayload(string $cacheKey, array $payload): array
    {
        $body = $payload[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? null;
        if (!\is_string($body) || $body === '') {
            return $payload;
        }

        $minBytes = (int)(Env::get('wls.performance.fpc_shared_file_body_min_bytes', 262144) ?: 0);
        if ($minBytes <= 0 || \strlen($body) < $minBytes) {
            return $payload;
        }

        $file = $this->sharedPayloadFilePath($cacheKey);
        if (!$this->writeSharedPayloadFile($file, $body)) {
            return $payload;
        }

        unset($payload[KeyBuilder::UNIFIED_CACHE_FPC_KEY]);
        // The gzip copy can still be close to the original HTML size (and is
        // base64-expanded inside the NDJSON shared-state frame). Keeping it
        // here defeats body externalization and can exceed the bounded Memory
        // Service protocol frame. Each Worker rebuilds this optional variant
        // once after hydrating the shared body and retains it in Process L1.
        unset($payload[self::UNIFIED_CACHE_FPC_GZIP_B64_KEY]);
        $payload[self::UNIFIED_CACHE_FPC_BODY_FILE_KEY] = [
            'path' => $file,
            'bytes' => \strlen($body),
        ];

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private function hydrateSharedPayload(array $payload): ?array
    {
        $body = $payload[KeyBuilder::UNIFIED_CACHE_FPC_KEY] ?? null;
        if (\is_string($body) && $body !== '') {
            return $payload;
        }

        $meta = $payload[self::UNIFIED_CACHE_FPC_BODY_FILE_KEY] ?? null;
        if (!\is_array($meta)) {
            return null;
        }

        $file = (string)($meta['path'] ?? '');
        if (!$this->isSharedPayloadPath($file)) {
            return null;
        }

        $body = @\file_get_contents($file);
        if (!\is_string($body) || $body === '') {
            return null;
        }

        $expectedBytes = (int)($meta['bytes'] ?? 0);
        if ($expectedBytes > 0 && \strlen($body) !== $expectedBytes) {
            return null;
        }

        $payload[KeyBuilder::UNIFIED_CACHE_FPC_KEY] = $body;

        return $payload;
    }

    private function writeSharedPayloadFile(string $file, string $body): bool
    {
        $dir = \dirname($file);
        if (!\is_dir($dir) && !@\mkdir($dir, 0775, true) && !\is_dir($dir)) {
            return false;
        }

        try {
            $suffix = \bin2hex(\random_bytes(4));
        } catch (\Throwable) {
            $suffix = \str_replace('.', '', (string)\microtime(true));
        }

        $tmp = $file . '.' . (string)\getmypid() . '.' . $suffix . '.tmp';
        if (@\file_put_contents($tmp, $body, \LOCK_EX) === false) {
            return false;
        }

        if (@\rename($tmp, $file)) {
            return true;
        }

        @\unlink($tmp);
        return false;
    }

    private function deleteSharedPayloadFile(string $cacheKey): void
    {
        $file = $this->sharedPayloadFilePath($cacheKey);
        if (\is_file($file)) {
            @\unlink($file);
        }
    }

    private function sharedPayloadFilePath(string $cacheKey): string
    {
        $hash = \hash('sha256', $cacheKey);
        return $this->sharedPayloadBaseDir()
            . \DIRECTORY_SEPARATOR
            . \substr($hash, 0, 2)
            . \DIRECTORY_SEPARATOR
            . $hash
            . '.html';
    }

    private function sharedPayloadBaseDir(): string
    {
        return BP
            . 'var'
            . \DIRECTORY_SEPARATOR
            . 'cache'
            . \DIRECTORY_SEPARATOR
            . 'router-fpc-payloads';
    }

    private function isSharedPayloadPath(string $file): bool
    {
        if ($file === '') {
            return false;
        }

        $base = \rtrim($this->sharedPayloadBaseDir(), '\\/') . \DIRECTORY_SEPARATOR;
        $normalizedFile = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $file);
        $normalizedBase = \str_replace(['/', '\\'], \DIRECTORY_SEPARATOR, $base);

        return \str_starts_with($normalizedFile, $normalizedBase);
    }

    private function privateSessionFpcEnabled(): bool
    {
        // P1a 固定策略：登录用户整页缓存硬旁路，配置不得重新开启。
        return false;
    }

    private function privateSessionFpcTtlSeconds(): int
    {
        $configured = (int)Env::get(
            'wls.performance.fpc_private_session_ttl_seconds',
            self::DEFAULT_PRIVATE_SESSION_TTL_SECONDS
        );

        return \max(5, \min($configured, 300));
    }

    private function canUsePrivateSessionFpc(): bool
    {
        return false;
    }

    private function currentPrivateSessionToken(): string
    {
        $cookieHeader = (string)(
            Context::getCurrent()?->server('HTTP_COOKIE', '')
            ?: WelineEnv::server('HTTP_COOKIE', '')
            ?: WelineEnv::get('server.http_cookie', '')
        );
        $cookieName = SessionCookieNameResolver::resolve();
        if ($cookieHeader === '' && !Runtime::isPersistent() && isset($_COOKIE[$cookieName])) {
            $sessionId = (string)$_COOKIE[$cookieName];
            $cookieHeader = $sessionId === ''
                ? ''
                : \rawurlencode($cookieName) . '=' . \rawurlencode($sessionId);
        }
        if ($cookieHeader === '') {
            return '';
        }

        return $this->privateSessionTokenFromCookieHeader($cookieHeader);
    }

    private function privateSessionTokenFromCookieHeader(string $cookieHeader): string
    {
        $cookieName = $this->sessionCookieNameForFullUri($this->getRawFullUri());
        $sessionId = (string)($this->parseCookieHeader($cookieHeader)[$cookieName] ?? '');
        if ($sessionId === '' || !\preg_match('/^[a-f0-9]{32}$/i', $sessionId)) {
            return '';
        }

        return \sha1($sessionId);
    }

    /**
     * @param array<string, string> $variant
     */
    private function privateSessionTokenFromVariant(array $variant): string
    {
        $token = (string)($variant['session'] ?? '');
        return \preg_match('/^[a-f0-9]{40}$/i', $token) ? $token : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadRemainingTtlSeconds(array $payload): ?int
    {
        $expiresAt = (float)($payload[self::UNIFIED_CACHE_EXPIRES_AT_KEY] ?? 0.0);
        if ($expiresAt <= 0.0) {
            return null;
        }

        return (int)\floor($expiresAt - \microtime(true));
    }

    private function normalizeHttpMethod(string $method): string
    {
        $method = \strtoupper(\trim($method) ?: 'GET');
        return $method !== '' ? $method : 'GET';
    }

    private function normalizeCacheMethod(string $method): string
    {
        $method = $this->normalizeHttpMethod($method);
        return $method === 'HEAD' ? 'GET' : $method;
    }

    private function normalizeBuildMethod(string $method): string
    {
        return $this->normalizeCacheMethod($method);
    }

    /**
     * FPC must vary by the same storefront dimensions that can change visible
     * HTML without changing the URL. URL prefixes/query parameters are already
     * covered by fullUri; these fields protect cookie-driven switches.
     *
     * @return array<string, string>
     */
    private function buildCurrentFpcVariant(): array
    {
        $context = $this->currentStorefrontCacheKeyContext();
        if (!$context->cacheable) {
            throw new \LogicException(__('Storefront 缓存上下文尚未完成版本冻结'));
        }
        return $this->variantFromStorefrontContext($context, [
            'lang' => $this->normalizeVariantLang($context->lang),
            'currency' => $this->normalizeVariantCurrency($context->currency),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function buildFpcVariantFromCookieHeader(string $cookieHeader, string $fullUri = ''): array
    {
        $cookies = $this->parseCookieHeader($cookieHeader);

        $variant = [
            'lang' => $this->normalizeVariantLang(
                (string)($cookies['WELINE_USER_LANG'] ?? $cookies['WELINE-WEBSITE-LANG'] ?? self::DEFAULT_LANG)
            ),
            'currency' => $this->normalizeVariantCurrency(
                (string)($cookies['WELINE_USER_CURRENCY'] ?? $cookies['WELINE_WEBSITE_CURRENCY'] ?? self::DEFAULT_CURRENCY)
            ),
        ];
        $context = $this->currentStorefrontCacheKeyContext();
        if (!$context->cacheable) {
            throw new \LogicException(__('Storefront 缓存上下文尚未完成版本冻结'));
        }
        return $this->mergePathVariant(
            $this->variantFromStorefrontContext($context, $variant),
            $fullUri,
        );
    }

    /**
     * @param array<string, string> $variant
     */
    private function buildUnifiedFpcCacheKey(string $fullUri, string $method, array $variant): string
    {
        return KeyBuilder::build(
            'router',
            $this->decorateFpcLogicalKey(
                'unified-fpc:'
                . $fullUri
                . ':'
                . $this->variantSuffix($variant)
                . ':'
                . \strtoupper($method ?: 'GET'),
                $fullUri,
            )
        );
    }

    /**
     * NamespaceGenerationSnapshot freezes the complete storefront version
     * vector for the current request/Fiber, so a build cannot switch versions
     * between lock acquisition, payload publication and receipt capture.
     */
    private function decorateFpcLogicalKey(string $logicalKey, string $fullUri): string
    {
        return $this->namespaceKeyDecorator()->decorate($logicalKey, $this->fpcNamespaceFingerprint($fullUri));
    }

    private function fpcNamespaceFingerprint(string $fullUri): string
    {
        $context = $this->currentStorefrontCacheKeyContext();
        if (!$context->cacheable || $context->namespaceFingerprint === null) {
            throw new \LogicException(__('FPC 缺少已冻结的 Storefront 命名空间指纹'));
        }
        return $context->namespaceFingerprint;
    }

    private function namespaceKeyDecorator(): NamespaceKeyDecorator
    {
        if ($this->namespaceKeyDecorator === null) {
            $this->namespaceKeyDecorator = ObjectManager::getInstance(NamespaceKeyDecorator::class);
        }

        return $this->namespaceKeyDecorator;
    }

    /**
     * @param array<string, string> $variant
     */
    private function variantSuffix(array $variant): string
    {
        $suffix = $this->variantSuffixWithoutSchema($variant);
        $suffix .= '|schema=' . self::FPC_CACHE_SCHEMA_VERSION;

        return $suffix;
    }

    /**
     * @param array<string, string> $variant
     */
    private function variantSuffixWithoutSchema(array $variant): string
    {
        $suffix = 'lang=' . $this->normalizeVariantLang((string)($variant['lang'] ?? ''))
            . '|currency=' . $this->normalizeVariantCurrency((string)($variant['currency'] ?? ''));
        foreach ([
            'scope_state',
            'scope_kind',
            'website',
            'store',
            'channel',
            'store_mode',
            'context_version',
            'cache_version',
        ] as $field) {
            if (isset($variant[$field]) && (string)$variant[$field] !== '') {
                $suffix .= '|' . $field . '=' . \rawurlencode((string)$variant[$field]);
            }
        }

        return $suffix;
    }

    /** @param array<string, string> $variant @return array<string, string> */
    private function variantFromStorefrontContext(
        StorefrontCacheKeyContext $context,
        array $variant,
    ): array
    {
        $dimensions = $context->keyDimensions();
        $variant['scope_state'] = $dimensions['scope_state'];
        $variant['scope_kind'] = $dimensions['scope_kind'];
        $variant['website'] = $dimensions['website'];
        $variant['store'] = $dimensions['store'];
        $variant['channel'] = $dimensions['channel'];
        $variant['store_mode'] = $dimensions['store_mode'];
        $variant['context_version'] = $dimensions['context_version'];
        $variant['cache_version'] = $dimensions['cache_key_fingerprint'];
        return $variant;
    }

    private function hasCompleteFrozenStorefrontScope(): bool
    {
        $provider = $this->storefrontScopeProviderResolution();
        if ($provider->status === RuntimeProviderResolution::CONFIGURED_UNAVAILABLE) {
            return false;
        }
        $context = $this->currentStorefrontCacheKeyContext();
        if ($provider->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            return $context->cacheable;
        }
        $identity = RequestContext::scopeIdentity();
        return $context->hasCompleteFrozenScope()
            && $identity instanceof \Weline\Framework\Runtime\ScopeIdentity
            && $context->scopeIdentity?->equals($identity);
    }

    private function legacyPreRouterFpcAllowed(): bool
    {
        // Raw Worker requests have no request-local frozen generation vector.
        // Keep them paused even for a Framework-only install; App-level FPC can
        // still use the legacy default context after Context::enter().
        return Context::hasCurrent()
            && $this->storefrontScopeProviderResolution()->status === RuntimeProviderResolution::NOT_CONFIGURED;
    }

    private function currentStorefrontCacheKeyContext(): StorefrontCacheKeyContext
    {
        $current = StorefrontCacheKeyContext::current();
        if ($current instanceof StorefrontCacheKeyContext && $current->cacheable) {
            $provider = $this->storefrontScopeProviderResolution();
            $identity = RequestContext::scopeIdentity();
            if ($provider->status === RuntimeProviderResolution::NOT_CONFIGURED
                || ($identity instanceof \Weline\Framework\Runtime\ScopeIdentity
                    && $current->scopeIdentity?->equals($identity))
            ) {
                return $current;
            }
        }
        $provider = $this->storefrontScopeProviderResolution();
        if ($provider->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            return $this->storefrontCacheKeyContextResolver()->freezeLegacyDefault();
        }
        return $this->storefrontCacheKeyContextResolver()->freezeCurrent();
    }

    private function storefrontCacheKeyContextResolver(): StorefrontCacheKeyContextResolver
    {
        if ($this->storefrontCacheKeyContextResolver === null) {
            $this->storefrontCacheKeyContextResolver = ObjectManager::getInstance(
                StorefrontCacheKeyContextResolver::class,
            );
        }
        return $this->storefrontCacheKeyContextResolver;
    }

    private function storefrontScopeProviderResolution(): RuntimeProviderResolution
    {
        if ($this->storefrontProviderResolution instanceof RuntimeProviderResolution) {
            return $this->storefrontProviderResolution;
        }
        try {
            return $this->storefrontProviderResolution = $this->runtimeProviderResolver()
                ->resolveDetailed(StorefrontScopeInstallerInterface::class);
        } catch (\Throwable $exception) {
            return $this->storefrontProviderResolution = new RuntimeProviderResolution(
                RuntimeProviderResolution::CONFIGURED_UNAVAILABLE,
                errorCode: 'storefront_provider_resolution_failed',
                error: $exception->getMessage(),
            );
        }
    }

    private function runtimeProviderResolver(): RuntimeProviderResolver
    {
        if ($this->runtimeProviderResolver === null) {
            $this->runtimeProviderResolver = ObjectManager::getInstance(RuntimeProviderResolver::class);
        }
        return $this->runtimeProviderResolver;
    }

    /**
     * @param array<string, string> $variant
     */
    private function variantDebugToken(array $variant): string
    {
        return \substr(\sha1($this->variantSuffix($variant)), 0, 12);
    }

    private function normalizeVariantLang(string $lang): string
    {
        $lang = \trim($lang);
        if ($lang === '') {
            return self::DEFAULT_LANG;
        }

        return \str_replace('-', '_', $lang);
    }

    private function normalizeVariantCurrency(string $currency): string
    {
        $currency = \strtoupper(\trim($currency));
        if (!State::isAllowedCurrencyCode($currency)) {
            return self::DEFAULT_CURRENCY;
        }

        return $currency;
    }

    /**
     * @param array<string, string> $variant
     * @return array<string, string>
     */
    private function mergePathVariant(array $variant, string $fullUri): array
    {
        $pathVariant = $this->extractPathVariant($fullUri);
        if (($pathVariant['lang'] ?? '') !== '') {
            $variant['lang'] = $this->normalizeVariantLang((string)$pathVariant['lang']);
        } else {
            // Unprefixed URLs represent the website default language. Do not let
            // WELINE_USER_LANG fork `/` into a second locale cache entry — that
            // makes the language switcher say 简体中文 while FPC still serves English.
            $websiteLang = $this->resolveWebsiteDefaultLangForUnprefixedFpc();
            if ($websiteLang !== '') {
                $variant['lang'] = $this->normalizeVariantLang($websiteLang);
            }
        }
        if (($pathVariant['currency'] ?? '') !== '') {
            $variant['currency'] = $this->normalizeVariantCurrency((string)$pathVariant['currency']);
        }

        return $variant;
    }

    private function resolveWebsiteDefaultLangForUnprefixedFpc(): string
    {
        try {
            $code = \trim((string)\Weline\Framework\Env\WelineEnv::get('website.language', ''));
            if ($code !== '') {
                return $code;
            }
        } catch (\Throwable) {
        }

        try {
            $code = \trim((string)(
                \Weline\Framework\Env\WelineEnv::server('WELINE_WEBSITE_LANGUAGE', '')
                ?: \Weline\Framework\Env\WelineEnv::server('WELINE-WEBSITE-LANG', '')
            ));
            if ($code !== '') {
                return $code;
            }
        } catch (\Throwable) {
        }

        return self::DEFAULT_LANG;
    }

    /**
     * @return array{lang:string,currency:string}
     */
    private function extractPathVariant(string $fullUri): array
    {
        $path = (string)(\parse_url($fullUri, \PHP_URL_PATH) ?: '');
        if ($path === '') {
            return ['lang' => '', 'currency' => ''];
        }

        $segments = \array_values(\array_filter(\explode('/', \trim($path, '/')), static function (string $segment): bool {
            return $segment !== '';
        }));
        $variant = ['lang' => '', 'currency' => ''];
        foreach (\array_slice($segments, 0, 3) as $segment) {
            $segment = (string)$segment;
            if ($variant['lang'] === '' && $this->isLocalePrefix($segment)) {
                $variant['lang'] = $segment;
                continue;
            }
            if ($variant['currency'] === '' && $this->isCurrencyPrefix($segment)) {
                $variant['currency'] = $segment;
            }
        }

        return $variant;
    }

    /**
     * @return array<string, string>
     */
    private function parseCookieHeader(string $cookieHeader): array
    {
        $cookies = [];
        foreach (\preg_split('/;\s*/', \trim($cookieHeader), -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            if (!\str_contains($part, '=')) {
                continue;
            }
            [$name, $value] = \explode('=', $part, 2);
            $name = \trim($name);
            if ($name === '') {
                continue;
            }
            $cookies[$name] = \urldecode(\trim($value));
        }

        return $cookies;
    }

    private function deleteCurrentCacheEntries(string $method): void
    {
        try {
            $unifiedCacheKey = $this->getUnifiedCacheKey($method);
            $this->deleteCachedPayloadByKey($unifiedCacheKey);
        } catch (\Throwable $throwable) {
            $this->logFpcWarning('delete current cache entries failed', [
                'error' => $throwable->getMessage(),
                'cache_key_full_uri' => $this->getCacheKeyFullUri(),
                'method' => $method,
            ]);
        }
    }

    private function deleteCachedPayloadByKey(string $cacheKey): void
    {
        $staleCacheKey = $this->buildStaleCacheKey($cacheKey);
        $this->cache()->delete($cacheKey);
        $this->cache()->delete($this->buildFormattedFastHttpCacheKey($cacheKey));
        $this->cache()->delete($staleCacheKey);
        $this->cache()->delete($this->buildFormattedFastHttpCacheKey($staleCacheKey));
        $this->deleteSharedPayloadFile($cacheKey);
        $this->deleteSharedPayloadFile($staleCacheKey);
        $this->deleteProcessCachedPayload($cacheKey);
        $this->deleteProcessCachedPayload($staleCacheKey);
        $this->deleteProcessCachedFormattedResponse($this->buildFormattedFastHttpCacheKey($cacheKey));
        $this->deleteProcessCachedFormattedResponse($this->buildFormattedFastHttpCacheKey($staleCacheKey));
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
        return $this->canonicalizeFullUriForCacheKey($this->getRawFullUri());
    }

    private function canonicalizeFullUriForCacheKey(string $fullUri): string
    {
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

    private function resolveAtomicCacheAdapter(string $poolIdentity = 'router'): ?AtomicCacheAdapterInterface
    {
        // An explicitly supplied pool is the complete cache boundary for
        // isolated construction (for example a module-owned adapter or a
        // focused harness). Production ObjectManager construction supplies
        // neither nullable dependency, so non-router locks still resolve the
        // dedicated single_flight pool through CacheManager.
        $useInjectedPool = $poolIdentity === 'router'
            || ($this->cachePool !== null && $this->cacheManager === null);
        $cache = $useInjectedPool
            ? $this->cache()
            : $this->cacheManager()->pool($poolIdentity);
        if (!\method_exists($cache, 'getAdapter')) {
            return null;
        }

        $adapter = $cache->getAdapter();
        return $adapter instanceof AtomicCacheAdapterInterface ? $adapter : null;
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
