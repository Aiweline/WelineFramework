<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

/**
 * Live storefront preview URL namespace: /~preview/{token}/…
 *
 * Keeps preview out of formal storefront cache keys (FPC/CDN). Not a WebsiteDomain path.
 * Visible live-preview URLs must not stack /~site/{code} — site identity rides in the Token
 * and is rehydrated into routing_uri by NormalizeVisitorUriLivePreviewPath.
 *
 * /~site peel/rehydrate mirrors Websites ProjectHostSiteMount shape without hard-requiring
 * that class (Theme may load before Websites in unit bootstrap).
 */
final class ThemeLivePreviewPathMount
{
    public const PREFIX_SEGMENT = '~preview';

    public const PATH_PREFIX = '/~preview';

    public const REQUEST_CONTEXT_TOKEN_KEY = 'theme.live_preview_path_token';

    public const ENV_TOKEN_KEY = 'weline_live_preview_path_token';

    /** Same segment as Websites ProjectHostSiteMount::PATH_PREFIX. */
    private const SITE_PATH_PREFIX = '/~site';

    /** Same as Website::CODE_DEFAULT — bare project Host, no /~site/default. */
    private const DEFAULT_WEBSITE_CODE = 'default';

    /** Same as ProjectHostSiteMount::CODE_PATTERN. */
    private const SITE_CODE_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,62}$/D';

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
     * Strips project-Host /~site/{code} from the visible path (Token carries site identity).
     */
    public static function joinPreviewPath(string $baseUrl, string $token): string
    {
        $token = \trim($token);
        if (!self::isPreviewToken($token)) {
            throw new \InvalidArgumentException('Invalid live preview token for path mount.');
        }

        $parts = \parse_url($baseUrl);
        if (!\is_array($parts)) {
            $relative = self::stripProjectHostSiteMountFromPath('/' . \ltrim($baseUrl, '/'));

            return self::PATH_PREFIX . '/' . $token . ($relative === '/' ? '' : $relative);
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

        // Peel /~site/{code} first. Language/currency switchers may have built the inverted
        // stack /~site/{code}/~preview/{token}/… — unwrap nested preview after the site peel.
        $path = self::stripProjectHostSiteMountFromPath($path);
        $nestedPreview = self::parseFromUri($path);
        if ($nestedPreview !== null) {
            $path = $nestedPreview['remainder'] === ''
                ? '/'
                : (string)(\parse_url($nestedPreview['remainder'], \PHP_URL_PATH) ?: '/');
            if ($path === '') {
                $path = '/';
            }
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

    /**
     * Peel /~site/{code} from a storefront path so live-preview URLs stay Token-only.
     */
    public static function stripProjectHostSiteMountFromPath(string $path): string
    {
        $path = self::canonicalPath($path);
        $parsed = self::parseSiteMountFromPath($path);
        if ($parsed === null) {
            return $path;
        }

        $mount = $parsed['mount'];
        if ($path === $mount) {
            return '/';
        }
        if (\str_starts_with($path, $mount . '/')) {
            $rest = \substr($path, \strlen($mount));
            $rest = '/' . \ltrim((string)$rest, '/');

            return $rest === '//' ? '/' : $rest;
        }

        return $path;
    }

    /**
     * When remainder has no /~site mount, prefix /~site/{code} for DetectWebsite (routing only).
     * Default website stays bare on the project Host (no /~site/default).
     */
    public static function rehydrateSiteMountIntoRouting(string $remainder, ?string $websiteCode): string
    {
        $remainder = \trim($remainder);
        if ($remainder === '') {
            $remainder = '/';
        }

        $path = (string)(\parse_url($remainder, \PHP_URL_PATH) ?: '/');
        $path = self::canonicalPath($path === '' ? '/' : $path);

        $existing = self::parseSiteMountFromPath($path);
        if ($existing !== null) {
            return $remainder;
        }
        // Invalid /~site… prefix without a mountable code — leave for DetectWebsite.
        if ($path === self::SITE_PATH_PREFIX
            || \str_starts_with($path, self::SITE_PATH_PREFIX . '/')
        ) {
            return $remainder;
        }

        $code = \strtolower(\trim((string)$websiteCode));
        if ($code === '' || $code === self::DEFAULT_WEBSITE_CODE || !self::isMountableSiteCode($code)) {
            return $remainder;
        }

        $mount = self::SITE_PATH_PREFIX . '/' . $code;
        $query = (string)(\parse_url($remainder, \PHP_URL_QUERY) ?: '');
        $fragment = (string)(\parse_url($remainder, \PHP_URL_FRAGMENT) ?: '');
        $rehydratedPath = $mount . ($path === '/' ? '' : $path);

        return $rehydratedPath
            . ($query !== '' ? '?' . $query : '')
            . ($fragment !== '' ? '#' . $fragment : '');
    }

    /** @return array{code: string, mount: string}|null */
    private static function parseSiteMountFromPath(string $path): ?array
    {
        $path = self::canonicalPath($path);
        if ($path === '/' || $path === self::SITE_PATH_PREFIX) {
            return null;
        }
        if (!\str_starts_with($path, self::SITE_PATH_PREFIX . '/')) {
            return null;
        }

        $after = \ltrim(\substr($path, \strlen(self::SITE_PATH_PREFIX)), '/');
        $code = $after === '' ? '' : \explode('/', $after, 2)[0];
        $code = \strtolower(\trim($code));
        if ($code === '' || !self::isMountableSiteCode($code)) {
            return null;
        }

        return [
            'code' => $code,
            'mount' => self::SITE_PATH_PREFIX . '/' . $code,
        ];
    }

    private static function isMountableSiteCode(string $code): bool
    {
        $code = \strtolower(\trim($code));
        if ($code === '' || $code === '~site') {
            return false;
        }

        return \preg_match(self::SITE_CODE_PATTERN, $code) === 1;
    }

    private static function canonicalPath(string $path): string
    {
        $path = '/' . \trim(\str_replace('\\', '/', $path), '/');

        return $path === '//' ? '/' : $path;
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
