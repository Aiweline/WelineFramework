<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

/**
 * urls∪previous_urls → canonicalize → × 站语种 × 站货币（店面 path 前缀变体）。
 *
 * FPC / 离线烘焙冷盘按 `scheme://host[/CURRENCY][/locale]/path` 建键（path 语种，非 query 标记）。
 * 本扩矩阵必须发出 path 变体（并保留无前缀默认面），否则 purgeUrls 清不掉多语冷盘。
 * 始终 ≤256，超额 degraded=true。
 */
final class UrlMatrixExpander
{
    private const MAX_KEYS = 256;

    /**
     * @param list<string> $urls
     * @return array{urls:list<string>,degraded:bool}
     */
    public function expand(array $urls, int $websiteId): array
    {
        $base = [];
        foreach ($urls as $url) {
            $url = $this->canonicalize(trim((string)$url));
            if ($url !== '') {
                $base[$url] = $url;
            }
        }
        $base = array_values($base);
        if ($base === []) {
            return ['urls' => [], 'degraded' => false];
        }

        $locales = $this->websiteLocales($websiteId);
        $currencies = $this->websiteCurrencies($websiteId);
        if ($locales === []) {
            $locales = ['zh_Hans_CN'];
        }
        if ($currencies === []) {
            $currencies = ['CNY'];
        }
        [$defaultLang, $defaultCurrency] = $this->websiteDefaults($websiteId, $locales, $currencies);

        $expanded = [];
        foreach ($base as $url) {
            // Always keep the visitor-facing base (typically default-locale unprefixed).
            $expanded[$url] = $url;
            if (count($expanded) >= self::MAX_KEYS) {
                return ['urls' => array_values($expanded), 'degraded' => true];
            }
            // Pass 1: every locale × default currency first so multi-lang cold disk
            // is covered before currency×locale explosion hits MAX_KEYS.
            foreach ($locales as $locale) {
                $variant = $this->withPathVariant(
                    $url,
                    (string)$locale,
                    $defaultCurrency,
                    $defaultLang,
                    $defaultCurrency,
                );
                if ($variant === '') {
                    continue;
                }
                $expanded[$variant] = $variant;
                if (count($expanded) >= self::MAX_KEYS) {
                    return ['urls' => array_values($expanded), 'degraded' => true];
                }
            }
            // Pass 2: remaining currency × locale variants (optional completeness).
            foreach ($locales as $locale) {
                foreach ($currencies as $currency) {
                    if (strcasecmp((string)$currency, $defaultCurrency) === 0) {
                        continue;
                    }
                    $variant = $this->withPathVariant(
                        $url,
                        (string)$locale,
                        (string)$currency,
                        $defaultLang,
                        $defaultCurrency,
                    );
                    if ($variant === '') {
                        continue;
                    }
                    $expanded[$variant] = $variant;
                    if (count($expanded) >= self::MAX_KEYS) {
                        return ['urls' => array_values($expanded), 'degraded' => true];
                    }
                }
            }
        }

        return ['urls' => array_values($expanded), 'degraded' => false];
    }

    private function canonicalize(string $url): string
    {
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            if ($url[0] !== '/') {
                $url = '/' . $url;
            }
            return $this->stripIgnorableQuery($url);
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $query = $this->filterQueryString((string)($parts['query'] ?? ''));
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . $host . $port . $path . ($query !== '' ? '?' . $query : '');
    }

    /**
     * Build storefront path localization variant matching FPC / bake identity.
     * Default lang+currency → unprefixed rest path; others → /[CURRENCY]/[locale]/rest.
     */
    private function withPathVariant(
        string $url,
        string $locale,
        string $currency,
        string $defaultLang,
        string $defaultCurrency,
    ): string {
        $locale = trim($locale);
        $currency = strtoupper(trim($currency));
        $defaultLang = trim($defaultLang);
        $defaultCurrency = strtoupper(trim($defaultCurrency));
        if ($locale === '' || $currency === '') {
            return '';
        }

        $absolute = preg_match('#^https?://#i', $url) === 1;
        $parts = $absolute ? parse_url($url) : ['path' => $url, 'query' => ''];
        if ($parts === false) {
            return '';
        }
        $path = (string)($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $rest = $this->stripLocalizationPrefix($path);
        $prefix = [];
        $omitCurrency = $defaultCurrency !== '' && strcasecmp($currency, $defaultCurrency) === 0;
        $omitLanguage = $defaultLang !== '' && strcasecmp($locale, $defaultLang) === 0;
        if (!$omitCurrency) {
            $prefix[] = $currency;
        }
        if (!$omitLanguage) {
            $prefix[] = $locale;
        }
        $restTrim = trim($rest, '/');
        $segments = $prefix;
        if ($restTrim !== '') {
            foreach (explode('/', $restTrim) as $seg) {
                if ($seg !== '') {
                    $segments[] = $seg;
                }
            }
        }
        $newPath = '/' . implode('/', $segments);
        if ($newPath !== '/' && str_ends_with($newPath, '/')) {
            $newPath = rtrim($newPath, '/');
        }
        $query = $this->filterQueryString((string)($parts['query'] ?? ''));
        $suffix = $query !== '' ? '?' . $query : '';

        if (!$absolute) {
            return $newPath . $suffix;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . $host . $port . $newPath . $suffix;
    }

    private function stripLocalizationPrefix(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn(string $s): bool => $s !== '',
        ));
        $i = 0;
        if (isset($segments[$i]) && $this->isCurrencySegment($segments[$i])) {
            $i++;
        }
        if (isset($segments[$i]) && $this->isLanguageSegment($segments[$i])) {
            $i++;
        }
        $rest = array_slice($segments, $i);
        if ($rest === []) {
            return '/';
        }

        return '/' . implode('/', $rest);
    }

