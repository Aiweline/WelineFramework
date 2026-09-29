<?php

declare(strict_types=1);

namespace Weline\Cdn\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\I18n\Api\Seo\LocalizedUrlBuilderInterface;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * 预热 URL 语种展开：按站点已启用语言生成绝对 URL（默认语省略路径段）。
 *
 * 「热」语种本迭代 = 站点已启用语种（默认语优先）；Visitor 流量热度另开。
 */
final class WarmupLocaleUrlExpander
{
    public function __construct(
        private readonly LocalizedUrlBuilderInterface $urlBuilder,
        private readonly WebsiteLanguage $websiteLanguage,
        private readonly ObjectManager $objectManager,
    ) {
    }

    /**
     * @return list<string> locale codes, default language first
     */
    public function localesForWebsite(int $websiteId): array
    {
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

        return $out;
    }

    /**
     * @return array{locale:string,currency:string}
     */
    public function defaultsForWebsite(int $websiteId): array
    {
        $locale = 'zh_Hans_CN';
        $currency = 'CNY';
        try {
            /** @var Website $website */
            $website = $this->objectManager->getInstance(Website::class);
            $website->clear()
                ->where(Website::schema_fields_ID, $websiteId)
                ->find()
                ->fetch();
            $dl = trim((string)($website->getDefaultLanguage() ?? ''));
            $dc = strtoupper(trim((string)($website->getDefaultCurrency() ?? '')));
            if ($dl !== '') {
                $locale = $dl;
            }
            if ($dc !== '') {
                $currency = $dc;
            }
        } catch (\Throwable) {
        }

        return ['locale' => $locale, 'currency' => $currency];
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
