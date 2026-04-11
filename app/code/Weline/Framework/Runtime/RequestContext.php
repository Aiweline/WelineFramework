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
    private const START_TIME_PATH = 'runtime.request_context.start_time';
    private const INITIALIZED_PATH = 'runtime.request_context.initialized';

    public static function init(): void
    {
        $context = self::ensureContext(true);
        $context->set(self::STORAGE_PATH, []);
        $context->set(self::CLEANUP_PATH, []);
        $context->set(self::REQUEST_ID_PATH, self::generateRequestId());
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
        $preferContextServer = (bool)$context->get(self::INITIALIZED_PATH, false);
        $server = $preferContextServer ? $context->server() : (\is_array($_SERVER ?? null) ? $_SERVER : $context->server());
        if (!\is_array($server) || $server === []) {
            $server = $preferContextServer
                ? (\is_array($_SERVER ?? null) ? $_SERVER : [])
                : $context->server();
        }
        if (!\is_array($server)) {
            $server = [];
        }
        $context->set('input.server', $server);

        $area = (string)(self::get('env.area', $context->get('route.area', $server['WELINE_AREA'] ?? self::AREA_FRONTEND)) ?: self::AREA_FRONTEND);
        $areaRoute = (string)(self::get('env.area_route', $context->get('route.area_route', $server['WELINE_AREA_ROUTE'] ?? '')) ?? '');
        $websiteId = (int)(self::get('env.website_id', $context->get('route.website_id', $server['WELINE_WEBSITE_ID'] ?? 0)) ?: 0);
        $websiteCode = (string)(self::get('env.website_code', $context->get('route.website_code', $server['WELINE_WEBSITE_CODE'] ?? '')) ?? '');
        $websiteUrl = (string)(self::get('env.website_url', $context->get('route.website_url', $server['WELINE_WEBSITE_URL'] ?? '')) ?? '');
        $userLang = (string)(self::get('env.user.lang', $context->get('route.language', $server['WELINE_USER_LANG'] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN');
        $userCurrency = (string)(self::get('env.user.currency', $context->get('route.currency', $server['WELINE_USER_CURRENCY'] ?? 'CNY')) ?: 'CNY');

        $context->set('route.area', $area);
        $context->set('route.area_route', $areaRoute);
        $context->set('route.website_id', $websiteId);
        $context->set('route.website_code', $websiteCode);
        $context->set('route.website_url', $websiteUrl);
        $context->set('route.language', $userLang);
        $context->set('route.currency', $userCurrency);

        self::set('env.area', $area);
        self::set('env.area_route', $areaRoute);
        self::set('env.website_id', (string)$websiteId);
        self::set('env.website_code', $websiteCode);
        self::set('env.website_url', $websiteUrl);
        self::set('env.user.lang', $userLang);
        self::set('env.user.currency', $userCurrency);
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
        }

        $_SERVER['WELINE_AREA'] = self::AREA_FRONTEND;
        $_SERVER['WELINE_AREA_ROUTE'] = '';
        $_SERVER['WELINE_IS_BACKEND'] = false;
        unset($_SERVER['WELINE_WEBSITE_ID'], $_SERVER['WELINE_WEBSITE_CODE'], $_SERVER['WELINE_WEBSITE_URL'], $_SERVER['WELINE_USER_LANG'], $_SERVER['WELINE_USER_CURRENCY']);
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
}
