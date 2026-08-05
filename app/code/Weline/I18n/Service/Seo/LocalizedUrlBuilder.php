<?php

declare(strict_types=1);

namespace Weline\I18n\Service\Seo;

use Weline\I18n\Api\Seo\LocalizedUrlBuilderInterface;

/**
 * Request-free canonical localized URL builder.
 *
 * Segment suppression is driven exclusively by the caller-provided default
 * locale/currency: only the site's own defaults omit their URL segment. The
 * FALLBACK_* constants are last-resort values used solely when a caller passes
 * empty defaults; they never suppress a non-default locale or currency.
 */
final class LocalizedUrlBuilder implements LocalizedUrlBuilderInterface
{
    private const FALLBACK_LOCALE = 'zh_Hans_CN';
    private const FALLBACK_CURRENCY = 'CNY';

    public function build(
        string $baseUrl,
        string $routePath,
        string $locale,
        string $defaultLocale,
        ?string $currency = null,
        ?string $defaultCurrency = null,
    ): string {
        $baseUrl = \rtrim(\trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }

        $locale = $this->normalizeLocale($locale);
        $defaultLocale = $this->normalizeLocale($defaultLocale);
        if ($defaultLocale === '') {
            $defaultLocale = self::FALLBACK_LOCALE;
        }
        if ($locale === '') {
            $locale = $defaultLocale;
        }

        $currency = \strtoupper(\trim((string)$currency));
        $defaultCurrency = \strtoupper(\trim((string)$defaultCurrency));
        if ($defaultCurrency === '') {
            $defaultCurrency = self::FALLBACK_CURRENCY;
        }

        $knownLocales = \array_values(\array_unique(\array_filter([
            $locale,
            $defaultLocale,
        ], static fn (string $code): bool => $code !== '')));

        $route = $this->stripLocaleAndCurrencyPrefixes($routePath, $knownLocales, [
            $currency,
            $defaultCurrency,
        ]);

        $segments = [];
        if ($currency !== '' && $currency !== $defaultCurrency) {
            $segments[] = $currency;
        }
        if ($locale !== '' && $locale !== $defaultLocale) {
            $segments[] = $locale;
        }

        $route = \trim($route, '/');
        if ($route !== '') {
            $segments[] = $route;
        }

        $path = \implode('/', $segments);

        return $baseUrl . ($path === '' ? '/' : '/' . $path);
    }

    /**
     * @param list<string> $locales
     * @param list<string> $currencies
     */
    private function stripLocaleAndCurrencyPrefixes(string $routePath, array $locales, array $currencies): string
    {
        $path = \trim($routePath);
        if ($path === '') {
            return '/';
        }

        // Absolute URL → keep path only.
        if (\preg_match('#^https?://#i', $path) === 1) {
            $parts = \parse_url($path);
            $path = \is_array($parts) ? (string)($parts['path'] ?? '/') : '/';
        }

        $questionPos = \strpos($path, '?');
        if ($questionPos !== false) {
            $path = \substr($path, 0, $questionPos);
        }

        $segments = \array_values(\array_filter(
            \explode('/', \trim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        $localeMap = [];
        foreach ($locales as $code) {
            $code = $this->normalizeLocale($code);
            if ($code === '') {
                continue;
            }
            $localeMap[\strtolower($code)] = true;
            $localeMap[\strtolower(\str_replace('_', '-', $code))] = true;
        }

        $currencyMap = [];
        foreach ($currencies as $code) {
            $code = \strtoupper(\trim((string)$code));
            if ($code !== '') {
                $currencyMap[$code] = true;
            }
        }

        while ($segments !== []) {
            $first = (string)$segments[0];
            $normalizedLocale = \strtolower($this->normalizeLocale($first));
            if (
                isset($localeMap[\strtolower($first)])
                || isset($localeMap[$normalizedLocale])
                || isset($currencyMap[\strtoupper($first)])
            ) {
                \array_shift($segments);
                continue;
            }
            break;
        }

        return $segments === [] ? '/' : '/' . \implode('/', $segments);
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = \trim($locale);
        if ($locale === '') {
            return '';
        }
        $locale = \str_replace('-', '_', $locale);
        if (\preg_match('/^([a-z]{2,3})(?:_([A-Za-z]{4}))?(?:_([A-Za-z]{2}|\d{3}))?$/', $locale, $m) !== 1) {
            return $locale;
        }
        $out = \strtolower($m[1]);
        if (!empty($m[2])) {
            $out .= '_' . \ucfirst(\strtolower($m[2]));
        }
        if (!empty($m[3])) {
            $out .= '_' . \strtoupper($m[3]);
        }

        return $out;
    }
}
