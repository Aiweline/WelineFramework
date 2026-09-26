<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Sitemap;

use Weline\I18n\Api\Localization\LocaleCatalogInterface;
use Weline\I18n\Api\Localization\LocaleRepositoryInterface;
use Weline\I18n\Api\Seo\LocalizedUrlBuilderInterface;
use Weline\Seo\Api\Sitemap\Data\Website;
use Weline\Seo\Interface\SitemapUrlProviderInterface;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Expands sitemap provider snapshots across website languages for path-stable URLs.
 *
 * Only runs when the provider opts in via supportsSiteLanguagePathExpansion().
 * Non-opt-in providers are returned unchanged (no synthetic alternates).
 */
final class SitemapLocaleUrlExpander
{
    public function __construct(
        private readonly LocalizedUrlBuilderInterface $urlBuilder,
        private readonly LocaleCatalogInterface $localeCatalog,
        private readonly LocaleRepositoryInterface $localeRepository,
        private readonly WebsiteLanguage $websiteLanguage,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rawUrls
     * @return array{
     *   urls: list<array<string,mixed>>,
     *   warnings: list<string>,
     *   expanded_url_count: int,
     *   owner_url_count: int
     * }
     */
    public function expand(SitemapUrlProviderInterface $provider, Website $website, array $rawUrls): array
    {
        $ownerCount = count($rawUrls);
        if (!$provider->supportsSiteLanguagePathExpansion()) {
            return [
                'urls' => array_values($rawUrls),
                'warnings' => [],
                'expanded_url_count' => $ownerCount,
                'owner_url_count' => $ownerCount,
            ];
        }

        $warnings = [];
        $siteLocales = $this->resolveSiteLocales($website->id, $warnings);
        if ($siteLocales === []) {
            $warnings[] = sprintf('website %d has no usable languages; sitemap locale expansion skipped', $website->id);

            return [
                'urls' => array_values($rawUrls),
                'warnings' => $warnings,
                'expanded_url_count' => $ownerCount,
                'owner_url_count' => $ownerCount,
            ];
        }

        $defaultLocale = $siteLocales[0];
        $baseUrl = rtrim(trim($website->url), '/');
        if ($baseUrl === '' || preg_match('#^https?://#i', $baseUrl) !== 1) {
            $warnings[] = sprintf('website %d has invalid base URL; sitemap locale expansion skipped', $website->id);

            return [
                'urls' => array_values($rawUrls),
                'warnings' => $warnings,
                'expanded_url_count' => $ownerCount,
                'owner_url_count' => $ownerCount,
            ];
        }

        $groups = [];
        foreach ($rawUrls as $row) {
            if (!is_array($row)) {
                continue;
            }
            $urlKey = trim((string)($row['url_key'] ?? $row['key'] ?? ''));
            if ($urlKey === '') {
                // Keep invalid rows for validateUrls to reject with context.
                $groups["\0invalid:" . count($groups)][] = $row;
                continue;
            }
            $groups[$urlKey][] = $row;
        }

        $expanded = [];
        foreach ($groups as $urlKey => $rows) {
            if (str_starts_with((string)$urlKey, "\0invalid:")) {
                foreach ($rows as $row) {
                    $expanded[] = $row;
                }
                continue;
            }

            $declared = [];
            $legacy = [];
            foreach ($rows as $row) {
                $locale = $this->tryNormalizeLocale((string)($row['locale'] ?? ''));
                if ($locale === '') {
                    $legacy[] = $row;
                    continue;
                }
                if (!isset($declared[$locale])) {
                    $declared[$locale] = $row;
                }
            }

            $template = $declared !== [] ? reset($declared) : ($legacy[0] ?? null);
            if (!is_array($template)) {
                continue;
            }

            $sampleLoc = '';
            if ($declared !== []) {
                $sampleLoc = trim((string)($template['loc'] ?? $template['url'] ?? ''));
            }
            if ($sampleLoc === '' && $legacy !== []) {
                $sampleLoc = trim((string)($legacy[0]['loc'] ?? $legacy[0]['url'] ?? ''));
            }
            if ($sampleLoc === '') {
                $warnings[] = sprintf('url_key %s missing loc; locale expansion skipped', $urlKey);
                foreach ($rows as $row) {
                    $expanded[] = $row;
                }
                continue;
            }

            $routePath = $this->extractRoutePath($sampleLoc, $siteLocales);
            $alternateLocs = [];
            foreach ($siteLocales as $locale) {
                if (isset($declared[$locale])) {
                    $alternateLocs[$locale] = trim((string)($declared[$locale]['loc'] ?? $declared[$locale]['url'] ?? ''));
                }
                if (($alternateLocs[$locale] ?? '') === '') {
                    $alternateLocs[$locale] = $this->urlBuilder->build(
                        $baseUrl,
                        $routePath,
                        $locale,
                        $defaultLocale,
                        null,
                        null,
                    );
                }
            }
            $alternateLocs['x-default'] = $alternateLocs[$defaultLocale] ?? reset($alternateLocs);

            foreach ($siteLocales as $locale) {
                if (isset($declared[$locale])) {
                    $row = $declared[$locale];
                    $row['locale'] = $locale;
                    $row['loc'] = trim((string)($row['loc'] ?? $row['url'] ?? $alternateLocs[$locale]));
                    $expanded[] = $this->withAlternates($row, $alternateLocs);
                    continue;
                }

                $row = $template;
                $row['url_key'] = $urlKey;
                $row['locale'] = $locale;
                $row['loc'] = $alternateLocs[$locale];
                $expanded[] = $this->withAlternates($row, $alternateLocs);
            }
        }

        return [
            'urls' => $expanded,
            'warnings' => $warnings,
            'expanded_url_count' => count($expanded),
            'owner_url_count' => $ownerCount,
        ];
    }

    /**
     * @param list<string> $warnings
     * @return list<string>
     */
    private function resolveSiteLocales(int $websiteId, array &$warnings): array
    {
        $activeMap = $this->activeLocaleMap();
        $codes = [];
        try {
            $codes = $this->websiteLanguage->getWebsiteLanguageCodes($websiteId);
        } catch (\Throwable $e) {
            $warnings[] = sprintf('failed to read website languages: %s', $e->getMessage());
            return [];
        }

        $resolved = [];
        foreach ($codes as $code) {
            $normalized = $this->filterToActiveLocale((string)$code, $activeMap);
            if ($normalized === null) {
                $warnings[] = sprintf('website language %s is not installed/enabled; skipped', (string)$code);
                continue;
            }
            $resolved[$normalized] = $normalized;
        }

        // getWebsiteLanguageCodes() already puts website.default_language first.
        $ordered = [];
        foreach ($codes as $code) {
            $normalized = $this->filterToActiveLocale((string)$code, $activeMap);
            if ($normalized !== null && isset($resolved[$normalized]) && !in_array($normalized, $ordered, true)) {
                $ordered[] = $normalized;
            }
        }
        foreach ($resolved as $code) {
            if (!in_array($code, $ordered, true)) {
                $ordered[] = $code;
            }
        }

        return $ordered;
    }

    /**
     * @param array<string,string> $activeMap
     */
    private function filterToActiveLocale(string $locale, array $activeMap): ?string
    {
        $locale = trim($locale);
        if ($locale === '') {
            return null;
        }
        $directKey = strtolower(str_replace('-', '_', $locale));
        if (isset($activeMap[$directKey])) {
            return $activeMap[$directKey];
        }
        try {
            $resolved = trim($this->localeRepository->resolveCode($locale, $locale));
        } catch (\Throwable) {
            return null;
        }
        $key = strtolower(str_replace('-', '_', $resolved));
        return $activeMap[$key] ?? null;
    }

    private function tryNormalizeLocale(string $locale): string
    {
        $normalized = $this->filterToActiveLocale($locale, $this->activeLocaleMap());
        return $normalized ?? '';
    }

    /** @return array<string,string> */
    private function activeLocaleMap(): array
    {
        $map = [];
        foreach ($this->localeCatalog->installed('zh_Hans_CN') as $record) {
            $code = trim((string)($record['code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $map[strtolower(str_replace('-', '_', $code))] = $code;
        }

        return $map;
    }

    /**
     * @param list<string> $siteLocales
     */
    private function extractRoutePath(string $loc, array $siteLocales): string
    {
        $path = $loc;
        if (preg_match('#^https?://#i', $path) === 1) {
            $parts = parse_url($path);
            $path = is_array($parts) ? (string)($parts['path'] ?? '/') : '/';
        }
        $questionPos = strpos($path, '?');
        if ($questionPos !== false) {
            $path = substr($path, 0, $questionPos);
        }

        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        $localeMap = [];
        foreach ($siteLocales as $code) {
            $code = str_replace('-', '_', trim($code));
            if ($code === '') {
                continue;
            }
            $localeMap[strtolower($code)] = true;
            $localeMap[strtolower(str_replace('_', '-', $code))] = true;
        }

        while ($segments !== []) {
            $first = (string)$segments[0];
            $asLocale = strtolower(str_replace('-', '_', $first));
            if (
                isset($localeMap[strtolower($first)])
                || isset($localeMap[$asLocale])
                || (preg_match('/^[A-Za-z]{3}$/', $first) === 1 && strtoupper($first) === $first)
            ) {
                array_shift($segments);
                continue;
            }
            break;
        }

        return $segments === [] ? '/' : '/' . implode('/', $segments);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,string> $alternateLocs
     * @return array<string,mixed>
     */
    private function withAlternates(array $row, array $alternateLocs): array
    {
        $metadata = [];
        if (isset($row['metadata']) && is_array($row['metadata'])) {
            $metadata = $row['metadata'];
        } elseif (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            $metadata = is_array($decoded) ? $decoded : [];
        }
        $metadata['alternates'] = $alternateLocs;
        $row['metadata'] = $metadata;

        return $row;
    }
}
