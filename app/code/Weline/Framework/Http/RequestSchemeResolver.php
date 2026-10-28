<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Env\WelineEnv;

/**
 * Single source of truth for the *public* request scheme.
 *
 * Behind Cloudflare + a managed Nginx reverse proxy, PHP only sees HTTP/1.1 over
 * plain TCP: `REQUEST_SCHEME`/`HTTPS` describe the proxy hop, not the visitor's URL.
 * Reading them directly is how `http://` leaked into og:url / og:image / canonical /
 * JSON-LD (theme-spec-gates S8 companion, reported as E7 in the 2026-10-28 audit).
 *
 * Every SEO/GEO consumer must call this instead of reading HTTPS by hand.
 */
final class RequestSchemeResolver
{
    public static function current(): string
    {
        return self::isHttps() ? 'https' : 'http';
    }

    public static function isHttps(): bool
    {
        // Cloudflare: CF-VISITOR: {"scheme":"https"}
        $cfVisitor = (string)WelineEnv::server('HTTP_CF_VISITOR', '');
        if ($cfVisitor !== '') {
            if (\preg_match('/"scheme"\s*:\s*"([a-z]+)"/i', $cfVisitor, $m) === 1) {
                return \strtolower($m[1]) === 'https';
            }
        }

        // Standard proxies: X-Forwarded-Proto may be a comma list ("https, http" = client hop first).
        $forwardedProto = (string)WelineEnv::server('HTTP_X_FORWARDED_PROTO', '');
        if ($forwardedProto !== '') {
            $first = \strtolower(\trim(\explode(',', $forwardedProto)[0] ?? ''));

            return $first === 'https';
        }

        $forwardedSsl = \strtolower(\trim((string)WelineEnv::server('HTTP_X_FORWARDED_SSL', '')));
        if ($forwardedSsl !== '') {
            return $forwardedSsl === 'on' || $forwardedSsl === '1';
        }

        $scheme = \strtolower(\trim((string)WelineEnv::server('REQUEST_SCHEME', '')));
        if ($scheme === 'https' || $scheme === 'http') {
            return $scheme === 'https';
        }

        $https = \strtolower(\trim((string)WelineEnv::server('HTTPS', '')));

        return $https !== '' && $https !== 'off' && $https !== '0';
    }

    /**
     * Normalize a URL's scheme to the public request scheme.
     * Absolute URLs only; relative input is returned unchanged.
     *
     * @param bool $forceHttps treat http as a proxy artifact (production policy)
     */
    public static function normalizeUrl(string $url, bool $forceHttps = false): string
    {
        if ($url === '' || \preg_match('#^https?://#i', $url) !== 1) {
            return $url;
        }
        $scheme = $forceHttps ? 'https' : self::current();

        return (string)\preg_replace('#^https?://#i', $scheme . '://', $url, 1);
    }
}
