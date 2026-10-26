<?php

declare(strict_types=1);

namespace Weline\App\Service;

use Weline\Currency\Api\CurrencyCatalogInterface;
use Weline\Currency\Api\Data\CurrencyRecord;
use Weline\Websites\Api\Catalog\Data\WebsiteSummary;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;
use Weline\Websites\Api\Localization\ResolvedLanguageCatalogInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * App / PWA 站点身份与本地化画像。
 *
 * 站名、语言、货币全部来自 Websites 已发布数据；禁止在 App 模块里写死品牌或语种。
 * 同一份画像供打包配置（ConfigRenderer）、启动配置 API 与 PWA manifest 共用。
 */
final class SiteProfile
{
    public function __construct(
        private readonly WebsiteCatalogInterface $websites,
        private readonly ResolvedLanguageCatalogInterface $languages,
        private readonly CurrencyCatalogInterface $currencies,
    ) {
    }

    /**
     * @return array{
     *   website_id:int,
     *   name:string,
     *   code:string,
     *   url:string,
     *   locales:list<string>,
     *   default_locale:string,
     *   currencies:list<array{code:string,name:string,symbol:string,format:string,position:string}>,
     *   default_currency:string,
     *   theme:array{primary:string,surface:string}
     * }
     */
    public function resolve(int $websiteId, bool $storefront = false): array
    {
        $website = $this->findWebsite($websiteId);
        $websiteId = $website?->id ?? $websiteId;

        $locales = $this->resolveLocales($websiteId);
        $defaultLocale = $this->normalizeLocaleCode(
            (string)(WebsiteData::defaultLanguageForWebsite($websiteId) ?? '')
        );
        if ($defaultLocale === '') {
            $defaultLocale = $locales[0] ?? '';
        }
        $locales = $this->orderLocales($locales, $defaultLocale);

        $currencyCodes = $this->resolveCurrencyCodes($websiteId);
        $defaultCurrency = \strtoupper(\trim((string)(WebsiteData::defaultCurrencyForWebsite($websiteId) ?? '')));
        if ($defaultCurrency === '') {
            $defaultCurrency = \strtoupper($currencyCodes[0] ?? '');
        }
        $currencies = $this->resolveCurrencies($currencyCodes, $defaultCurrency);

        $name = $this->resolveName($websiteId, $storefront, $website?->name ?? '');
        $description = $this->resolveDescription($websiteId, $storefront);

        return [
            'website_id' => $websiteId,
            'name' => $name,
            'description' => $description,
            'code' => $website?->code ?? '',
            'url' => $website?->url ?? '',
            'locales' => $locales,
            'default_locale' => $defaultLocale,
            'currencies' => $currencies,
            'default_currency' => $defaultCurrency,
            'theme' => $this->resolveThemeColors(),
        ];
    }

    private function findWebsite(int $websiteId): ?WebsiteSummary
    {
        try {
            $all = $this->websites->all();
            foreach ($all as $website) {
                if ($website->id === $websiteId) {
                    return $website;
                }
            }
            foreach ($all as $website) {
                if ($website->id === $this->websites->defaultWebsiteId()) {
                    return $website;
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function resolveLocales(int $websiteId): array
    {
        $codes = [];
        try {
            $codes = $this->languages->getResolvedLanguageCodes($websiteId);
        } catch (\Throwable) {
            $codes = [];
        }
        if ($codes === []) {
            try {
                $codes = WebsiteData::languageCodesForWebsite($websiteId);
            } catch (\Throwable) {
                $codes = [];
            }
        }

        $normalized = [];
        foreach ($codes as $code) {
            $code = $this->normalizeLocaleCode((string)$code);
            if ($code !== '') {
                $normalized[\strtolower($code)] = $code;
            }
        }

        return \array_values($normalized);
    }

    /**
     * @return list<string>
     */
    private function resolveCurrencyCodes(int $websiteId): array
    {
        try {
            $codes = WebsiteData::currencyCodesForWebsite($websiteId);
        } catch (\Throwable) {
            $codes = [];
        }

        $normalized = [];
        foreach ($codes as $code) {
            $code = \strtoupper(\trim((string)$code));
            if ($code !== '') {
                $normalized[$code] = $code;
            }
        }

        return \array_values($normalized);
    }

    /**
     * @param list<string> $codes
     * @return list<array{code:string,name:string,symbol:string,format:string,position:string}>
     */
    private function resolveCurrencies(array $codes, string $defaultCurrency): array
    {
        $records = [];
        try {
            /** @var CurrencyRecord $record */
            foreach ($this->currencies->active() as $record) {
                $records[$record->code] = $record;
            }
        } catch (\Throwable) {
            $records = [];
        }

        if ($defaultCurrency !== '') {
            $codes = \array_values(\array_filter($codes, static fn (string $code): bool => $code !== $defaultCurrency));
            \array_unshift($codes, $defaultCurrency);
        }

        $currencies = [];
        foreach ($codes as $code) {
            $record = $records[$code] ?? null;
            $currencies[] = [
                'code' => $code,
                'name' => $record?->name ?? $code,
                'symbol' => $record?->symbol ?? '',
                'format' => $record?->format ?? '1,0',
                'position' => $record?->position ?? 'left',
            ];
        }

        return $currencies;
    }

    private function resolveName(int $websiteId, bool $storefront, string $fallback): string
    {
        if ($storefront) {
            try {
                if (WebsiteData::matchesWebsiteId($websiteId)) {
                    $name = \trim((string)(WebsiteData::getName() ?? ''));
                    if ($name !== '') {
                        return $name;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return \trim($fallback);
    }

    private function resolveDescription(int $websiteId, bool $storefront): string
    {
        if (!$storefront) {
            return '';
        }
        try {
            if (WebsiteData::matchesWebsiteId($websiteId)) {
                return \trim((string)(WebsiteData::getDescription() ?? ''));
            }
        } catch (\Throwable) {
        }

        return '';
    }

    /**
     * @return array{primary:string,surface:string}
     */
    private function resolveThemeColors(): array
    {
        $primary = '';
        try {
            if (\class_exists(\Weline\Theme\Helper\ThemeData::class)) {
                $primary = \trim((string)(\Weline\Theme\Helper\ThemeData::getColorConfig('frontend') ?? ''));
            }
        } catch (\Throwable) {
            $primary = '';
        }

        return [
            'primary' => $this->normalizeColor($primary),
            'surface' => '',
        ];
    }

    private function normalizeColor(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if (\preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $value) === 1) {
            return $value;
        }
        if (\preg_match('/^rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\)$/i', $value) === 1) {
            return $value;
        }

        return '';
    }

    private function normalizeLocaleCode(string $code): string
    {
        $code = \str_replace('-', '_', \trim($code));
        if ($code === '') {
            return '';
        }

        try {
            return \Weline\Framework\App\State::normalizeLanguageCode($code);
        } catch (\Throwable) {
            return $code;
        }
    }

    /**
     * @param list<string> $locales
     * @return list<string>
     */
    private function orderLocales(array $locales, string $defaultLocale): array
    {
        if ($defaultLocale === '') {
            return $locales;
        }

        $ordered = [\strtolower($defaultLocale) => $defaultLocale];
        foreach ($locales as $locale) {
            $ordered[\strtolower($locale)] = $locale;
        }

        return \array_values($ordered);
    }
}
