<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;
use Weline\Framework\Runtime\RequestContext;

/**
 * Resolves the framework Session cookie name for the active request.
 *
 * Browser cookies are scoped by host and path, not by port. Dedicated WLS
 * instances on the same host therefore need a port-qualified name, while
 * standard HTTP/HTTPS deployments retain the historical name.
 *
 * Customer (storefront) auth uses {@see CUSTOMER_NAME}; admin/backend keeps
 * {@see LEGACY_NAME}. Request cookie-scope modules (via {@see CookieScope::EVENT_RESOLVE})
 * may further qualify the name/path so sibling mounts on one host stay isolated.
 *
 * SameSite follows the same authority: HTTPS non-standard ports use
 * CHIPS (`SameSite=None; Partitioned`) so embedded browsers keep the cookie.
 */
final class SessionCookieNameResolver
{
    /** Admin / backend Session cookie base name. */
    public const LEGACY_NAME = 'WELINE_SESSID';

    /** Customer / storefront Session cookie base name (isolated from admin). */
    public const CUSTOMER_NAME = 'WELINE_CUSTOMER_SESSID';

    public static function resolve(?string $host = null, ?string $area = null): string
    {
        return self::resolveFor(self::legacyNameForArea($area), $host);
    }

    /** Resolve a host cookie name for the active request authority + cookie scope. */
    public static function resolveFor(string $legacyName, ?string $host = null): string
    {
        return CookieScope::qualifyName(self::resolveUnscopedFor($legacyName, $host));
    }

    /**
     * Resolve the authority-qualified name before a module cookie scope suffix.
     *
     * Trusted realm bridges use this only to locate a previously attested
     * Session when their API route has already entered another cookie scope.
     */
    public static function resolveUnscopedFor(string $legacyName, ?string $host = null): string
    {
        $legacyName = trim($legacyName);
        if ($legacyName === '') {
            return '';
        }

        $host = $host ?? self::currentHost();
        $port = self::extractPort($host);
        $name = $legacyName;
        if ($port !== null && $port !== 80 && $port !== 443) {
            $name .= '_' . $port;
        }

        return $name;
    }

