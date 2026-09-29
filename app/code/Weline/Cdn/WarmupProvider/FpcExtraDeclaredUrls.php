<?php

declare(strict_types=1);

namespace Weline\Cdn\WarmupProvider;

use Weline\Blog\Extends\Module\Weline_Seo\SitemapUrlProvider\BlogSitemapUrlProvider;
use Weline\Cdn\Api\WarmupProviderInterface;
use Weline\Cdn\Service\WarmupLocaleUrlExpander;
use Weline\Faq\Extends\Module\Weline_Seo\SitemapUrlProvider\FaqSitemapUrlProvider;
use Weline\Framework\Controller\Extra\ExtraCollector;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Extends\Module\Weline_Seo\SitemapUrlProvider\StorefrontStaticSitemapUrlProvider;
use Weline\Websites\Api\Catalog\WebsiteCatalogInterface;

/**
 * CDN 内置：从 @Extra type=fpc 侧车收集公开可预热 URL（静态文档面 × 站点已启用语种）。
 */
final class FpcExtraDeclaredUrls implements WarmupProviderInterface
{
    public const SOURCE_MODULE = 'Weline_Cdn';
    public const UI_LABEL = '静态文档（FPC 公开页）';
    public const UI_HINT = '含 Blog/FAQ/政策等；覆盖站点已启用语种；不含商品通配';

    private const SITEMAP_CAP = 200;
    private const EXCLUDE_PREFIXES = [
        '/customer/account',
        '/login',
        '/register',
        '/checkout',
        '/cart',
        '/api',
        '/admin',
        '/backend',
    ];

    public static function execute(): array
    {
        /** @var ExtraCollector $collector */
        $collector = ObjectManager::getInstance(ExtraCollector::class);
        $declarations = $collector->loadSidecar() ?? [];

        /** @var WebsiteCatalogInterface $websites */
        $websites = ObjectManager::getInstance(WebsiteCatalogInterface::class);
        /** @var WarmupLocaleUrlExpander $localeExpander */
        $localeExpander = ObjectManager::getInstance(WarmupLocaleUrlExpander::class);

        $siteBases = [];
        foreach ($websites->all() as $website) {
            $base = rtrim(trim((string)($website->url ?? '')), '/');
            if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
                continue;
            }
            $siteBases[(int)$website->id] = $base;
        }
        if ($siteBases === []) {
            return [];
        }

        $literalPaths = [];
        $needBlog = false;
        $needFaq = false;
        $needPolicy = false;

        foreach ($declarations as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (strtolower((string)($row['type'] ?? '')) !== 'fpc') {
                continue;
            }
            $attrs = is_array($row['attrs'] ?? null) ? $row['attrs'] : [];
            if (array_key_exists('enabled', $attrs) && !$attrs['enabled']) {
                continue;
            }
            $class = (string)($row['class'] ?? '');
            if ($class !== '' && (str_contains($class, '\\Backend\\') || str_ends_with($class, 'BackendController'))) {
                continue;
            }
            foreach ((array)($row['public_path_patterns'] ?? []) as $pattern) {
                $pattern = '/' . ltrim(trim((string)$pattern), '/');
                if ($pattern === '/' || self::isExcluded($pattern)) {
                    continue;
                }
                if (self::isProductPattern($pattern)) {
                    continue;
                }
                if (str_contains($pattern, '*')) {
                    if (str_starts_with($pattern, '/blog')) {
                        $needBlog = true;
                    } elseif (str_starts_with($pattern, '/faq')) {
                        $needFaq = true;
                    } elseif (str_starts_with($pattern, '/policy') || str_starts_with($pattern, '/about') || str_starts_with($pattern, '/terms')) {
                        $needPolicy = true;
                    }
                    continue;
                }
                $literalPaths[$pattern] = true;
            }
        }

        $out = [];
        $seen = [];
        foreach ($siteBases as $siteId => $base) {
            foreach (array_keys($literalPaths) as $path) {
                foreach ($localeExpander->expandRoute((int)$siteId, $base, $path) as $row) {
                    $url = $row['url'];
                    if (isset($seen[$url])) {
                        continue;
                    }
                    $seen[$url] = true;
                    $out[] = ['url' => $url, 'site_id' => (int)$siteId];
                }
            }
        }

        if ($needBlog) {
            self::appendSitemap($out, $seen, BlogSitemapUrlProvider::class, $siteBases, $localeExpander);
        }
        if ($needFaq) {
            self::appendSitemap($out, $seen, FaqSitemapUrlProvider::class, $siteBases, $localeExpander);
        }
        if ($needPolicy) {
            self::appendSitemap($out, $seen, StorefrontStaticSitemapUrlProvider::class, $siteBases, $localeExpander);
        }

