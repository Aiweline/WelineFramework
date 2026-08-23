<?php
declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Context;
use Weline\Framework\Service\Query\Value\FrontendWorkerExecutionContext;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeBinding;

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
    private const SCOPE_IDENTITY_PATH = 'runtime.request_context.scope_identity';
    private const SCOPE_STORE_ID_PATH = 'runtime.request_context.scope_store_id';
    private const SCOPE_CHANNEL_ID_PATH = 'runtime.request_context.scope_channel_id';
    private const SCOPE_TIMEZONE_PATH = 'runtime.request_context.scope_timezone';
    private const STOREFRONT_ROUTE_PATH = 'runtime.request_context.storefront_route_path';
    private const LEGACY_STOREFRONT_ROUTE_PATH = 'route.storefront_path';

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

        $failures = [];
        $callbacks = (array)$context->get(self::CLEANUP_PATH, []);
        foreach ($callbacks as $name => $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $message = '[RequestContext] Cleanup callback error: ' . $e->getMessage();
                try {
                    if (\function_exists('w_log_error')) {
                        \w_log_error($message);
                    } else {
                        \error_log($message);
                    }
                } catch (\Throwable) {
                    // Logging must never mask the original cleanup failure.
                }
                RequestResetException::append(
                    $failures,
                    'cleanup_callback:' . (\is_string($name) ? $name : (string)$name),
                    $e,
                );
            }
        }

        try {
            $context->set(self::STORAGE_PATH, []);
            $context->set(self::CLEANUP_PATH, []);
            $context->set(self::REQUEST_ID_PATH, null);
            $context->set(self::CONNECTION_ID_PATH, null);
            $context->set(self::START_TIME_PATH, 0.0);
            $context->set(self::INITIALIZED_PATH, false);
            self::resetWelineVars();
        } catch (\Throwable $e) {
            RequestResetException::append($failures, 'context_projection_reset', $e);
        }

        if ($failures !== []) {
            throw new RequestResetException('request_context', $failures);
        }
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
        $identity = self::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            return $identity->websiteId ?? 0;
        }

        $context = Context::getCurrent();
        if ($context === null) {
            return 0;
        }

        return (int)$context->get('route.website_id', 0);
    }

    /** @deprecated Install a ScopeIdentity after pre-freeze request assembly. */
    public static function setWelineWebsiteId(int $websiteId): void
    {
        if (!self::allowFrozenScopeFieldWrite('website_id', $websiteId)) {
            return;
        }
        $context = self::ensureContext();
        $context->set('route.website_id', $websiteId);
        self::set('env.website_id', (string)$websiteId);
        $_SERVER['WELINE_WEBSITE_ID'] = (string)$websiteId;
    }

    public static function getWelineStoreId(): int
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return 0;
        }

        if (self::scopeIdentity() instanceof ScopeIdentity) {
            return (int)$context->get(
                self::SCOPE_STORE_ID_PATH,
                $context->get('route.store_id', 0),
            );
        }

        return (int)$context->get('route.store_id', 0);
    }

    /** @deprecated Install a ScopeIdentity after pre-freeze request assembly. */
    public static function setWelineStoreId(int $storeId): void
    {
        if (!self::allowFrozenScopeIdWrite('store_id', $storeId)) {
            return;
        }
        $context = self::ensureContext();
        $context->set('route.store_id', $storeId);
        self::set('env.store_id', (string)$storeId);
        $_SERVER['WELINE_STORE_ID'] = (string)$storeId;
    }

    public static function getWelineStoreCode(): string
    {
        $identity = self::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            return $identity->storeCode ?? '';
        }

        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        return (string)$context->get('route.store_code', '');
    }

    /** @deprecated Install a ScopeIdentity after pre-freeze request assembly. */
    public static function setWelineStoreCode(string $storeCode): void
    {
        if (!self::allowFrozenScopeFieldWrite('store_code', $storeCode)) {
            return;
        }
        $context = self::ensureContext();
        $context->set('route.store_code', $storeCode);
        self::set('env.store_code', $storeCode);
        $_SERVER['WELINE_STORE_CODE'] = $storeCode;
    }

    public static function getWelineStoreMode(): string
    {
        $identity = self::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            return $identity->storeMode ?? '';
        }

        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        return (string)$context->get('route.store_mode', '');
    }

    /** @deprecated Install a ScopeIdentity after pre-freeze request assembly. */
    public static function setWelineStoreMode(string $storeMode): void
    {
        if (!self::allowFrozenScopeFieldWrite('store_mode', $storeMode)) {
            return;
        }
        $context = self::ensureContext();
        $context->set('route.store_mode', $storeMode);
        self::set('env.store_mode', $storeMode);
        $_SERVER['WELINE_STORE_MODE'] = $storeMode;
    }

    public static function getWelineChannelId(): int
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return 0;
        }

        if (self::scopeIdentity() instanceof ScopeIdentity) {
            return (int)$context->get(
                self::SCOPE_CHANNEL_ID_PATH,
                $context->get('route.channel_id', 0),
            );
        }

        return (int)$context->get('route.channel_id', 0);
    }

    /** @deprecated Install a ScopeIdentity after pre-freeze request assembly. */
    public static function setWelineChannelId(int $channelId): void
    {
        if (!self::allowFrozenScopeIdWrite('channel_id', $channelId)) {
            return;
        }
        $context = self::ensureContext();
        $context->set('route.channel_id', $channelId);
        self::set('env.channel_id', (string)$channelId);
        $_SERVER['WELINE_CHANNEL_ID'] = (string)$channelId;
    }

    public static function getWelineChannelCode(): string
    {
        $identity = self::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            return $identity->channelCode ?? '';
        }

        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        return (string)$context->get('route.channel_code', '');
    }

    /** @deprecated Install a ScopeIdentity after pre-freeze request assembly. */
    public static function setWelineChannelCode(string $channelCode): void
    {
        if (!self::allowFrozenScopeFieldWrite('channel_code', $channelCode)) {
            return;
        }
        $context = self::ensureContext();
        $context->set('route.channel_code', $channelCode);
        self::set('env.channel_code', $channelCode);
        $_SERVER['WELINE_CHANNEL_CODE'] = $channelCode;
    }

    public static function installScopeIdentity(ScopeIdentity $identity): void
    {
        $context = self::ensureContext();
        $existing = self::scopeIdentity();
        if ($existing !== null && !$existing->equals($identity)) {
            throw new \LogicException(__('当前请求的 ScopeIdentity 已冻结，禁止二次改写'));
        }
        if ($existing !== null) {
            return;
        }

        $storeId = self::getWelineStoreId();
        $channelId = self::getWelineChannelId();

        // Clear every mutable compatibility projection before publishing the
        // immutable identity. Omitted segments must not inherit stale values.
        self::setWelineWebsiteId(0);
        self::setWelineWebsiteCode('');
        self::setWelineStoreId(0);
        self::setWelineStoreCode('');
        self::setWelineStoreMode('');
        self::setWelineChannelId(0);
        self::setWelineChannelCode('');

        if (!$identity->isGlobal()) {
            self::setWelineWebsiteId((int)$identity->websiteId);
            self::setWelineWebsiteCode((string)$identity->websiteCode);
            if ($identity->scopeKind === ScopeIdentity::KIND_STORE
                || $identity->scopeKind === ScopeIdentity::KIND_CHANNEL) {
                self::setWelineStoreId($storeId);
                self::setWelineStoreCode((string)$identity->storeCode);
                self::setWelineStoreMode((string)$identity->storeMode);
            }
            if ($identity->scopeKind === ScopeIdentity::KIND_CHANNEL) {
                self::setWelineChannelId($channelId);
                self::setWelineChannelCode((string)$identity->channelCode);
            }
        }

        $legacyScope = $identity->toLegacyScopeString();
        self::set(ScopeContext::KEY, $legacyScope);
        self::set(ScopeContext::LEGACY_KEY, $legacyScope);
        $context->set(self::SCOPE_STORE_ID_PATH, self::getWelineStoreId());
        $context->set(self::SCOPE_CHANNEL_ID_PATH, self::getWelineChannelId());
        $context->set(self::SCOPE_IDENTITY_PATH, $identity);
    }

    /**
     * Replace the navigation-derived Store/Channel only after QueryBin has
     * installed the matching server-constructed Worker execution context.
     *
     * The public API endpoint has no storefront Store path, so its initial
     * navigation Scope can only identify the Host's default Store. A current,
     * authoritative Worker binding may refine that Scope within the same
     * Website after token and Catalog revalidation. Cross-Website replacement,
     * non-REST use, or a binding that differs from the execution context is
     * always rejected.
     */
    public static function replaceScopeIdentityForTrustedWorker(
        FrontendWorkerScopeBinding $binding,
        int $storeId,
        int $channelId,
    ): void {
        $context = self::ensureContext();
        $existing = self::scopeIdentity();
        $replacement = $binding->scope;
        $executionContext = self::get(FrontendWorkerExecutionContext::REQUEST_CONTEXT_KEY);
        if (!$existing instanceof ScopeIdentity
            || $existing->scopeKind !== ScopeIdentity::KIND_CHANNEL
            || $replacement->scopeKind !== ScopeIdentity::KIND_CHANNEL
            || self::getWelineArea() !== self::AREA_REST_FRONTEND
            || !$executionContext instanceof FrontendWorkerExecutionContext
            || $executionContext->area !== FrontendWorkerExecutionContext::AREA_FRONTEND
            || !$executionContext->scopeBinding instanceof FrontendWorkerScopeBinding
            || !\hash_equals($executionContext->scopeBinding->digest(), $binding->digest())
            || $existing->websiteId !== $replacement->websiteId
            || !\hash_equals((string)$existing->websiteCode, (string)$replacement->websiteCode)
            || $storeId < 1
            || $channelId < 1) {
            throw new \LogicException('Trusted Worker Scope replacement precondition failed.');
        }
        if ($existing->equals($replacement)) {
            return;
        }

        // Temporarily unfreeze only the Scope projections. Request authority,
        // method, URI, locale/currency and every unrelated request value stay
        // intact. installScopeIdentity() then republishes one complete identity.
        $context->set(self::SCOPE_IDENTITY_PATH, null);
        $context->set(self::SCOPE_STORE_ID_PATH, null);
        $context->set(self::SCOPE_CHANNEL_ID_PATH, null);
        $context->set(self::STOREFRONT_ROUTE_PATH, null);
        $context->set(self::LEGACY_STOREFRONT_ROUTE_PATH, null);
        self::setWelineStoreId($storeId);
        self::setWelineChannelId($channelId);
        self::installScopeIdentity($replacement);
    }

    /**
     * Replace the navigation-derived Store only for a server-authorized frontend
     * preview. The caller must first validate its opaque preview credential and
     * rebuild the replacement from the authoritative Store catalog.
     *
     * CMS/Theme previews may switch between Stores that share a host and
     * therefore cannot rely on navigation alone. Keep this exception narrower
     * than a general scope setter: frontend only, Store identity only, and never
     * across Websites.
     */
    public static function replaceScopeIdentityForAuthorizedPreview(
        ScopeIdentity $replacement,
        int $storeId,
    ): void {
        $context = self::ensureContext();
        $existing = self::scopeIdentity();
        if (!$existing instanceof ScopeIdentity
            || $existing->isGlobal()
            || $replacement->scopeKind !== ScopeIdentity::KIND_STORE
            || self::getWelineArea() !== self::AREA_FRONTEND
            || $existing->websiteId !== $replacement->websiteId
            || !\hash_equals((string)$existing->websiteCode, (string)$replacement->websiteCode)
            || $storeId < 1
        ) {
            throw new \LogicException('Authorized preview Scope replacement precondition failed.');
        }
        if ($existing->equals($replacement)) {
            if (self::getWelineStoreId() !== $storeId) {
                throw new \LogicException('Authorized preview Store identity is inconsistent.');
            }
            return;
        }

        $context->set(self::SCOPE_IDENTITY_PATH, null);
        $context->set(self::SCOPE_STORE_ID_PATH, null);
        $context->set(self::SCOPE_CHANNEL_ID_PATH, null);
        $context->set(self::STOREFRONT_ROUTE_PATH, null);
        $context->set(self::LEGACY_STOREFRONT_ROUTE_PATH, null);
        self::setWelineStoreId($storeId);
        self::installScopeIdentity($replacement);
    }

    public static function scopeIdentity(): ?ScopeIdentity
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return null;
        }
        $identity = $context->get(self::SCOPE_IDENTITY_PATH, null);
        if ($identity instanceof ScopeIdentity) {
            return $identity;
        }
        if (is_array($identity)) {
            $identity = ScopeIdentity::fromArray($identity);
            $context->set(self::SCOPE_IDENTITY_PATH, $identity);
            return $identity;
        }
        return null;
    }

    public static function getWelineWebsiteCode(): string
    {
        $identity = self::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            return $identity->websiteCode ?? '';
        }

        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        return (string)$context->get('route.website_code', '');
    }

    /** @deprecated Install a ScopeIdentity after pre-freeze request assembly. */
    public static function setWelineWebsiteCode(string $code): void
    {
        if (!self::allowFrozenScopeFieldWrite('website_code', $code)) {
            return;
        }
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
        \Weline\Framework\App\State::resetLangLocalCache();
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
        \Weline\Framework\App\State::resetLangLocalCache();
    }

    public static function getWelineTimezone(): string
    {
        $context = Context::getCurrent();
        $timezone = $context === null
            ? ''
            : \trim((string)$context->get(
                self::SCOPE_TIMEZONE_PATH,
                $context->get('route.timezone', ''),
            ));

        return $timezone !== '' ? $timezone : \date_default_timezone_get();
    }

    public static function setWelineTimezone(string $timezone): void
    {
        $timezone = \trim($timezone);
        if ($timezone === '') {
            throw new \InvalidArgumentException('Request timezone cannot be empty.');
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Request timezone is invalid.', 0, $exception);
        }

        $context = self::ensureContext();
        $existing = \trim((string)$context->get(
            self::SCOPE_TIMEZONE_PATH,
            $context->get('route.timezone', ''),
        ));
        if ($existing !== '') {
            if (\hash_equals($existing, $timezone)) {
                return;
            }
            if (self::scopeIdentity() instanceof ScopeIdentity) {
                throw new \LogicException('The frozen request timezone cannot be changed.');
            }
        }

        $context->set(self::SCOPE_TIMEZONE_PATH, $timezone);
        $context->set('route.timezone', $timezone);
    }

    /**
     * Stable response-safe metadata for the currently frozen Storefront Scope.
     *
     * Tokens, signatures, opaque bootstrap ids, fingerprints and secrets are
     * intentionally absent. A null result means no Storefront Scope was frozen.
     *
     * @return array{scope_kind:string,website_id:int,website_code:string,store_id:int,store_code:string,store_mode:string,channel_id:int,channel_code:string,locale:string,currency:string,timezone:string,context_version:string}|null
     */
    public static function scopeMetadata(): ?array
    {
        $identity = self::scopeIdentity();
        if (!$identity instanceof ScopeIdentity || $identity->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            return null;
        }

        return [
            'scope_kind' => $identity->scopeKind,
            'website_id' => (int)$identity->websiteId,
            'website_code' => (string)$identity->websiteCode,
            'store_id' => self::getWelineStoreId(),
            'store_code' => (string)$identity->storeCode,
            'store_mode' => (string)$identity->storeMode,
            'channel_id' => self::getWelineChannelId(),
            'channel_code' => (string)$identity->channelCode,
            'locale' => self::getWelineUserLang(),
            'currency' => self::getWelineUserCurrency(),
            'timezone' => self::getWelineTimezone(),
            'context_version' => $identity->contextVersion,
        ];
    }

    public static function setStorefrontRoutePath(string $routePath): void
    {
        $identity = self::scopeIdentity();
        if (!$identity instanceof ScopeIdentity || $identity->scopeKind !== ScopeIdentity::KIND_CHANNEL) {
            throw new \LogicException('A complete ScopeIdentity must be installed before publishing its route path.');
        }

        $navigation = new StorefrontNavigationScope($identity, $routePath);
        $context = self::ensureContext();
        $existing = $context->get(
            self::STOREFRONT_ROUTE_PATH,
            $context->get(self::LEGACY_STOREFRONT_ROUTE_PATH, null),
        );
        if (\is_string($existing) && $existing !== '') {
            if (\hash_equals($existing, $navigation->routePath)) {
                return;
            }
            throw new \LogicException('The frozen Storefront route path cannot be changed.');
        }

        $context->set(self::STOREFRONT_ROUTE_PATH, $navigation->routePath);
        $context->set(self::LEGACY_STOREFRONT_ROUTE_PATH, $navigation->routePath);
    }

    public static function getStorefrontRoutePath(): ?string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return null;
        }

        $routePath = $context->get(
            self::STOREFRONT_ROUTE_PATH,
            $context->get(self::LEGACY_STOREFRONT_ROUTE_PATH, null),
        );
        return \is_string($routePath) && $routePath !== '' ? $routePath : null;
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
            $context->set('route.store_id', 0);
            $context->set('route.store_code', '');
            $context->set('route.store_mode', '');
            $context->set('route.channel_id', 0);
            $context->set('route.channel_code', '');
            $context->set(self::SCOPE_IDENTITY_PATH, null);
            $context->set(self::SCOPE_STORE_ID_PATH, null);
            $context->set(self::SCOPE_CHANNEL_ID_PATH, null);
            $context->set(\Weline\Framework\Runtime\ScopeContext::KEY, '');
            $context->set(\Weline\Framework\Runtime\ScopeContext::LEGACY_KEY, '');
            self::remove(\Weline\Framework\Runtime\ScopeContext::KEY);
            self::remove(\Weline\Framework\Runtime\ScopeContext::LEGACY_KEY);
            $context->set('route.language', 'zh_Hans_CN');
            $context->set('route.currency', 'CNY');
            $context->set('route.timezone', '');
            $context->set(self::SCOPE_TIMEZONE_PATH, null);
            $context->set(self::STOREFRONT_ROUTE_PATH, null);
            $context->set(self::LEGACY_STOREFRONT_ROUTE_PATH, null);
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
        unset(
            $_SERVER['WELINE_WEBSITE_ID'],
            $_SERVER['WELINE_WEBSITE_CODE'],
            $_SERVER['WELINE_WEBSITE_URL'],
            $_SERVER['WELINE_STORE_ID'],
            $_SERVER['WELINE_STORE_CODE'],
            $_SERVER['WELINE_STORE_MODE'],
            $_SERVER['WELINE_CHANNEL_ID'],
            $_SERVER['WELINE_CHANNEL_CODE'],
            $_SERVER['WELINE_USER_LANG'],
            $_SERVER['WELINE_USER_CURRENCY'],
            $_SERVER['WELINE_CONNECTION_ID'],
        );
        \Weline\Framework\App\State::resetLangLocalCache();
    }

    /**
     * @deprecated Passing a value is deprecated; install ScopeIdentity instead.
     *             Read access remains a canonical compatibility facade.
     */
    public static function websiteId(?int $websiteId = null): ?int
    {
        if ($websiteId !== null) {
            self::setWelineWebsiteId($websiteId);
        }

        $identity = self::scopeIdentity();
        if ($identity instanceof ScopeIdentity) {
            return $identity->websiteId;
        }

        // website_id=0 是合法系统默认站，不得转成 null；仅在无请求上下文时返回 null
        $context = Context::getCurrent();
        if ($context === null) {
            return null;
        }

        return (int)$context->get('route.website_id', 0);
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

    /**
     * A legacy scope setter may populate request assembly state before freeze.
     * After freeze, an equal call is an idempotent no-op and any conflict is
     * rejected so mutable compatibility projections cannot become a second
     * source of truth.
     */
    private static function allowFrozenScopeFieldWrite(string $field, int|string $candidate): bool
    {
        $identity = self::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            return true;
        }

        $canonical = match ($field) {
            'website_id' => $identity->websiteId,
            'website_code' => $identity->websiteCode ?? '',
            'store_code' => $identity->storeCode ?? '',
            'store_mode' => $identity->storeMode ?? '',
            'channel_code' => $identity->channelCode ?? '',
            default => throw new \LogicException('Unknown frozen ScopeIdentity field: ' . $field),
        };
        if ($candidate === $canonical) {
            return false;
        }

        throw new \LogicException(__('当前请求的 ScopeIdentity 已冻结，禁止二次改写'));
    }

    private static function allowFrozenScopeIdWrite(string $field, int $candidate): bool
    {
        $identity = self::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            return true;
        }

        $context = self::ensureContext();
        $canonical = match ($field) {
            'store_id' => (int)$context->get(
                self::SCOPE_STORE_ID_PATH,
                $context->get('route.store_id', 0),
            ),
            'channel_id' => (int)$context->get(
                self::SCOPE_CHANNEL_ID_PATH,
                $context->get('route.channel_id', 0),
            ),
            default => throw new \LogicException('Unknown frozen ScopeIdentity id field: ' . $field),
        };
        if ($candidate === $canonical) {
            return false;
        }

        throw new \LogicException(__('当前请求的 ScopeIdentity 已冻结，禁止二次改写'));
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
        $storeId = (int)self::snapshotRouteValue(
            $context,
            $server,
            'route.store_id',
            'WELINE_STORE_ID',
            0,
            $preferContext,
        );
        $storeCode = (string)self::snapshotRouteValue(
            $context,
            $server,
            'route.store_code',
            'WELINE_STORE_CODE',
            '',
            $preferContext,
        );
        $storeMode = (string)self::snapshotRouteValue(
            $context,
            $server,
            'route.store_mode',
            'WELINE_STORE_MODE',
            '',
            $preferContext,
        );
        $channelId = (int)self::snapshotRouteValue(
            $context,
            $server,
            'route.channel_id',
            'WELINE_CHANNEL_ID',
            0,
            $preferContext,
        );
        $channelCode = (string)self::snapshotRouteValue(
            $context,
            $server,
            'route.channel_code',
            'WELINE_CHANNEL_CODE',
            '',
            $preferContext,
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
        $server['WELINE_STORE_ID'] = (string)$storeId;
        $server['WELINE_STORE_CODE'] = $storeCode;
        $server['WELINE_STORE_MODE'] = $storeMode;
        $server['WELINE_CHANNEL_ID'] = (string)$channelId;
        $server['WELINE_CHANNEL_CODE'] = $channelCode;
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
        $context->set('route.store_id', $storeId);
        $context->set('route.store_code', $storeCode);
        $context->set('route.store_mode', $storeMode);
        $context->set('route.channel_id', $channelId);
        $context->set('route.channel_code', $channelCode);
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
        self::set('env.store_id', (string)$storeId);
        self::set('env.store_code', $storeCode);
        self::set('env.store_mode', $storeMode);
        self::set('env.channel_id', (string)$channelId);
        self::set('env.channel_code', $channelCode);
        self::set('env.user.lang', $userLang);
        self::set('env.user.currency', $userCurrency);
    }

    private static function snapshotRouteValue(
        Context $context,
        array $server,
        string $contextKey,
        string $serverKey,
        mixed $default,
        bool $preferContext,
    ): mixed {
        if ($preferContext) {
            if ($context->has($contextKey)) {
                return $context->get($contextKey, $default);
            }
            return array_key_exists($serverKey, $server) ? $server[$serverKey] : $default;
        }
        if (array_key_exists($serverKey, $server)) {
            return $server[$serverKey];
        }
        return $context->has($contextKey) ? $context->get($contextKey, $default) : $default;
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
