<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

/**
 * Zero-DB loader for pre-generated storefront 404 pages under pub/errors/storefront-not-found/.
 *
 * Hot 404 responses read these files directly — no theme slot queries or product catalog reads per request.
 * Layout: {websiteCode}/{lang}.html plus legacy flat {lang}.html and _host_map.php.
 */
final class StorefrontNotFoundStaticPage
{
    public const DEFAULT_LANG = 'zh_Hans_CN';

    public const KIND = 'storefront-not-found';

    /**
     * Resolve locale for a storefront 404 response.
     *
     * Priority: path segment → query(lang|locale|locale_code) → zh_Hans_CN (no language cookie).
     */
    public static function resolveLang(
        string $path,
        string $queryString = '',
        string $cookieHeader = '',
    ): string {
        $pathLang = self::resolveLangFromStaticPath($path);
        if ($pathLang !== '') {
            return $pathLang;
        }

        $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
        foreach (\explode('/', \trim($pathOnly, '/')) as $segment) {
            $lang = self::normalizeLangCode($segment);
            if ($lang !== '') {
                return $lang;
            }
        }

        if ($queryString !== '') {
            \parse_str($queryString, $queryParams);
            foreach (['locale', 'locale_code', 'lang'] as $key) {
                $lang = self::normalizeLangCode((string)($queryParams[$key] ?? ''));
                if ($lang !== '') {
                    return $lang;
                }
            }
        }

        // Path locale may already be stripped from request.path; prefer request-scoped mirror.
        try {
            if (\class_exists(\Weline\Framework\Env\WelineEnv::class, false)
                || \class_exists(\Weline\Framework\Env\WelineEnv::class)) {
                $mirror = self::normalizeLangCode(
                    (string)\Weline\Framework\Env\WelineEnv::server('WELINE_USER_LANG', '')
                );
                if ($mirror !== '') {
                    return $mirror;
                }
            }
        } catch (\Throwable) {
        }

        // $cookieHeader retained for call-site compatibility; language cookies are ignored.
        unset($cookieHeader);

        return self::DEFAULT_LANG;
    }

    public static function resolveLangFromRequestUri(string $requestUri, string $cookieHeader = ''): string
    {
        $queryString = (string)(\parse_url($requestUri, \PHP_URL_QUERY) ?: '');
        $path = (string)(\parse_url($requestUri, \PHP_URL_PATH) ?: '/');

        return self::resolveLang($path, $queryString, $cookieHeader);
    }

    public static function staticFilePath(string $lang, string $websiteCode = ''): string
    {
        return StaticErrorPageMap::websiteLocaleFile(self::KIND, $websiteCode, $lang, '.html');
    }

    public static function publicHtmlUrl(string $lang, string $websiteCode = ''): string
    {
        $lang = self::normalizeLangCode($lang);
        if ($lang === '') {
            $lang = self::DEFAULT_LANG;
        }
        $code = StaticErrorPageMap::sanitizeWebsiteCode($websiteCode);
        if ($code !== '') {
            return '/pub/errors/storefront-not-found/' . \rawurlencode($code) . '/' . \rawurlencode($lang) . '.html';
        }

        return '/pub/errors/storefront-not-found/' . \rawurlencode($lang) . '.html';
    }

    public static function resolveLangFromStaticPath(string $path): string
    {
        $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
        if (\preg_match('#/pub/errors/storefront-not-found/([^/]+)/([^/]+)\.html$#i', $pathOnly, $matches) === 1) {
            $maybeCode = (string)($matches[1] ?? '');
            $maybeLang = self::normalizeLangCode(\rawurldecode((string)($matches[2] ?? '')));
            if ($maybeLang !== '' && StaticErrorPageMap::sanitizeWebsiteCode($maybeCode) === $maybeCode) {
                return $maybeLang;
            }
        }
        if (\preg_match('#/pub/errors/storefront-not-found/([^/]+)\.html$#i', $pathOnly, $matches) === 1) {
            $lang = self::normalizeLangCode(\rawurldecode((string)($matches[1] ?? '')));
            if ($lang !== '') {
                return $lang;
            }
        }

        return '';
    }

    public static function loadHtml(
        ?string $lang = null,
        string $path = '/',
        string $queryString = '',
        string $cookieHeader = '',
        string $host = '',
        ?string $websiteCode = null,
    ): ?string {
        if (!\defined('BP')) {
            return null;
        }

        $lang = $lang !== null && $lang !== '' ? $lang : self::resolveLang($path, $queryString, $cookieHeader);

        return StaticErrorPageMap::loadWithFallback(
            self::KIND,
            $lang,
            $host,
            $path,
            false,
            $websiteCode,
        );
    }

    /**
     * @return list<string>
     */
    public static function langFallbackChain(string $lang): array
    {
        return StaticErrorPageMap::langFallbackChain(self::KIND, $lang);
    }

    public static function normalizeLangCode(string $code): string
    {
        $code = \trim($code);
        if ($code === '') {
            return '';
        }

        $normalized = \str_replace('-', '_', $code);
        if (\preg_match('/^[a-z]{2}_[A-Za-z]{2,}(?:_[A-Z]{2})?$/', $normalized) === 1) {
            $parts = \explode('_', $normalized);
            $langPart = \strtolower($parts[0]);
            $scriptOrRegion = $parts[1] ?? '';
            if (\strlen($scriptOrRegion) === 4) {
                $scriptOrRegion = \ucfirst(\strtolower($scriptOrRegion));
            } else {
                $scriptOrRegion = \strtoupper($scriptOrRegion);
            }
            $region = isset($parts[2]) ? '_' . \strtoupper($parts[2]) : '';

            return $langPart . '_' . $scriptOrRegion . $region;
        }

        return MaintenanceStaticPage::normalizeLangCode($code);
    }
}
