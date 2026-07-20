<?php

declare(strict_types=1);

namespace Weline\Framework\Session;

use Weline\Framework\Context;

/**
 * Resolves the framework Session cookie name for the active request.
 *
 * Browser cookies are scoped by host and path, not by port. Dedicated WLS
 * instances on the same host therefore need a port-qualified name, while
 * standard HTTP/HTTPS deployments retain the historical name.
 *
 * SameSite follows the same authority: HTTPS non-standard ports use
 * CHIPS (`SameSite=None; Partitioned`) so embedded browsers keep the cookie.
 */
final class SessionCookieNameResolver
{
    public const LEGACY_NAME = 'WELINE_SESSID';

    public static function resolve(?string $host = null): string
    {
        $host = $host ?? self::currentHost();
        $port = self::extractPort($host);
        if ($port === null || $port === 80 || $port === 443) {
            return self::LEGACY_NAME;
        }

        return self::LEGACY_NAME . '_' . $port;
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
        $cookies = Context::getCurrent()?->get('input.cookie', []) ?? [];
        if (!\is_array($cookies)) {
            return false;
        }

        $value = $cookies[self::resolve()] ?? null;
        return \is_string($value) && \trim($value) !== '';
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

        // Prefer the HTTP authority because it normally retains an explicit
        // non-standard port. Some HTTP/2 and HTTP/3 request envelopes expose
        // only a host name, so fall back to the validated listener port.
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

        $serverPort = $context->get('input.server.SERVER_PORT');
        if (
            !is_int($serverPort)
            && !(is_string($serverPort) && $serverPort !== '' && ctype_digit($serverPort))
        ) {
            return $host;
        }

        $port = (int)$serverPort;
        if ($port < 1 || $port > 65535 || $port === 80 || $port === 443) {
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
