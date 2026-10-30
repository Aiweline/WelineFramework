<?php

declare(strict_types=1);

namespace Amayum\Website\Extends\Module\Weline_Seo\SeoProfileProvider;

use Amayum\Website\Service\SeoFactsService;
use Amayum\Website\Service\SiteProfileService;
use Weline\Seo\Interface\SeoProfileProviderInterface;
use Weline\Websites\Data\WebsiteData;

/**
 * 把 Amayum 页面的 SEO 事实提供给 Weline_Seo HeadRenderer。
 *
 * 只返回事实，不输出 HTML/JSON-LD。
 */
final class AmayumSeoProfileProvider implements SeoProfileProviderInterface
{
    public function __construct(
        private readonly SiteProfileService $siteProfile,
        private readonly SeoFactsService $seoFacts,
    ) {
    }

    public function provideSeoProfile($template, array $context): array
    {
        $slot = \strtolower(\trim((string)($context['_slot'] ?? 'head')));
        if ($slot !== '' && $slot !== 'head') {
            return [];
        }

        // 站点门禁：本 provider 只服务 Amayum。原先只判 pageType/路径，任何站点命中同一批
        // 路径形状（首页、/legal、/journal）都会拿到 Amayum 的 FAQ/Organization —— 实测
        // playinginchina 首页就注入了 9 条「WelineFramework 是 Shopify 的替代品吗？」。
        if (!$this->isAmayumSite()) {
            return [];
        }

        $pageType = \strtolower(\str_replace(['-', ' '], '_', \trim((string)($context['page_type'] ?? ''))));
        $canonical = \trim((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        $path = (string)(\parse_url($canonical, \PHP_URL_PATH) ?: '/');
        $path = '/' . \trim($path, '/');

        if (!$this->isAmayumPage($pageType, $path)) {
            return [];
        }

        $facts = $this->seoFacts->forPath($path, $this->siteProfile->currentWebsiteCode());
        if ($facts === []) {
            return [];
        }

        $keys = [
            'page_type',
            'site_name',
            'title',
            'description',
            'canonical_url',
            'robots',
            'image',
            'image_alt',
            'organization',
            'faqs',
            'article',
            'schema_nodes',
            'breadcrumbs',
            'og_locale',
            'html_locale',
            'locale',
            'site_search_enabled',
        ];
        $profile = [];
        foreach ($keys as $key) {
            if (\array_key_exists($key, $facts)) {
                $profile[$key] = $facts[$key];
            }
        }

        return $profile;
    }

    /**
     * 当前请求是否真的落在 Amayum 站。
     *
     * 判定只认「当前网站 code」，不猜路径形状：同一套 storefront 路由（/、/legal、/journal）
     * 在别的站也会命中，按路径放行就等于把 Amayum 的品牌事实复制到全站。
     *
     * 注意不能用 SiteProfileService::currentWebsiteCode()：它在取不到 code 时会回退成 'amayum'，
     * 于是「无上下文」和「真是 Amayum」无法区分，门禁形同虚设。这里直接问 WebsiteData，
     * 只有确实拿到 amayum 才放行；拿不到（CLI / 无站点上下文）视为不适用，返回 false。
     */
    private function isAmayumSite(): bool
    {
        try {
            $code = \strtolower(\trim((string)(WebsiteData::getCode() ?? '')));
        } catch (\Throwable) {
            return false;
        }

        return $code === 'amayum';
    }

    private function isAmayumPage(string $pageType, string $path): bool
    {
        if (\in_array($pageType, [
            'homepage',
            'home',
            'commerce',
            'case_studies',
            'journal',
            'journal_article',
            'article',
            'contact',
            'legal',
            'sitemap_page',
        ], true)) {
            return true;
        }

        return \in_array($path, [
            '/',
            '/commerce',
            '/case-studies',
            '/journal',
            '/diagnosis',
            '/legal',
            '/sitemap',
        ], true) || \preg_match('#^/journal/[a-z0-9-]+$#D', $path) === 1;
    }
}
