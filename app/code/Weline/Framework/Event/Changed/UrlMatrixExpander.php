<?php

declare(strict_types=1);

namespace Weline\Framework\Event\Changed;

use Weline\Framework\Manager\ObjectManager;

/**
 * urls∪previous_urls → canonicalize → × 站语种 × 站货币；始终 ≤256 降级。
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

        $expanded = [];
        foreach ($base as $url) {
            foreach ($locales as $locale) {
                foreach ($currencies as $currency) {
                    $variant = $this->withVariant($url, $locale, $currency);
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
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port . $path . $query;
    }

    private function withVariant(string $url, string $locale, string $currency): string
    {
        // FPC 键侧由 Capability 按 path + locale/currency 变体删；此处保留原始 URL 标记变体查询
        if (preg_match('#^https?://#i', $url) !== 1) {
            $sep = str_contains($url, '?') ? '&' : '?';
            return $url . $sep . '_wl=' . rawurlencode($locale) . '&_wc=' . rawurlencode($currency);
        }
        $parts = parse_url($url);
        if ($parts === false) {
            return $url;
        }
        $query = [];
        if (!empty($parts['query'])) {
            parse_str((string)$parts['query'], $query);
        }
        $query['_wl'] = $locale;
        $query['_wc'] = $currency;
        $path = $parts['path'] ?? '/';
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port . $path . '?' . http_build_query($query);
    }

    /** @return list<string> */
    private function websiteLocales(int $websiteId): array
    {
        try {
            if (function_exists('w_query')) {
                $codes = w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);
                if (is_array($codes)) {
                    return array_values(array_filter(array_map('strval', $codes)));
                }
            }
        } catch (\Throwable) {
        }
        try {
            $class = \Weline\Websites\Model\WebsiteLanguage::class;
            if (class_exists($class)) {
                /** @var object $model */
                $model = ObjectManager::getInstance($class);
                if (method_exists($model, 'getWebsiteLanguageCodes')) {
                    $codes = $model->getWebsiteLanguageCodes($websiteId);
                    if (is_array($codes)) {
                        return array_values(array_filter(array_map('strval', $codes)));
                    }
                }
            }
        } catch (\Throwable) {
        }
        return ['zh_Hans_CN', 'en_US'];
    }

    /** @return list<string> */
    private function websiteCurrencies(int $websiteId): array
    {
        try {
            if (function_exists('w_query')) {
                $codes = w_query('websites', 'getWebsiteCurrencyCodes', ['website_id' => $websiteId]);
                if (is_array($codes) && $codes !== []) {
                    return array_values(array_filter(array_map('strval', $codes)));
                }
            }
        } catch (\Throwable) {
        }
        return ['CNY'];
    }
}
