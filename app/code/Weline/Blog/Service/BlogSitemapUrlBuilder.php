<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Sitemap\BlogSitemapContentSourceInterface;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\I18n\Api\Seo\LocalizedUrlBuilderInterface;
use Weline\Websites\Model\WebsiteLanguage;

/**
 * Builds Blog sitemap rows with per-site-locale locs and xhtml alternates.
 *
 * Blog keeps supportsSiteLanguagePathExpansion()=false: public slugs are shared,
 * but content is locale-owned (storage suffix / locale packs). Path-blind expansion
 * would invent ghost URLs; this builder emits only storefront-resolvable locale rows.
 */
final class BlogSitemapUrlBuilder
{
    private const ARTICLE_LIMIT_PER_LOCALE = 500;

    public function __construct(
        private readonly BlogSitemapContentSourceInterface $resolver,
        private readonly LocalizedUrlBuilderInterface $urlBuilder,
        private readonly WebsiteLanguage $websiteLanguage,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildForWebsite(int $websiteId, string $baseUrl = ''): array
    {
        if ($websiteId < 0) {
            return [];
        }

        $baseUrl = rtrim(trim($baseUrl), '/');
        $siteLocales = $this->siteLocales($websiteId);
        if ($siteLocales === []) {
            $siteLocales = ['zh_Hans_CN'];
        }
        $defaultLocale = $siteLocales[0];

        /** @var array<string, array<string, array<string, mixed>>> $groups */
        $groups = [];

        foreach ($siteLocales as $locale) {
            $hubLoc = $this->localizePath($baseUrl, BlogNamespace::publicPath(), $locale, $defaultLocale);
            $groups['blog-list'][$locale] = [
                'url_key' => 'blog-list',
                'locale' => $locale,
                'loc' => $hubLoc,
                'lastmod' => date('Y-m-d'),
                'changefreq' => 'weekly',
                'priority' => '0.6',
                'entity_type' => 'blog_article',
                'entity_id' => 0,
                'metadata' => [
                    'page_type' => 'blog_list',
                    'content_kind' => 'blog_list',
                    'title' => (string)(function_exists('__') ? __('博客') : 'Blog'),
                    'locale' => $locale,
                ],
            ];
        }

        foreach ($siteLocales as $locale) {
            foreach ($this->resolver->listPublishedArticles($websiteId, $locale, self::ARTICLE_LIMIT_PER_LOCALE, $baseUrl) as $article) {
                if (!$article instanceof BlogArticle) {
                    continue;
                }
                $urlKey = $this->articleUrlKey($article);
                if ($urlKey === '') {
                    continue;
                }
                // Keep the first row for a locale (preferred content for that storefront locale).
                if (isset($groups[$urlKey][$locale])) {
                    continue;
                }
                $groups[$urlKey][$locale] = $this->articleToUrl($article, $baseUrl, $locale, $defaultLocale, $urlKey);
            }
        }

        $result = [];
        foreach ($groups as $urlKey => $byLocale) {
            if ($byLocale === []) {
                continue;
            }
            $alternates = [];
            foreach ($siteLocales as $locale) {
                if (!isset($byLocale[$locale])) {
                    continue;
                }
                $alternates[$locale] = (string)$byLocale[$locale]['loc'];
            }
            if ($alternates === []) {
                continue;
            }
            $alternates['x-default'] = $alternates[$defaultLocale] ?? reset($alternates);

            foreach ($byLocale as $locale => $row) {
                $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
                $meta['alternates'] = $alternates;
                $meta['locale'] = $locale;
                $row['metadata'] = $meta;
                $row['locale'] = $locale;
                $row['url_key'] = $urlKey;
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function articleToUrl(
        BlogArticle $article,
        string $baseUrl = '',
        string $displayLocale = '',
        string $defaultLocale = 'zh_Hans_CN',
        string $urlKey = '',
    ): array {
        $id = $article->entityId();
        $locale = trim(str_replace('-', '_', $displayLocale !== '' ? $displayLocale : $article->locale));
        if ($locale === '') {
            $locale = $defaultLocale !== '' ? $defaultLocale : 'zh_Hans_CN';
        }
        if ($urlKey === '') {
            $urlKey = $this->articleUrlKey($article);
        }
        $routePath = BlogNamespace::publicPath($article->slug);
        $loc = $this->localizePath($baseUrl, $routePath, $locale, $defaultLocale !== '' ? $defaultLocale : $locale);

        return [
            'url_key' => $urlKey,
            'locale' => $locale,
            'loc' => $loc,
            'lastmod' => substr((string)($article->updatedAt ?: $article->publishedAt ?: date('Y-m-d')), 0, 10),
            'changefreq' => 'weekly',
            'priority' => '0.7',
            'entity_type' => 'blog_article',
            'entity_id' => $id,
            'metadata' => [
                'page_type' => 'blog_post',
                'content_kind' => $article->contentKind,
                'title' => $article->title,
                'locale' => $locale,
                'images' => $article->coverImage ? [['loc' => $article->coverImage, 'title' => $article->title]] : [],
            ],
        ];
    }

    private function articleUrlKey(BlogArticle $article): string
    {
        $slug = trim(strtolower($article->slug), '/');
        if ($slug === '') {
            $storage = trim(strtolower((string)($article->sourceRef['storage_slug'] ?? '')), '/');
            $slug = trim(strtolower($this->resolver->publicSlugForLocale($storage, (string)$article->locale)), '/');
        }
        if ($slug === '') {
            $id = $article->entityId();
            return $id > 0
                ? ($article->contentKind === BlogArticle::KIND_POST ? 'blog-post-' . $id : 'blog-cms-' . $id)
                : '';
        }

        return ($article->contentKind === BlogArticle::KIND_POST ? 'blog-article-' : 'blog-cms-slug-') . $slug;
    }

    private function localizePath(string $baseUrl, string $routePath, string $locale, string $defaultLocale): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $routePath = '/' . ltrim(trim($routePath), '/');
        if ($baseUrl === '' || preg_match('#^https?://#i', $baseUrl) !== 1) {
            return $routePath === '/' ? '/blog' : $routePath;
        }

        $built = $this->urlBuilder->build($baseUrl, $routePath, $locale, $defaultLocale, null, null);
        return $built !== '' ? $built : ($baseUrl . ($routePath === '/' ? '' : $routePath));
    }

    /**
     * @return list<string>
     */
    private function siteLocales(int $websiteId): array
    {
        try {
            $codes = $this->websiteLanguage->getWebsiteLanguageCodes($websiteId);
        } catch (\Throwable) {
            return [];
        }
        $ordered = [];
        foreach ($codes as $code) {
            $locale = trim(str_replace('-', '_', (string)$code));
            if ($locale === '' || in_array($locale, $ordered, true)) {
                continue;
            }
            $ordered[] = $locale;
        }

        return $ordered;
    }
}