        return self::filterEffectivePolicies($out, $siteBases);
    }

    /** 按公开URL的默认店铺/渠道身份检查已发布有效策略，预热不能重启已禁用声明。 */
    private static function filterEffectivePolicies(array $rows, array $siteBases): array
    {
        $resolver = ObjectManager::getInstance(\Weline\Framework\Controller\Extra\ExtraPolicyResolver::class);
        $stores = ObjectManager::getInstance(\Weline\Websites\Api\Catalog\StoreCatalogInterface::class);
        $channels = ObjectManager::getInstance(\Weline\Websites\Api\Catalog\SalesChannelCatalogInterface::class);
        $websites = ObjectManager::getInstance(WebsiteCatalogInterface::class);
        $codes = [];
        foreach ($websites->all() as $website) { $codes[$website->id] = $website->code; }
        $out = [];
        foreach ($rows as $row) {
            $site = (int)$row['site_id'];
            if (!isset($codes[$site])) { continue; }
            $store = $stores->defaultStore($site);
            $base = $siteBases[$site] ?? '';
            $longest = -1;
            foreach ($stores->byWebsite($site) as $candidate) {
                $candidateBase = rtrim((string)$candidate->url, '/');
                if ($candidate->enabled && $candidateBase !== '' && ($row['url'] === $candidateBase || str_starts_with($row['url'], $candidateBase . '/')) && strlen($candidateBase) > $longest) {
                    $store = $candidate; $base = $candidateBase; $longest = strlen($candidateBase);
                }
            }
            $scope = \Weline\Framework\Runtime\ScopeIdentity::website($site, $codes[$site]);
            if ($store !== null) {
                $channel = $channels->defaultChannelForStore($store);
                $scope = $channel !== null
                    ? \Weline\Framework\Runtime\ScopeIdentity::channel($site,$codes[$site],$store->code,$channel->code,$store->storeMode)
                    : \Weline\Framework\Runtime\ScopeIdentity::store($site,$codes[$site],$store->code,$store->storeMode);
            }
            $path = \Weline\Framework\Controller\Extra\FpcPolicySnapshot::normalizePath($row['url'],$base);
            $policy = $resolver->resolveFpcForPath($path,$scope);
            if ($policy !== null && $policy['enabled'] && $policy['ttl'] > 0) { $out[] = $row; }
        }
        return $out;
    }

    private static function isExcluded(string $pattern): bool
    {
        foreach (self::EXCLUDE_PREFIXES as $prefix) {
            if ($pattern === $prefix || str_starts_with($pattern, $prefix . '/') || str_starts_with($pattern, $prefix . '*')) {
                return true;
            }
        }

        return false;
    }

    private static function isProductPattern(string $pattern): bool
    {
        return str_starts_with($pattern, '/product')
            || str_starts_with($pattern, '/catalog/product');
    }

    /**
     * @param list<array{url:string,site_id:int}> $out
     * @param array<string,bool> $seen
     * @param array<int,string> $siteBases
     * @param class-string $providerClass
     */
    private static function appendSitemap(
        array &$out,
        array &$seen,
        string $providerClass,
        array $siteBases,
        WarmupLocaleUrlExpander $localeExpander,
    ): void {
        if (!class_exists($providerClass)) {
            return;
        }
        try {
            $provider = ObjectManager::getInstance($providerClass);
        } catch (\Throwable $e) {
            w_log_error('FpcExtraDeclaredUrls sitemap load failed: ' . $providerClass . ' ' . $e->getMessage());

            return;
        }
        if (!method_exists($provider, 'getUrlsForWebsite')) {
            return;
        }
        foreach ($siteBases as $siteId => $base) {
            $count = 0;
            try {
                $rows = $provider->getUrlsForWebsite((int)$siteId);
            } catch (\Throwable $e) {
                w_log_error('FpcExtraDeclaredUrls sitemap expand failed: ' . $e->getMessage());
                continue;
            }
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                if ($count >= self::SITEMAP_CAP) {
                    break;
                }
                $loc = '';
                if (is_string($row)) {
                    $loc = $row;
                } elseif (is_array($row)) {
                    $loc = (string)($row['loc'] ?? $row['url'] ?? '');
                }
                $loc = trim($loc);
                if ($loc === '') {
                    continue;
                }
                // Provider 已带 locale 的绝对 URL：直接入队；相对 path 或仅默认语：再按站点语种展开。
                $expanded = $localeExpander->expandRoute((int)$siteId, $base, $loc);
                if ($expanded === []) {
                    if (preg_match('#^https?://#i', $loc) === 1) {
                        $expanded = [['url' => $loc, 'site_id' => (int)$siteId, 'locale' => '']];
                    } else {
                        continue;
                    }
                }
                foreach ($expanded as $item) {
                    if ($count >= self::SITEMAP_CAP) {
                        break;
                    }
                    $url = $item['url'];
                    if (isset($seen[$url])) {
                        continue;
                    }
                    $seen[$url] = true;
                    $out[] = ['url' => $url, 'site_id' => (int)$siteId];
                    $count++;
                }
            }
        }
    }
}
