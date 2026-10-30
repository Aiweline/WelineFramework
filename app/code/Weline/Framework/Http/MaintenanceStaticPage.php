<?php

declare(strict_types=1);

namespace Weline\Framework\Http;

use Weline\Framework\Http\Fpc\MaintenanceFpcStore;

/**
 * Zero-DB loader for maintenance (503) pages from the per-language special FPC.
 *
 * Used by the WLS maintenance Worker and other gates that must return a page quickly.
 * Layout: generated/error-pages/maintenance/{websiteCode}/{lang}.html|.json.
 * Miss → null; the app-context renderer (MaintenanceStaticGenerator::renderHtml/renderJson)
 * renders the Maintenance module layout once and fills the FPC.
 */
final class MaintenanceStaticPage
{
    public const DEFAULT_LANG = 'zh_Hans_CN';

    public const KIND = 'maintenance';

    /**
     * Resolve locale for a maintenance response.
     *
     * Priority: path segment → query(lang|locale|locale_code) → WelineEnv::getLang()
     * (RequestContext) → zh_Hans_CN (no language cookie).
     */
    public static function resolveLang(
        string $path,
        string $queryString = '',
        string $cookieHeader = '',
    ): string {
        $pathLang = self::resolveLangFromMaintenancePath($path);
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

        try {
            if (\class_exists(\Weline\Framework\Context::class, false)
                && \Weline\Framework\Context::getCurrent() !== null
                && \class_exists(\Weline\Framework\App\State::class, false)
            ) {
                $fromState = self::normalizeLangCode(
                    (string)\Weline\Framework\Env\WelineEnv::getLang()
                );
                if ($fromState !== '') {
                    return $fromState;
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

    public static function staticFilePath(string $lang, bool $isApi = false, string $websiteCode = ''): string
    {
        $suffix = $isApi ? '.json' : '.html';

        return StaticErrorPageMap::websiteLocaleFile(self::KIND, $websiteCode, $lang, $suffix);
    }

    public static function publicHtmlUrl(string $lang, string $websiteCode = ''): string
    {
        $lang = self::normalizeLangCode($lang);
        if ($lang === '') {
            $lang = self::DEFAULT_LANG;
        }
        $code = StaticErrorPageMap::sanitizeWebsiteCode($websiteCode);
        if ($code !== '') {
            return '/pub/errors/maintenance/' . \rawurlencode($code) . '/' . \rawurlencode($lang) . '.html';
        }

        return '/pub/errors/maintenance/' . \rawurlencode($lang) . '.html';
    }

    public static function resolveLangFromMaintenancePath(string $path): string
    {
        $pathOnly = (string)(\parse_url($path, \PHP_URL_PATH) ?: $path);
        // Website-scoped: /pub/errors/maintenance/{code}/{lang}.html
        if (\preg_match('#/pub/errors/maintenance/([^/]+)/([^/]+)\.(?:html|json)$#i', $pathOnly, $matches) === 1) {
            $maybeCode = (string)($matches[1] ?? '');
            $maybeLang = self::normalizeLangCode(\rawurldecode((string)($matches[2] ?? '')));
            if ($maybeLang !== '' && StaticErrorPageMap::sanitizeWebsiteCode($maybeCode) === $maybeCode) {
                return $maybeLang;
            }
        }
        // Legacy flat: /pub/errors/maintenance/{lang}.html
        if (\preg_match('#/pub/errors/maintenance/([^/]+)\.(?:html|json)$#i', $pathOnly, $matches) === 1) {
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
        $hit = MaintenanceFpcStore::read(self::resolveWebsiteCode($websiteCode, $host), $lang, false);

        return $hit !== null ? $hit['body'] : null;
    }

    public static function loadJson(
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
        $hit = MaintenanceFpcStore::read(self::resolveWebsiteCode($websiteCode, $host), $lang, true);

        return $hit !== null ? $hit['body'] : null;
    }

    private static function resolveWebsiteCode(?string $websiteCode, string $host): string
    {
        unset($host);
        if ($websiteCode !== null && $websiteCode !== '') {
            return MaintenanceFpcStore::sanitizeCode($websiteCode);
        }
        try {
            if (\class_exists(\Weline\Framework\Runtime\RequestContext::class)) {
                $code = (string)\Weline\Framework\Runtime\RequestContext::getWelineWebsiteCode();
                if ($code !== '') {
                    return MaintenanceFpcStore::sanitizeCode($code);
                }
            }
        } catch (\Throwable) {
        }

        return 'default';
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
            $lang = \strtolower($parts[0]);
            $scriptOrRegion = $parts[1] ?? '';
            if (\strlen($scriptOrRegion) === 4) {
                $scriptOrRegion = \ucfirst(\strtolower($scriptOrRegion));
            } else {
                $scriptOrRegion = \strtoupper($scriptOrRegion);
            }
            $region = isset($parts[2]) ? '_' . \strtoupper($parts[2]) : '';

            return $lang . '_' . $scriptOrRegion . $region;
        }

        return self::langMapping()[$code] ?? self::langMapping()[\str_replace('_', '-', $code)] ?? '';
    }

    /**
     * @return array<string, string>
     */
    private static function langMapping(): array
    {
        static $mapping = null;
        if ($mapping !== null) {
            return $mapping;
        }

        $mappingFile = (\defined('BP') ? BP : '') . 'app/code/Weline/Maintenance/i18n/lang_mapping.json';
        if (\is_file($mappingFile)) {
            $content = @\file_get_contents($mappingFile);
            if (\is_string($content) && $content !== '') {
                $data = @\json_decode($content, true);
                if (\is_array($data) && isset($data['mapping']) && \is_array($data['mapping'])) {
                    $mapping = $data['mapping'];

                    return $mapping;
                }
            }
        }

        $mapping = [
            'zh-CN' => self::DEFAULT_LANG,
            'zh' => self::DEFAULT_LANG,
            'en-US' => 'en_US',
            'en' => 'en_US',
        ];

        return $mapping;
    }
}
