<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc;

use Weline\Framework\Runtime\RequestContext;

/**
 * Shared FPC identity aligned with engine disk live/{host}/{path}.
 *
 * SEO rewrite / ModuleRouter may promote controller paths such as
 * /guide?view=routes or /guide?d={slug} into ORIGIN. Those must never become
 * the Shared key — path-only canonicalize would collapse them all to /guide.
 */
final class FpcEngineIdentity
{
    /**
     * Controller-shaped guide targets used as internal routing, not visitor paths.
     */
    public static function isGuideControllerUri(string $uri): bool
    {
        $uri = \trim($uri);
        if ($uri === '') {
            return false;
        }
        if (!\str_contains($uri, '://') && !\str_starts_with($uri, '/')) {
            $uri = '/' . $uri;
        }
        $parts = \parse_url($uri);
        if (!\is_array($parts)) {
            return false;
        }
        $path = (string)($parts['path'] ?? '');
        if ($path === '') {
            $path = '/';
        }
        $path = \rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }
        if ($path !== '/guide') {
            return false;
        }
        $query = (string)($parts['query'] ?? '');
        if ($query === '') {
            return false;
        }
        $params = [];
        \parse_str($query, $params);
        foreach (['view', 'd', 'rt'] as $key) {
            if (isset($params[$key]) && \trim((string)$params[$key]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Prefer a visitor pretty path over a guide-controller identity.
     */
    public static function preferVisitorUri(string $candidate, string ...$fallbacks): string
    {
        $candidate = \trim($candidate);
        if ($candidate !== '' && !self::isGuideControllerUri($candidate)) {
            return $candidate;
        }
        foreach ($fallbacks as $fallback) {
            $fallback = \trim($fallback);
            if ($fallback !== '' && !self::isGuideControllerUri($fallback)) {
                return $fallback;
            }
        }

        return $candidate;
    }

    /**
     * Freeze Shared/engine identity once per request to scheme://host/visitor-path.
     * Never overwrite a good freeze with a guide-controller URI.
     */
    public static function freezeFullUrl(string $fullUrl): void
    {
        $fullUrl = \trim($fullUrl);
        if ($fullUrl === '' || !\str_contains($fullUrl, '://')) {
            return;
        }
        if (self::isGuideControllerUri($fullUrl)) {
            return;
        }
        try {
            $existing = \trim((string)(RequestContext::get('fpc.engine_identity_url', '') ?? ''));
            if ($existing !== ''
                && \str_contains($existing, '://')
                && !self::isGuideControllerUri($existing)
            ) {
                return;
            }
            RequestContext::set('fpc.engine_identity_url', $fullUrl);
        } catch (\Throwable) {
            // RequestContext may be unavailable in early CLI probes.
        }
    }
}
