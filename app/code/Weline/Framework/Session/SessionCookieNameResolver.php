<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Context;
use Weline\Framework\Http\CookieScope;

/**
 * Resolves the framework Session cookie name for the active request.
 *
 * Browser cookies are scoped by host and path, not by port. Dedicated WLS
 * instances on the same host therefore need a port-qualified name, while
 * standard HTTP/HTTPS deployments retain the historical name.
 *
 * Request cookie-scope modules (via {@see CookieScope::EVENT_RESOLVE}) may
 * further qualify the name/path so sibling mounts on one host stay isolated.
 *
 * SameSite follows the same authority: HTTPS non-standard ports use
 * CHIPS (`SameSite=None; Partitioned`) so embedded browsers keep the cookie.
 */
final class SessionCookieNameResolver
{
    public const LEGACY_NAME = 'WELINE_SESSID';

    public static function resolve(?string $host = null): string
    {
        return self::resolveFor(self::LEGACY_NAME, $host);
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

    public static function hasRequestCookie(): bool
    {
        return self::readRequestSessionId() !== '';
    }

    /**
     * Session cookie wire names that may carry the active login for this request.
     *
     * Document navigations can emit the authority-qualified name before
     * CookieScope is active (`WELINE_SESSID_9555`), while QueryBin often
     * starts after website detection and uses the scoped name
     * (`WELINE_SESSID_9555_w0`) while expiring the unscoped alias. Readers
     * must accept every candidate so login state is not split across jars.
     *
     * @return list<string>
     */
    public static function requestCookieCandidates(?string $host = null): array
    {
        $names = [
            self::resolve($host),
            self::resolveUnscopedFor(self::LEGACY_NAME, $host),
            self::LEGACY_NAME,
        ];

        $cookies = Context::getCurrent()?->get('input.cookie', []) ?? [];
        if (\is_array($cookies)) {
            foreach (\array_keys($cookies) as $name) {
                if (!\is_string($name) || $name === '') {
                    continue;
                }
                if (\preg_match('/^WELINE_SESSID(?:_[1-9]\d{0,4})?(?:_w\d+)?$/D', $name) !== 1) {
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
    public static function readRequestSessionId(?string $host = null): string
    {
        $cookies = Context::getCurrent()?->get('input.cookie', []) ?? [];
        if (!\is_array($cookies)) {
            $cookies = [];
        }

        foreach (self::requestCookieCandidates($host) as $name) {
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
