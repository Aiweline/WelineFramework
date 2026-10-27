<?php

declare(strict_types=1);

namespace Amayum\Website\Extends\Module\Weline_Seo\SeoProfileProvider;

use Amayum\Website\Service\SeoFactsService;
use Amayum\Website\Service\SiteProfileService;
use Weline\Seo\Interface\SeoProfileProviderInterface;

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
