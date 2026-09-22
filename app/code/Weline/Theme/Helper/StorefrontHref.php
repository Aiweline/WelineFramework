<?php

declare(strict_types=1);

namespace Weline\Theme\Helper;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\AllMenu\MenuTreeNormalizer;

/**
 * Storefront href helper: relative paths stay for &lt;base href="@url{'/'}"&gt;;
 * root-relative / same-origin absolutes rebuild via getFrontendUrl (lang+currency).
 */
final class StorefrontHref
{
    /**
     * In-page fragment that survives storefront &lt;base href="/"&gt;.
     * Bare "#id" resolves against the base and jumps to the homepage instead of the current path.
     */
    public static function fragmentHref(string $id, string $fallbackPath): string
    {
        $id = ltrim(trim($id), '#');
        if ($id === '') {
            return '#';
        }

        return self::currentDocumentPath($fallbackPath) . '#' . $id;
    }

    /**
     * Current storefront path for in-document anchors (locale prefix preserved when present).
     */
    public static function currentDocumentPath(string $fallbackPath): string
    {
        $fallback = trim($fallbackPath);
        if ($fallback === '' || $fallback === '#') {
            $fallback = '/';
        }
        if (!str_starts_with($fallback, '/')) {
            $fallback = '/' . $fallback;
        }
        $fallbackOnly = parse_url($fallback, PHP_URL_PATH);
        if (is_string($fallbackOnly) && $fallbackOnly !== '') {
            $fallback = $fallbackOnly;
        }

        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $raw = (string)($request->getPathInfo() ?: '');
            if ($raw === '' && \function_exists('w_env_request_uri')) {
                $raw = (string)\w_env_request_uri();
            }
            $path = parse_url($raw, PHP_URL_PATH);
            if (is_string($path) && $path !== '' && $path !== '/') {
                return $path;
            }
        } catch (\Throwable) {
        }

        return $fallback;
    }

    public static function localize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || $url === '#') {
            return $url !== '' ? $url : '#';
        }
        if (
            str_starts_with($url, 'mailto:')
            || str_starts_with($url, 'tel:')
            || str_starts_with($url, 'javascript:')
        ) {
            return $url;
        }

        // Path-relative (promotion/deals): resolved by document &lt;base&gt;.
        if (!str_starts_with($url, '/') && preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) !== 1) {
            return $url;
        }

        $pathUrl = self::toStorefrontPath($url);
        if ($pathUrl === null) {
            return $url; // external absolute
        }

        try {
            /** @var MenuTreeNormalizer $normalizer */
            $normalizer = ObjectManager::getInstance(MenuTreeNormalizer::class);

            return $normalizer->localizeUrl($pathUrl);
        } catch (\Throwable) {
            return $pathUrl;
        }
    }

    /**
     * @return string|null storefront path (/, /a/b, /a?x=1) or null when URL is external
     */
    private static function toStorefrontPath(string $url): ?string
    {
        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $scheme = strtolower((string)$parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower((string)$parts['host']);
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $authority = $host . $port;

        $originAuthority = self::requestOriginAuthority();
        if ($originAuthority === '' || $authority !== $originAuthority) {
            return null;
        }

        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $path .= '#' . $parts['fragment'];
        }

        return $path;
    }

    private static function requestOriginAuthority(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $base = (string)$request->getBaseUrl();
            $parts = parse_url($base);
            if ($parts === false || !isset($parts['host'])) {
                return '';
            }
            $host = strtolower((string)$parts['host']);
            $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

            return $host . $port;
        } catch (\Throwable) {
            return '';
        }
    }
}
