<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;

/**
 * Compatibility facade over the single framework Context.
 *
 * The long-term target is to remove direct RequestContext usage, but during the
 * refactor this keeps the rest of the framework running while centralizing
 * actual request state into Context.
 */
class RequestContext
{
    public const AREA_FRONTEND = 'frontend';
    public const AREA_BACKEND = 'backend';
    public const AREA_REST_FRONTEND = 'rest_frontend';
    public const AREA_REST_BACKEND = 'rest_backend';

    public const SSE_WRITER_KEY = 'framework.sse.writer';

    private const STORAGE_PATH = 'runtime.request_context.storage';
    private const CLEANUP_PATH = 'runtime.request_context.cleanup_callbacks';
    private const REQUEST_ID_PATH = 'runtime.request_context.request_id';
    private const CONNECTION_ID_PATH = 'runtime.request_context.connection_id';
    private const START_TIME_PATH = 'runtime.request_context.start_time';
    private const INITIALIZED_PATH = 'runtime.request_context.initialized';

    public static function init(): void
    {
        $context = self::ensureContext(true);
        $context->set(self::STORAGE_PATH, []);
        $context->set(self::CLEANUP_PATH, []);
        $requestId = self::generateRequestId();
        $connectionId = self::isWlsRequestContext($context)
            ? self::resolveConnectionId($context, (array)$context->get('input.server', []))
            : null;
        if ($connectionId === null) {
            $connectionId = $requestId;
        }
        $context->set(self::CONNECTION_ID_PATH, $connectionId);
        $context->set('runtime.connection_id', $connectionId ?? '');
        $context->set('runtime.chain_id', self::buildChainId($context, $connectionId));
        $context->set(self::REQUEST_ID_PATH, $requestId);
        $context->set(self::START_TIME_PATH, \microtime(true));
        $context->set(self::INITIALIZED_PATH, true);

        self::syncFromServer();
    }

    public static function getRequestId(): ?string
    {
        return self::getId();
    }

