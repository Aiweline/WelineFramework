<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Seo\LocalizedUrlBuilderInterface;
use Weline\Websites\Data\WebsiteData;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 预热 URL 语种展开：按站点已启用语言生成绝对 URL（默认语省略路径段）。
 *
 * 「热」语种本迭代 = 站点已启用语种（默认语优先）；Visitor 流量热度另开。
 */
final class WarmupLocaleUrlExpander
{
    /**
     * 按站点缓存语种与默认值。
     *
     * expandRoute() 每条路由都重新取一次站点语种与默认语言/货币；
     * 批量预热展开 2800+ 路由时每个站点会重复打上千次相同查询
     * （实测 FpcExtraDeclaredUrls::execute() 单次 18.7 秒）。
     * 站点语种在单次收集内不变，缓存一次即可。
     *
     * @var array<int, list<string>>
     */
    private array $localesCache = [];

    /** @var array<int, array{locale:string,currency:string}> */
    private array $defaultsCache = [];

    public function __construct(
        private readonly LocalizedUrlBuilderInterface $urlBuilder,
        private readonly WebsiteLanguage $websiteLanguage,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /** 站点语种/默认值变更后如需强制重读，可调用此方法。 */
    public function clearCaches(): void
    {
        $this->localesCache = [];
        $this->defaultsCache = [];
    }

    /**
     * @return list<string> locale codes, default language first
     */
    public function localesForWebsite(int $websiteId): array
    {
        if (isset($this->localesCache[$websiteId])) {
            return $this->localesCache[$websiteId];
        }
        try {
            $codes = $this->websiteLanguage->getWebsiteLanguageCodes($websiteId);
        } catch (\Throwable $e) {
            w_log_error('WarmupLocaleUrlExpander locales failed: ' . $e->getMessage());
            $codes = [];
        }
        $out = [];
        foreach ($codes as $code) {
            $code = trim((string)$code);
            if ($code === '') {
                continue;
            }
            if (!in_array($code, $out, true)) {
                $out[] = $code;
            }
        }
        if ($out === []) {
            $defaults = $this->defaultsForWebsite($websiteId);
            if ($defaults['locale'] !== '') {
                $out[] = $defaults['locale'];
            }
        }

        return $this->localesCache[$websiteId] = $out;
    }

    /**
     * @return array{locale:string,currency:string}
     */
    public function defaultsForWebsite(int $websiteId): array
    {
        if (isset($this->defaultsCache[$websiteId])) {
            return $this->defaultsCache[$websiteId];
        }
        $locale = 'zh_Hans_CN';
        $currency = 'CNY';
        try {
            $dl = trim((string)(WebsiteData::defaultLanguageForWebsite($websiteId) ?? ''));
            $dc = strtoupper(trim((string)(WebsiteData::defaultCurrencyForWebsite($websiteId) ?? '')));
            if ($dl !== '') {
                $locale = $dl;
            }
            if ($dc !== '') {
                $currency = $dc;
            }
        } catch (\Throwable) {
        }

        return $this->defaultsCache[$websiteId] = ['locale' => $locale, 'currency' => $currency];
    }

    /**
     * Expand a storefront route (or absolute URL) across all enabled site locales.
     *
     * @return list<array{url:string,site_id:int,locale:string}>
     */
    public function expandRoute(int $websiteId, string $baseUrl, string $routeOrUrl): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '' || preg_match('#^https?://#i', $baseUrl) !== 1) {
            return [];
        }
        $locales = $this->localesForWebsite($websiteId);
        if ($locales === []) {
            return [];
        }
        $defaults = $this->defaultsForWebsite($websiteId);
        $defaultLocale = $defaults['locale'] !== '' ? $defaults['locale'] : $locales[0];
        $defaultCurrency = $defaults['currency'];

        $rows = [];
        $seen = [];
        foreach ($locales as $locale) {
            try {
                $url = $this->urlBuilder->build(
                    $baseUrl,
                    $routeOrUrl,
                    $locale,
                    $defaultLocale,
                    null,
                    $defaultCurrency,
                );
            } catch (\Throwable $e) {
                w_log_error('WarmupLocaleUrlExpander build failed: ' . $e->getMessage());
                continue;
            }
            $url = trim($url);
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $rows[] = [
                'url' => $url,
                'site_id' => $websiteId,
                'locale' => $locale,
            ];
        }

        return $rows;
    }
}
