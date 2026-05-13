<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Seo;

use Weline\I18n\Service\ActiveLocaleCodeProvider;

class InternationalSeoContextService
{
    private const DEFAULT_LOCALE = 'zh_Hans_CN';
    private const DEFAULT_CURRENCY = 'CNY';

    public function __construct(
        private readonly ActiveLocaleCodeProvider $activeLocaleCodeProvider,
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function build($template, array $context): array
    {
        $seo = $this->toArray($this->readTemplate($template, 'seo'));
        $currentLocale = $this->normalizeLocale($this->firstNonEmpty([
            $context['locale'] ?? '',
            $this->readTemplate($template, 'lang_local'),
            $this->readTemplate($template, 'lang'),
            $this->env('user.lang'),
            $_SERVER['WELINE_USER_LANG'] ?? '',
            self::DEFAULT_LOCALE,
        ]));
        $defaultLocale = $this->normalizeLocale($this->firstNonEmpty([
            $this->readTemplate($template, 'default_locale'),
            $this->read($seo, ['default_locale', 'website_locale', 'x_default_locale']),
            $this->env('website.language'),
            $_SERVER['WELINE_WEBSITE_LANGUAGE'] ?? '',
            self::DEFAULT_LOCALE,
        ]));
        try {
            $activeLocaleCodes = $this->activeLocaleCodeProvider->getInstalledActiveCodes();
        } catch (\Throwable) {
            $activeLocaleCodes = [];
        }
        $locales = $this->uniqueLocales(array_merge(
            [$defaultLocale],
            $activeLocaleCodes,
            [$currentLocale],
        ));

        $baseUrl = $this->siteRoot((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        $routePath = $this->routePath((string)($context['canonical_url'] ?? $context['url'] ?? ''), $locales);
        $generatedAlternates = $this->buildAlternates($baseUrl, $routePath, $locales, $defaultLocale);
        $contextAlternates = $this->normalizeUrlMap((array)($context['alternates'] ?? []), $baseUrl);
        $explicitAlternates = $this->normalizeUrlMap(
            $this->toArray($this->readTemplate($template, 'i18n_alternates') ?: $this->read($seo, ['i18n_alternates'])),
            $baseUrl,
        );
        $alternates = array_replace($generatedAlternates, $contextAlternates, $explicitAlternates);
        if (!isset($alternates['x-default']) && isset($alternates[$defaultLocale])) {
            $alternates['x-default'] = $alternates[$defaultLocale];
        }

        $localizedSeo = $this->findLocaleConfig($this->localizedSeoMap($template, $seo), $currentLocale);
        $localizedContext = $this->normalizeLocalizedSeo($localizedSeo, $baseUrl);
        $result = [
            'locale' => $currentLocale,
            'html_locale' => $this->toBcp47($currentLocale),
            'og_locale' => $currentLocale,
            'available_languages' => array_values(array_map(fn (string $locale): string => $this->toBcp47($locale), $locales)),
            'alternates' => $alternates,
            'i18n' => [
                'current_locale' => $currentLocale,
                'default_locale' => $defaultLocale,
                'locales' => $locales,
                'html_locale' => $this->toBcp47($currentLocale),
            ],
        ];

        return array_replace($result, $localizedContext);
    }

    /**
     * @param string[] $locales
     * @return array<string, string>
     */
    private function buildAlternates(string $baseUrl, string $routePath, array $locales, string $defaultLocale): array
    {
        if ($baseUrl === '') {
            return [];
        }

        $alternates = [];
        foreach ($locales as $locale) {
            $alternates[$locale] = $this->buildLocaleUrl($baseUrl, $routePath, $locale, $defaultLocale);
        }
        if (isset($alternates[$defaultLocale])) {
            $alternates['x-default'] = $alternates[$defaultLocale];
        }
        return $alternates;
    }

    private function buildLocaleUrl(string $baseUrl, string $routePath, string $locale, string $defaultLocale): string
    {
        $prefix = trim($this->localePrefix($locale, $defaultLocale), '/');
        $route = trim($routePath, '/');
        $path = trim($prefix . '/' . $route, '/');
        return rtrim($baseUrl, '/') . ($path === '' ? '/' : '/' . $path);
    }

    private function localePrefix(string $locale, string $defaultLocale): string
    {
        $segments = [];
        $currency = strtoupper($this->firstNonEmpty([$this->env('user.currency'), $_SERVER['WELINE_USER_CURRENCY'] ?? '']));
        $websiteCurrency = strtoupper($this->firstNonEmpty([$this->env('website.currency'), self::DEFAULT_CURRENCY]));
        if ($currency !== '' && $currency !== $websiteCurrency && $currency !== self::DEFAULT_CURRENCY) {
            $segments[] = $currency;
        }

        if ($locale !== '' && $locale !== $defaultLocale && $locale !== self::DEFAULT_LOCALE) {
            $segments[] = $locale;
        }

        return $segments === [] ? '' : '/' . implode('/', $segments);
    }

    /**
     * @param string[] $locales
     */
    private function routePath(string $url, array $locales): string
    {
        $path = '';
        if ($url !== '') {
            $parts = parse_url($url);
            if (is_array($parts)) {
                $path = (string)($parts['path'] ?? '');
            }
        }
        if ($path === '') {
            $path = (string)($_SERVER['REQUEST_URI'] ?? '/');
            $questionPos = strpos($path, '?');
            if ($questionPos !== false) {
                $path = substr($path, 0, $questionPos);
            }
        }

        $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $segment): bool => $segment !== ''));
        $localeMap = [];
        foreach ($locales as $locale) {
            $localeMap[strtolower($locale)] = true;
            $localeMap[strtolower($this->toBcp47($locale))] = true;
        }
        $currencyMap = $this->currencyPrefixMap();

        while ($segments !== []) {
            $first = (string)$segments[0];
            $normalizedLocale = strtolower($this->normalizeLocale($first));
            if (isset($localeMap[strtolower($first)]) || isset($localeMap[$normalizedLocale]) || isset($currencyMap[strtoupper($first)])) {
                array_shift($segments);
                continue;
            }
            break;
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    /**
     * @return array<string, bool>
     */
    private function currencyPrefixMap(): array
    {
        $currencies = [
            $this->env('user.currency'),
            $_SERVER['WELINE_USER_CURRENCY'] ?? '',
            $this->env('website.currency'),
            self::DEFAULT_CURRENCY,
        ];
        $map = [];
        foreach ($currencies as $currency) {
            $currency = strtoupper(trim((string)$currency));
            if ($currency !== '') {
                $map[$currency] = true;
            }
        }
        return $map;
    }

    private function siteRoot(string $url): string
    {
        $parts = $url !== '' ? parse_url($url) : false;
        if (is_array($parts) && !empty($parts['host'])) {
            return (string)($parts['scheme'] ?? 'https') . '://' . (string)$parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }

        $configured = rtrim($this->firstNonEmpty([
            $_SERVER['WELINE_WEBSITE_URL'] ?? '',
            $this->env('website.url'),
            $this->env('website_url'),
        ]), '/');
        if ($configured !== '') {
            return $configured;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
        if ($host === '') {
            return '';
        }
        $scheme = (string)($_SERVER['REQUEST_SCHEME'] ?? '');
        if ($scheme === '') {
            $https = strtolower((string)($_SERVER['HTTPS'] ?? ''));
            $scheme = $https !== '' && $https !== 'off' ? 'https' : 'http';
        }
        return $scheme . '://' . $host;
    }

    /**
     * @param array<string, mixed> $alternates
     * @return array<string, string>
     */
    private function normalizeUrlMap(array $alternates, string $baseUrl): array
    {
        $normalized = [];
        foreach ($alternates as $locale => $url) {
            if (!is_string($locale) || !is_string($url) || trim($url) === '') {
                continue;
            }
            $key = strtolower($locale) === 'x-default' ? 'x-default' : $this->normalizeLocale($locale);
            if ($key === '') {
                continue;
            }
            $normalized[$key] = $this->absoluteUrl($url, $baseUrl);
        }
        return $normalized;
    }

    /**
     * @param array<string, mixed> $seo
     * @return array<string, mixed>
     */
    private function localizedSeoMap($template, array $seo): array
    {
        return $this->toArray(
            $this->readTemplate($template, 'i18n_seo')
                ?: $this->read($seo, ['i18n_seo', 'i18n', 'locales'])
        );
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, mixed>
     */
    private function findLocaleConfig(array $map, string $locale): array
    {
        foreach ($map as $key => $value) {
            if (!is_string($key) || !is_array($value)) {
                continue;
            }
            if ($this->normalizeLocale($key) === $locale) {
                return $value;
            }
        }
        return [];
    }

    /**
     * @param array<string, mixed> $seo
     * @return array<string, mixed>
     */
    private function normalizeLocalizedSeo(array $seo, string $baseUrl): array
    {
        $context = [];
        foreach ([
            'title' => ['title', 'meta_title'],
            'description' => ['description', 'meta_description'],
            'keywords' => ['keywords', 'meta_keywords'],
            'robots' => ['robots'],
        ] as $target => $keys) {
            $value = $this->read($seo, $keys);
            if ($this->isFilled($value)) {
                $context[$target] = $value;
            }
        }

        $canonical = $this->read($seo, ['canonical_url', 'canonical']);
        if ($this->isFilled($canonical)) {
            $context['canonical_url'] = $this->absoluteUrl((string)$canonical, $baseUrl);
        }
        $image = $this->read($seo, ['image', 'og_image']);
        if ($this->isFilled($image)) {
            $context['image'] = $this->absoluteUrl((string)$image, $baseUrl);
        }
        return $context;
    }

    private function readTemplate($template, string $key): mixed
    {
        if (is_object($template) && method_exists($template, 'getData')) {
            return $template->getData($key);
        }
        return null;
    }

    /**
     * @param mixed $source
     * @param string[] $keys
     */
    private function read(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }
            if (is_object($source) && method_exists($source, 'getData')) {
                $value = $source->getData($key);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    /**
     * @param mixed[] $values
     */
    private function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if ($this->isFilled($value)) {
                return trim((string)$value);
            }
        }
        return '';
    }

    private function isFilled(mixed $value): bool
    {
        return !is_array($value) && !is_object($value) && trim((string)$value) !== '';
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param string[] $locales
     * @return string[]
     */
    private function uniqueLocales(array $locales): array
    {
        $result = [];
        $seen = [];
        foreach ($locales as $locale) {
            $normalized = $this->normalizeLocale((string)$locale);
            if ($normalized === '') {
                continue;
            }
            $key = strtolower($normalized);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $normalized;
        }
        return $result;
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = trim(str_replace('-', '_', $locale));
        if ($locale === '' || strtolower($locale) === 'x_default') {
            return '';
        }

        $parts = array_values(array_filter(explode('_', $locale), static fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return '';
        }

        $normalized = [strtolower($parts[0])];
        for ($i = 1, $count = count($parts); $i < $count; $i++) {
            $part = $parts[$i];
            $normalized[] = strlen($part) === 4
                ? ucfirst(strtolower($part))
                : strtoupper($part);
        }
        return implode('_', $normalized);
    }

    private function toBcp47(string $locale): string
    {
        return str_replace('_', '-', $this->normalizeLocale($locale));
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if ($baseUrl === '') {
            return $url;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function env(string $key): string
    {
        if (function_exists('w_env')) {
            try {
                $value = \w_env($key, '');
                if ($value !== null && trim((string)$value) !== '') {
                    return (string)$value;
                }
            } catch (\Throwable) {
            }
        }
        return '';
    }
}