    public static function getId(): ?string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return null;
        }

        $id = $context->get(self::REQUEST_ID_PATH, null);
        return $id === null ? null : (string)$id;
    }

    public static function setId(?string $id): void
    {
        $context = self::ensureContext();
        $context->set(self::REQUEST_ID_PATH, $id);
        $context->set(self::INITIALIZED_PATH, $id !== null);
    }

    public static function getConnectionId(): ?string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return null;
        }

        $id = $context->get(self::CONNECTION_ID_PATH, null);
        return $id === null || $id === '' ? null : (string)$id;
    }

    public static function setConnectionId(?string $id): void
    {
        $context = self::ensureContext();
        $normalized = self::normalizeScopeId($id);
        $context->set(self::CONNECTION_ID_PATH, $normalized);
        $context->set('runtime.connection_id', $normalized ?? '');
        $context->set('runtime.chain_id', self::buildChainId($context, $normalized));
        if ($normalized !== null) {
            $_SERVER['WELINE_CONNECTION_ID'] = $normalized;
        } else {
            unset($_SERVER['WELINE_CONNECTION_ID']);
        }
    }

    public static function getChainId(): ?string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return null;
        }

        $chainId = $context->get('runtime.chain_id', null);
        if (\is_string($chainId) && $chainId !== '') {
            return $chainId;
        }

        return self::getStorageScopeId();
    }

    public static function getStorageScopeId(): ?string
    {
        return self::getConnectionId();
    }

    public static function getStartTime(): float
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return 0.0;
        }

        return (float)$context->get(self::START_TIME_PATH, 0.0);
    }

    public static function getElapsedMs(): float
    {
        return (\microtime(true) - self::getStartTime()) * 1000;
    }

    public static function set(string $key, mixed $value): void
    {
        $context = self::ensureContext();
        $storage = (array)$context->get(self::STORAGE_PATH, []);
        $storage[$key] = $value;
        $context->set(self::STORAGE_PATH, $storage);
        $context->set(self::INITIALIZED_PATH, true);
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return $default;
        }

        $storage = (array)$context->get(self::STORAGE_PATH, []);
        return $storage[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return false;
        }

        $storage = (array)$context->get(self::STORAGE_PATH, []);
        return \array_key_exists($key, $storage);
    }

    public static function remove(string $key): void
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return;
        }

        $storage = (array)$context->get(self::STORAGE_PATH, []);
        unset($storage[$key]);
        $context->set(self::STORAGE_PATH, $storage);
    }

    public static function all(): array
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return [];
        }

        return (array)$context->get(self::STORAGE_PATH, []);
    }

    public static function onCleanup(callable $callback, ?string $name = null): void
    {
        $context = self::ensureContext();
        $callbacks = (array)$context->get(self::CLEANUP_PATH, []);
        if ($name !== null) {
            $callbacks[$name] = $callback;
        } else {
            $callbacks[] = $callback;
        }
        $context->set(self::CLEANUP_PATH, $callbacks);
        $context->set(self::INITIALIZED_PATH, true);
    }

    public static function cleanup(): void
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return;
        }

        $callbacks = (array)$context->get(self::CLEANUP_PATH, []);
        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                w_log_error('[RequestContext] Cleanup callback error: ' . $e->getMessage());
            }
        }

        $context->set(self::STORAGE_PATH, []);
        $context->set(self::CLEANUP_PATH, []);
        $context->set(self::REQUEST_ID_PATH, null);
        $context->set(self::CONNECTION_ID_PATH, null);
        $context->set(self::START_TIME_PATH, 0.0);
        $context->set(self::INITIALIZED_PATH, false);
        self::resetWelineVars();
    }

    public static function isInitialized(): bool
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return false;
        }

        return (bool)$context->get(self::INITIALIZED_PATH, false);
    }

    public static function getWelineArea(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return self::AREA_FRONTEND;
        }

        return (string)$context->get('route.area', self::AREA_FRONTEND);
    }

    public static function setWelineArea(string $area): void
    {
        $context = self::ensureContext();
        $context->set('route.area', $area);
        self::set('env.area', $area);
        $_SERVER['WELINE_AREA'] = $area;
    }

    public static function getWelineAreaRoute(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        return (string)$context->get('route.area_route', '');
    }

    public static function setWelineAreaRoute(string $route): void
    {
        $context = self::ensureContext();
        $context->set('route.area_route', $route);
        self::set('env.area_route', $route);
        $_SERVER['WELINE_AREA_ROUTE'] = $route;
    }

    public static function getWelineWebsiteId(): int
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return 0;
        }

        return (int)$context->get('route.website_id', 0);
    }

    public static function setWelineWebsiteId(int $websiteId): void
    {
        $context = self::ensureContext();
        $context->set('route.website_id', $websiteId);
        self::set('env.website_id', (string)$websiteId);
        $_SERVER['WELINE_WEBSITE_ID'] = (string)$websiteId;
    }

    public static function getWelineWebsiteCode(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        return (string)$context->get('route.website_code', '');
    }

    public static function setWelineWebsiteCode(string $code): void
    {
        $context = self::ensureContext();
        $context->set('route.website_code', $code);
        self::set('env.website_code', $code);
        $_SERVER['WELINE_WEBSITE_CODE'] = $code;
    }

    public static function getWelineWebsiteUrl(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        return (string)$context->get('route.website_url', '');
    }

    public static function setWelineWebsiteUrl(string $url): void
    {
        $context = self::ensureContext();
        $context->set('route.website_url', $url);
        self::set('env.website_url', $url);
        $_SERVER['WELINE_WEBSITE_URL'] = $url;
    }

    public static function getWelineUserLang(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return 'zh_Hans_CN';
        }

        return (string)$context->get('route.language', 'zh_Hans_CN');
    }

    public static function setWelineUserLang(string $lang): void
    {
        $context = self::ensureContext();
        $context->set('route.language', $lang);
        self::set('env.user.lang', $lang);
        $_SERVER['WELINE_USER_LANG'] = $lang;
    }

    public static function getWelineUserCurrency(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return 'CNY';
        }

        return (string)$context->get('route.currency', 'CNY');
    }

    public static function setWelineUserCurrency(string $currency): void
    {
        $context = self::ensureContext();
        $context->set('route.currency', $currency);
        self::set('env.user.currency', $currency);
        $_SERVER['WELINE_USER_CURRENCY'] = $currency;
    }

    public static function isBackendArea(): bool
    {
        $area = self::getWelineArea();
        return $area === self::AREA_BACKEND || $area === self::AREA_REST_BACKEND;
    }

    public static function syncFromServer(): void
    {
        $context = self::ensureContext(true);
        $server = \is_array($_SERVER ?? null) ? $_SERVER : [];
        self::syncSnapshot($context, $server, false);
    }

    public static function syncFromContext(?Context $context = null): void
    {
        $context ??= self::ensureContext(true);
        $server = (array)$context->get('input.server', []);
        if ($server === []) {
            $server = \is_array($_SERVER ?? null) ? $_SERVER : [];
        }

        self::syncSnapshot($context, $server, true);
    }

    public static function resetWelineVars(): void
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            $context->set('input.query', []);
            $context->set('input.post', []);
            $context->set('input.cookie', []);
            $context->set('input.files', []);
            $context->set('input.headers', []);
            $context->set('input.server', []);
            $context->set('input.uri', '/');
            $context->set('input.origin_request_uri', '/');
            $context->set('input.full_request_uri', '');
            $context->set('input.method', 'GET');
            $context->set('input.scheme', 'http');
            $context->set('input.host', '');
            $context->set('input.ip', '');
            $context->set('route.area', self::AREA_FRONTEND);
            $context->set('route.area_route', '');
            $context->set('route.website_id', 0);
            $context->set('route.website_code', '');
            $context->set('route.website_url', '');
            $context->set('route.language', 'zh_Hans_CN');
            $context->set('route.currency', 'CNY');
            $context->set('route.is_backend', false);
            $context->set('route.is_static', false);
            $context->set('route.is_media', false);
            $context->set('route.url_parsed', false);
            $context->set('runtime.connection_id', '');
            $context->set('runtime.chain_id', '');
        }

        $_SERVER['WELINE_AREA'] = self::AREA_FRONTEND;
        $_SERVER['WELINE_AREA_ROUTE'] = '';
        $_SERVER['WELINE_IS_BACKEND'] = false;
        unset($_SERVER['WELINE_WEBSITE_ID'], $_SERVER['WELINE_WEBSITE_CODE'], $_SERVER['WELINE_WEBSITE_URL'], $_SERVER['WELINE_USER_LANG'], $_SERVER['WELINE_USER_CURRENCY'], $_SERVER['WELINE_CONNECTION_ID']);
    }

    public static function websiteId(?int $websiteId = null): ?int
    {
        if ($websiteId !== null) {
            self::setWelineWebsiteId($websiteId);
        }

        $value = self::getWelineWebsiteId();
        return $value === 0 ? null : $value;
    }

    public static function locale(?string $locale = null): ?string
    {
        if ($locale !== null) {
            self::setWelineUserLang($locale);
        }

        return self::getWelineUserLang();
    }

    public static function currency(?string $currency = null): ?string
    {
        if ($currency !== null) {
            self::setWelineUserCurrency($currency);
        }

        return self::getWelineUserCurrency();
    }

    public static function area(?string $area = null): ?string
    {
        if ($area !== null) {
            self::setWelineArea($area);
        }

        return self::getWelineArea();
    }

    private static function ensureContext(bool $hydrateFromGlobals = false): Context
    {
        $context = Context::getCurrent();
        if ($context !== null) {
            return $context;
        }

        $context = $hydrateFromGlobals ? Context::fromGlobals() : new Context();
        Context::enter($context);
        return $context;
    }

    private static function generateRequestId(): string
    {
        if (\function_exists('hrtime')) {
            return \bin2hex(\random_bytes(8)) . '-' . \hrtime(true);
        }

        return \bin2hex(\random_bytes(8)) . '-' . (int)(\microtime(true) * 1000000);
    }

    private static function syncSnapshot(Context $context, array $server, bool $preferContext): void
    {
        $routeArea = (string)$context->get('route.area', self::AREA_FRONTEND);
        $area = (string)(
            $preferContext
                ? ($routeArea ?: ($server['WELINE_AREA'] ?? self::AREA_FRONTEND))
                : (($server['WELINE_AREA'] ?? $routeArea) ?: self::AREA_FRONTEND)
        );
        if ($area === '') {
            $area = self::AREA_FRONTEND;
        }

        $areaRoute = (string)(
            $preferContext
                ? ($context->get('route.area_route', '') ?: ($server['WELINE_AREA_ROUTE'] ?? ''))
                : ($server['WELINE_AREA_ROUTE'] ?? $context->get('route.area_route', ''))
        );
        $websiteId = (int)(
            $preferContext
                ? ($context->get('route.website_id', $server['WELINE_WEBSITE_ID'] ?? 0) ?: 0)
                : (($server['WELINE_WEBSITE_ID'] ?? $context->get('route.website_id', 0)) ?: 0)
        );
        $websiteCode = (string)(
            $preferContext
                ? ($context->get('route.website_code', '') ?: ($server['WELINE_WEBSITE_CODE'] ?? ''))
                : ($server['WELINE_WEBSITE_CODE'] ?? $context->get('route.website_code', ''))
        );
        $websiteUrl = (string)(
            $preferContext
                ? ($context->get('route.website_url', '') ?: ($server['WELINE_WEBSITE_URL'] ?? ''))
                : ($server['WELINE_WEBSITE_URL'] ?? $context->get('route.website_url', ''))
        );
        $userLang = (string)(
            $preferContext
                ? ($context->get('route.language', 'zh_Hans_CN') ?: ($server['WELINE_USER_LANG'] ?? 'zh_Hans_CN'))
                : (($server['WELINE_USER_LANG'] ?? $context->get('route.language', 'zh_Hans_CN')) ?: 'zh_Hans_CN')
        );
        $userCurrency = (string)(
            $preferContext
                ? ($context->get('route.currency', 'CNY') ?: ($server['WELINE_USER_CURRENCY'] ?? 'CNY'))
                : (($server['WELINE_USER_CURRENCY'] ?? $context->get('route.currency', 'CNY')) ?: 'CNY')
        );
        $isBackend = \array_key_exists('WELINE_IS_BACKEND', $server)
            ? (bool)$server['WELINE_IS_BACKEND']
            : (bool)$context->get('route.is_backend', \in_array($area, [self::AREA_BACKEND, self::AREA_REST_BACKEND], true));
        if ($preferContext && $context->has('route.is_backend')) {
            $isBackend = (bool)$context->get('route.is_backend', $isBackend);
        }
        $isStatic = \array_key_exists('WELINE_IS_STATIC_FILE', $server)
            ? (bool)$server['WELINE_IS_STATIC_FILE']
            : (bool)$context->get('route.is_static', false);
        if ($preferContext && $context->has('route.is_static')) {
            $isStatic = (bool)$context->get('route.is_static', $isStatic);
        }
        $isMedia = \array_key_exists('WELINE_IS_MEDIA', $server)
            ? (bool)$server['WELINE_IS_MEDIA']
            : (bool)$context->get('route.is_media', false);
        if ($preferContext && $context->has('route.is_media')) {
            $isMedia = (bool)$context->get('route.is_media', $isMedia);
        }
        $urlParsed = \array_key_exists('WELINE_URL_PARSED', $server)
            ? (bool)$server['WELINE_URL_PARSED']
            : (bool)$context->get('route.url_parsed', false);
        if ($preferContext && $context->has('route.url_parsed')) {
            $urlParsed = (bool)$context->get('route.url_parsed', $urlParsed);
        }

        // preferContext=true 时不能优先用 input.uri：WLS 下 Url::parser 已把剥前缀后的路径写进
        // input.server.REQUEST_URI，但 input.uri 可能仍是入口阶段（如 WlsRuntime）写入的旧值，
        // 若此处优先 input.uri，会把错误 URI 写回 $_SERVER，导致 FPM 正常、WLS 路由 404。
        $uri = (string)(
            $preferContext
                ? (($server['REQUEST_URI'] ?? '') !== ''
                    ? (string)$server['REQUEST_URI']
                    : ($context->get('input.uri', '/') ?: '/'))
                : (($server['REQUEST_URI'] ?? $context->get('input.uri', '/')) ?: '/')
        );
        if ($uri === '') {
            $uri = '/';
        }
        $method = (string)(
            $preferContext
                ? ($context->get('input.method', 'GET') ?: ($server['REQUEST_METHOD'] ?? 'GET'))
                : (($server['REQUEST_METHOD'] ?? $context->get('input.method', 'GET')) ?: 'GET')
        );
        $scheme = (string)(
            $preferContext
                ? ($context->get('input.scheme', 'http') ?: ($server['REQUEST_SCHEME'] ?? 'http'))
                : (($server['REQUEST_SCHEME'] ?? $context->get('input.scheme', 'http')) ?: 'http')
        );
        $host = (string)(
            $preferContext
                ? ($context->get('input.host', '') ?: ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''))
                : (($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? $context->get('input.host', '')) ?: '')
        );
        $ip = (string)(
            $preferContext
                ? ($context->get('input.ip', '') ?: ($server['REMOTE_ADDR'] ?? ''))
                : (($server['REMOTE_ADDR'] ?? $context->get('input.ip', '')) ?: '')
        );
        $originRequestUri = (string)(
            $preferContext
                ? ($context->get('input.origin_request_uri', $uri) ?: ($server['WELINE_ORIGIN_REQUEST_URI'] ?? $uri))
                : (($server['WELINE_ORIGIN_REQUEST_URI'] ?? $context->get('input.origin_request_uri', $uri)) ?: $uri)
        );
        $fullRequestUri = (string)(
            $preferContext
                ? ($context->get('input.full_request_uri', '') ?: ($server['WELINE_FULL_REQUEST_URI'] ?? ''))
                : (($server['WELINE_FULL_REQUEST_URI'] ?? $context->get('input.full_request_uri', '')) ?: '')
        );
        $connectionId = self::isWlsRequestContext($context)
            ? self::resolveConnectionId($context, $server)
            : self::normalizeScopeId($context->get(self::CONNECTION_ID_PATH, null));
        if ($connectionId === null && !self::isWlsRequestContext($context)) {
            $connectionId = self::normalizeScopeId($context->get(self::REQUEST_ID_PATH, null));
        }

        $server['REQUEST_URI'] = $uri;
        $server['REQUEST_METHOD'] = $method;
        $server['REQUEST_SCHEME'] = $scheme;
        $server['WELINE_ORIGIN_REQUEST_URI'] = $originRequestUri;
        $server['WELINE_FULL_REQUEST_URI'] = $fullRequestUri;
        $server['WELINE_AREA'] = $area;
        $server['WELINE_AREA_ROUTE'] = $areaRoute;
        $server['WELINE_WEBSITE_ID'] = (string)$websiteId;
        $server['WELINE_WEBSITE_CODE'] = $websiteCode;
        $server['WELINE_WEBSITE_URL'] = $websiteUrl;
        $server['WELINE_USER_LANG'] = $userLang;
        $server['WELINE_USER_CURRENCY'] = $userCurrency;
        $server['WELINE_IS_BACKEND'] = $isBackend;
        $server['WELINE_IS_STATIC_FILE'] = $isStatic;
        $server['WELINE_IS_MEDIA'] = $isMedia;
        $server['WELINE_URL_PARSED'] = $urlParsed;
        if ($connectionId !== null) {
            $server['WELINE_CONNECTION_ID'] = $connectionId;
        } else {
            unset($server['WELINE_CONNECTION_ID']);
        }
        if ($host !== '') {
            $server['HTTP_HOST'] = $host;
        }
        if ($ip !== '') {
            $server['REMOTE_ADDR'] = $ip;
        }

        $context->set('input.server', $server);
        $context->set('input.uri', $uri);
        $context->set('input.origin_request_uri', $originRequestUri);
        $context->set('input.full_request_uri', $fullRequestUri);
        $context->set('input.method', $method);
        $context->set('input.scheme', $scheme);
        $context->set('input.host', $host);
        $context->set('input.ip', $ip);
        $context->set('route.area', $area);
        $context->set('route.area_route', $areaRoute);
        $context->set('route.path', (string)(\parse_url($uri, \PHP_URL_PATH) ?: '/'));
        $context->set('route.website_id', $websiteId);
        $context->set('route.website_code', $websiteCode);
        $context->set('route.website_url', $websiteUrl);
        $context->set('route.language', $userLang);
        $context->set('route.currency', $userCurrency);
        $context->set('route.is_backend', $isBackend);
        $context->set('route.is_static', $isStatic);
        $context->set('route.is_media', $isMedia);
        $context->set('route.url_parsed', $urlParsed);
        $context->set(self::CONNECTION_ID_PATH, $connectionId);
        $context->set('runtime.connection_id', $connectionId ?? '');
        $context->set('runtime.chain_id', self::buildChainId($context, $connectionId));

        $_SERVER = \array_replace(\is_array($_SERVER ?? null) ? $_SERVER : [], $server);

        self::set('env.area', $area);
        self::set('env.area_route', $areaRoute);
        self::set('env.website_id', (string)$websiteId);
        self::set('env.website_code', $websiteCode);
        self::set('env.website_url', $websiteUrl);
        self::set('env.user.lang', $userLang);
        self::set('env.user.currency', $userCurrency);
    }

    private static function resolveConnectionId(Context $context, array $server = []): ?string
    {
        $candidates = [
            $context->get(self::CONNECTION_ID_PATH, null),
            $context->get('runtime.connection_id', null),
            $context->getRuntimeAttr('connection_id', null),
            $server['WELINE_CONNECTION_ID'] ?? null,
            $_SERVER['WELINE_CONNECTION_ID'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeScopeId($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private static function normalizeScopeId(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $normalized = \trim((string)$id);
        return $normalized === '' ? null : $normalized;
    }

    private static function isWlsRequestContext(Context $context): bool
    {
        return (string)$context->get('meta.type', '') === 'request'
            && (string)$context->get('meta.mode', '') === 'wls';
    }

    private static function buildChainId(Context $context, ?string $connectionId): string
    {
        if ($connectionId !== null) {
            return $connectionId;
        }

        return (string)($context->get(self::REQUEST_ID_PATH, '') ?? '');
    }
}