    /**
     * Cookie base name for an auth/session area.
     *
     * Frontend / storefront-facing areas use the customer family so shopper
     * login cannot share a jar with admin WELINE_SESSID. With no explicit area
     * and no request context, keep historical {@see LEGACY_NAME}.
     */
    public static function legacyNameForArea(?string $area = null): string
    {
        $area = \strtolower(\trim((string)$area));
        if ($area === '') {
            try {
                if (\class_exists(RequestContext::class, false)
                    && \class_exists(Context::class, false)) {
                    $context = Context::getCurrent();
                    if ($context !== null && $context->has('route.area')) {
                        $fromRequest = \strtolower(\trim((string)$context->get('route.area', '')));
                        if ($fromRequest !== '') {
                            $area = $fromRequest;
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }
        if ($area === '') {
            return self::LEGACY_NAME;
        }

        return self::usesCustomerCookieFamily($area) ? self::CUSTOMER_NAME : self::LEGACY_NAME;
    }

    public static function usesCustomerCookieFamily(?string $area = null): bool
    {
        $area = \strtolower(\trim((string)$area));
        if ($area === '') {
            return false;
        }
        return match ($area) {
            'frontend', 'api', 'checkout', 'rest_frontend' => true,
            default => false,
        };
    }

    /**
     * Resolve area for cookie family: explicit arg, else request WELINE_AREA, else frontend.
     */
    public static function normalizeArea(?string $area = null): string
    {
        $area = \strtolower(\trim((string)$area));
        if ($area !== '') {
            return $area;
        }
        try {
            if (\class_exists(RequestContext::class, false)) {
                $fromRequest = \strtolower(\trim(RequestContext::getWelineArea()));
                if ($fromRequest !== '') {
                    return $fromRequest;
                }
            }
        } catch (\Throwable) {
        }

        return 'frontend';
    }

    private static function normalizeTcpPort(mixed $value): ?int
    {
        if (!\is_int($value) && !(\is_string($value) && $value !== '' && \ctype_digit($value))) {
            return null;
        }
        $port = (int)$value;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return $port;
    }

    /**
     * Cookie Path for the session cookie under the active cookie scope.
     */
    public static function resolvePath(string $configuredPath = '/'): string
    {
        return CookieScope::resolvePath($configuredPath);
    }

    /**
     * Resolve SameSite for the active request authority.
     *
     * Uses the same host/port authority as {@see resolve()} (including
     * SERVER_PORT fallback). Must be called at cookie emission time — not
     * during worker warmup when request context is empty.
     */
    public static function resolveSameSite(
        ?bool $secure = null,
        ?string $configuredSameSite = null,
        mixed $configuredPartitioned = null,
    ): string {
        $secure ??= (\function_exists('w_env') && \w_env('server.https') === 'on');
        $configuredSameSite = \trim((string)($configuredSameSite ?? ''));

        if ($configuredPartitioned !== null) {
            if ($secure && (bool)$configuredPartitioned) {
                return 'None; Partitioned';
            }

            return $configuredSameSite !== '' ? $configuredSameSite : 'Lax';
        }

        if ($configuredSameSite !== '') {
            return $configuredSameSite;
        }

        if ($secure && self::isNonStandardHttpsPort(self::currentHost())) {
            return 'None; Partitioned';
        }

        return 'Lax';
    }

    public static function hasRequestCookie(?string $area = null): bool
    {
        return self::readRequestSessionId(null, $area) !== '';
    }

    /**
     * Session cookie wire names that may carry the active login for this area.
     *
     * Document navigations can emit the authority-qualified name before
     * CookieScope is active, while QueryBin often starts after website
     * detection and uses the scoped name while expiring the unscoped alias.
     * Candidates stay within one cookie family so customer Expire never
     * clears admin WELINE_SESSID*.
     *
     * @return list<string>
     */
    public static function requestCookieCandidates(?string $host = null, ?string $area = null): array
    {
        $legacyName = self::legacyNameForArea($area);
        $names = [
            self::resolveFor($legacyName, $host),
            self::resolveUnscopedFor($legacyName, $host),
            $legacyName,
        ];

        $pattern = self::familyPattern($legacyName);
        $cookies = Context::getCurrent()?->get('input.cookie', []) ?? [];
        if (\is_array($cookies)) {
            foreach (\array_keys($cookies) as $name) {
                if (!\is_string($name) || $name === '') {
                    continue;
                }
                if (\preg_match($pattern, $name) !== 1) {
                    continue;
                }
                $names[] = $name;
            }
        }

        $unique = [];
        foreach ($names as $name) {
            $name = \trim($name);
            if ($name === '' || isset($unique[$name])) {
                continue;
            }
            $unique[$name] = true;
        }

        return \array_keys($unique);
    }

    /**
     * First non-empty Session id from {@see requestCookieCandidates()}.
     */
    public static function readRequestSessionId(?string $host = null, ?string $area = null): string
    {
        $cookies = Context::getCurrent()?->get('input.cookie', []) ?? [];
        if (!\is_array($cookies)) {
            $cookies = [];
        }

        foreach (self::requestCookieCandidates($host, $area) as $name) {
            $value = $cookies[$name] ?? null;
            if (!\is_string($value) || \trim($value) === '') {
                if (\function_exists('w_env_cookie')) {
                    $value = \w_env_cookie($name);
                }
            }
            if (\is_string($value) && \trim($value) !== '') {
                return \trim($value);
            }
        }

        return '';
    }

    /**
     * Regex that matches only one Session cookie family (customer or admin).
     */
    public static function familyPattern(string $legacyName): string
    {
        $legacyName = \trim($legacyName);
        if ($legacyName === self::CUSTOMER_NAME) {
            return '/^WELINE_CUSTOMER_SESSID(?:_[1-9]\d{0,4})?(?:_w\d+)?$/D';
        }

        // Admin family: WELINE_SESSID… but never WELINE_CUSTOMER_SESSID…
        return '/^WELINE_SESSID(?:_[1-9]\d{0,4})?(?:_w\d+)?$/D';
    }

    /**
     * Current request authority, preferring an explicit non-standard port.
     */
    public static function currentHost(): string
    {
        $context = Context::getCurrent();
        if ($context === null) {
            return '';
        }

        // Prefer the normalized HTTP authority because it retains an explicit
        // non-standard port. WlsRequest owns public-origin normalization, so a
        // valid SERVER_PORT (including trusted-proxy 80/443) must never be
        // replaced by an internal WLS worker/listener port.
        $httpHost = trim((string)($context->get('input.server.HTTP_HOST', '') ?? ''));
        $inputHost = trim((string)($context->get('input.host', '') ?? ''));

        if ($httpHost !== '' && self::extractPort($httpHost) !== null) {
            return $httpHost;
        }
        if ($inputHost !== '' && self::extractPort($inputHost) !== null) {
            return $inputHost;
        }

        $host = $httpHost !== '' ? $httpHost : $inputHost;
        if ($host === '') {
            return '';
        }

        $port = self::normalizeTcpPort($context->get('input.server.SERVER_PORT'));
        if ($port === null) {
            $listenPort = self::normalizeTcpPort($context->get('input.server.WLS_PORT'));
            if ($listenPort === null) {
                $envListen = \getenv('WLS_PORT');
                $listenPort = self::normalizeTcpPort(\is_string($envListen) ? $envListen : null);
            }
            if ($listenPort !== null && $listenPort !== 80 && $listenPort !== 443) {
                $port = $listenPort;
            }
        }
        if ($port === null || $port === 80 || $port === 443) {
            return $host;
        }

        return $host . ':' . $port;
    }

    private static function isNonStandardHttpsPort(string $host): bool
    {
        $port = self::extractPort($host);
        return $port !== null && !\in_array($port, [80, 443], true);
    }

    private static function extractPort(string $host): ?int
    {
        $host = \trim($host);
        if ($host === '') {
            return null;
        }

        $port = \parse_url('https://' . \ltrim($host, '/'), \PHP_URL_PORT);
        if (!\is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }

        return $port;
    }
}