    private function isCurrencySegment(string $segment): bool
    {
        return preg_match('/^[A-Z]{3}$/', $segment) === 1;
    }

    private function isLanguageSegment(string $segment): bool
    {
        return preg_match('/^[a-z]{2}(_[A-Za-z0-9]+)+$/', $segment) === 1;
    }

    private function stripIgnorableQuery(string $url): string
    {
        if (!str_contains($url, '?')) {
            return $url;
        }
        $qPos = strpos($url, '?');
        $path = substr($url, 0, $qPos);
        $query = $this->filterQueryString(substr($url, $qPos + 1));

        return $path . ($query !== '' ? '?' . $query : '');
    }

    private function filterQueryString(string $query): string
    {
        $query = trim($query);
        if ($query === '') {
            return '';
        }
        parse_str($query, $params);
        if (!is_array($params) || $params === []) {
            return '';
        }
        // Drop matrix leftovers + common tracking; keep content params (e.g. guide?view=).
        unset($params['_wl'], $params['_wc'], $params['_wb_nc'], $params['utm_source'], $params['utm_medium'], $params['utm_campaign']);
        if ($params === []) {
            return '';
        }
        ksort($params);

        return http_build_query($params);
    }

    /**
     * @param list<string> $locales
     * @param list<string> $currencies
     * @return array{0:string,1:string}
     */
    private function websiteDefaults(int $websiteId, array $locales, array $currencies): array
    {
        $defaultLang = trim((string)($locales[0] ?? 'zh_Hans_CN')) ?: 'zh_Hans_CN';
        $defaultCurrency = strtoupper(trim((string)($currencies[0] ?? 'CNY'))) ?: 'CNY';
        try {
            if (function_exists('w_query')) {
                // WebsiteData bag via Query — never forceReload when process/request face exists.
                $row = w_query('websites', 'getWebsiteById', [
                    'website_id' => $websiteId,
                    'force_reload' => false,
                ]);
                if (is_array($row)) {
                    $lang = trim((string)($row['default_language'] ?? ''));
                    $cur = strtoupper(trim((string)($row['default_currency'] ?? '')));
                    if ($lang !== '') {
                        $defaultLang = $lang;
                    }
                    if ($cur !== '') {
                        $defaultCurrency = $cur;
                    }
                }
            }
        } catch (\Throwable) {
        }

        return [$defaultLang, $defaultCurrency];
    }

    /** @return list<string> */
    private function websiteLocales(int $websiteId): array
    {
        try {
            if (function_exists('w_query')) {
                $codes = w_query('websites', 'getWebsiteLanguageCodes', [
                    'website_id' => $websiteId,
                    'force_reload' => false,
                ]);
                if (is_array($codes)) {
                    return array_values(array_filter(array_map('strval', $codes)));
                }
            }
        } catch (\Throwable) {
        }
        // No WebsiteLanguage FQCN soft-pull — websites Query is the only read path.
        return ['zh_Hans_CN', 'en_US'];
    }

    /** @return list<string> */
    private function websiteCurrencies(int $websiteId): array
    {
        try {
            if (function_exists('w_query')) {
                $codes = w_query('websites', 'getWebsiteCurrencyCodes', [
                    'website_id' => $websiteId,
                    'force_reload' => false,
                ]);
                if (is_array($codes) && $codes !== []) {
                    return array_values(array_filter(array_map('strval', $codes)));
                }
            }
        } catch (\Throwable) {
        }
        return ['CNY'];
    }
}
