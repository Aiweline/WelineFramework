<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Live storefront preview URL namespace: /~preview/{token}/…
 *
 * Keeps preview out of formal storefront cache keys (FPC/CDN). Not a WebsiteDomain path.
 */
final class ThemeLivePreviewPathMount
{
    public const PREFIX_SEGMENT = '~preview';

    public const PATH_PREFIX = '/~preview';

    public const REQUEST_CONTEXT_TOKEN_KEY = 'theme.live_preview_path_token';

    public const ENV_TOKEN_KEY = 'weline_live_preview_path_token';

    /**
     * Opaque preview bearer segment (matches PreviewTokenService issued tokens).
     */
    public const TOKEN_PATTERN = '/^pv_(?:[A-Za-z0-9_-]{43}|[1-9][0-9]{0,18}_[0-9]{9,12}_[a-f0-9]{16})$/D';

    public static function isPreviewToken(string $token): bool
    {
        $token = \trim($token);

        return $token !== '' && \preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /**
     * @return array{token: string, mount: string, remainder: string}|null
     */
    public static function parseFromUri(string $uri): ?array
    {
        $uri = \trim($uri);
        if ($uri === '') {
            return null;
        }
        if ($uri[0] !== '/') {
            $uri = '/' . $uri;
        }

        $path = (string)(\parse_url($uri, \PHP_URL_PATH) ?: '');
        if ($path === '') {
            return null;
        }
        $path = '/' . \trim(\str_replace('\\', '/', $path), '/');
        if ($path === '//') {
            $path = '/';
        }

        $prefix = self::PATH_PREFIX;
        if ($path !== $prefix && !\str_starts_with($path, $prefix . '/')) {
            return null;
        }

        $after = $path === $prefix ? '' : \substr($path, \strlen($prefix));
        $after = \ltrim((string)$after, '/');
        if ($after === '') {
            return null;
        }

        $token = \explode('/', $after, 2)[0];
        $token = \trim((string)$token);
        if (!self::isPreviewToken($token)) {
            return null;
        }

        $restPath = \substr($after, \strlen($token));
        $restPath = $restPath === false ? '' : $restPath;
        $restPath = '/' . \ltrim((string)$restPath, '/');
        if ($restPath === '//') {
            $restPath = '/';
        }

        $query = (string)(\parse_url($uri, \PHP_URL_QUERY) ?: '');
        $fragment = (string)(\parse_url($uri, \PHP_URL_FRAGMENT) ?: '');
        $remainder = $restPath
            . ($query !== '' ? '?' . $query : '')
            . ($fragment !== '' ? '#' . $fragment : '');

        return [
            'token' => $token,
            'mount' => self::PATH_PREFIX . '/' . $token,
            'remainder' => $remainder,
        ];
    }

    /**
     * Insert /~preview/{token} after origin, before the storefront path.
     */
    public static function joinPreviewPath(string $baseUrl, string $token): string
    {
        $token = \trim($token);
        if (!self::isPreviewToken($token)) {
            throw new \InvalidArgumentException('Invalid live preview token for path mount.');
        }

        $parts = \parse_url($baseUrl);
        if (!\is_array($parts)) {
            return self::PATH_PREFIX . '/' . $token . '/' . \ltrim($baseUrl, '/');
        }

        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        // Already mounted — replace token segment.
        $existing = self::parseFromUri($path . (isset($parts['query']) ? '?' . $parts['query'] : ''));
        if ($existing !== null) {
            $path = $existing['remainder'] === '' ? '/' : (string)(\parse_url($existing['remainder'], \PHP_URL_PATH) ?: '/');
        }

        $mountedPath = self::PATH_PREFIX . '/' . $token . ($path === '/' ? '' : $path);

        $query = [];
        if (!empty($parts['query'])) {
            \parse_str((string)$parts['query'], $query);
        }
        unset($query[PreviewTokenService::TOKEN_KEY]);
        foreach (PreviewContextService::editorCanvasQueryKeys() as $key) {
            unset($query[$key]);
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
        $host = (string)($parts['host'] ?? '');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $user = (string)($parts['user'] ?? '');
        $pass = isset($parts['pass']) ? ':' . $parts['pass'] : '';
        $auth = $user !== '' ? $user . $pass . '@' : '';
        $queryString = $query !== [] ? '?' . \http_build_query($query) : '';
        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

        if ($host === '') {
            return $mountedPath . $queryString . $fragment;
        }

        return $scheme . $auth . $host . $port . $mountedPath . $queryString . $fragment;
    }

    /**
     * Prefix a same-origin storefront URL/path with the active preview mount.
     */
    public static function prefixStorefrontUrl(string $url, string $token): string
    {
        $token = \trim($token);
        if (!self::isPreviewToken($token) || $url === '') {
            return $url;
        }

        if (\str_starts_with($url, '#')
            || \str_starts_with(\strtolower($url), 'mailto:')
            || \str_starts_with(\strtolower($url), 'tel:')
            || \str_starts_with(\strtolower($url), 'javascript:')
        ) {
            return $url;
        }

        if (self::parseFromUri($url) !== null) {
            return self::joinPreviewPath($url, $token);
        }

        if (\str_starts_with($url, '/')) {
            return self::joinPreviewPath($url, $token);
        }

        if (\str_starts_with($url, 'http://') || \str_starts_with($url, 'https://')) {
            return self::joinPreviewPath($url, $token);
        }

        return $url;
    }

    public static function conflictsWithDomainSubPath(string $subPath): bool
    {
        $normalized = '/' . \trim(\str_replace('\\', '/', $subPath), '/');
        if ($normalized === '/' || $normalized === '') {
            return false;
        }

        return $normalized === self::PATH_PREFIX
            || \str_starts_with($normalized, self::PATH_PREFIX . '/');
    }
}
