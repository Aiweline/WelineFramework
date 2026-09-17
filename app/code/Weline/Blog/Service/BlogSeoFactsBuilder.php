<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Theme\Helper\WidgetI18n;

final class BlogSeoFactsBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildDetailProfile(BlogArticle $article, string $canonicalOverride = ''): array
    {
        $canonical = trim($canonicalOverride);
        if ($canonical === '') {
            $canonical = trim($article->canonicalUrl);
        }
        if ($canonical === '') {
            $canonical = trim($article->publicUrl);
        }

        $section = '';
        foreach ($article->categories as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $section = $name;
                break;
            }
        }

        $breadcrumbs = [
            ['name' => WidgetI18n::label('首页'), 'url' => $this->localeAwarePath('/', $canonical)],
            ['name' => WidgetI18n::label('博客'), 'url' => $this->localeAwarePath('/blog', $canonical)],
        ];
        $categoryUrl = trim((string)($article->sourceRef['category_url'] ?? ''));
        if ($section !== '' && $categoryUrl !== '') {
            $breadcrumbs[] = [
                'name' => $section,
                'url' => $this->localeAwarePath($categoryUrl, $canonical),
            ];
        }
        $breadcrumbs[] = [
            'name' => $article->title,
            'url' => $canonical !== '' ? $canonical : $article->publicUrl,
        ];

        $articleFacts = [
            'headline' => $article->title,
            'description' => $article->excerpt,
            'datePublished' => $article->publishedAt,
            'dateModified' => $article->updatedAt ?: $article->publishedAt,
            'mainEntityOfPage' => $canonical,
        ];
        if ($section !== '') {
            $articleFacts['articleSection'] = $section;
        }
        if ($article->author !== null && $article->author !== '') {
            // Prefer plural `authors` so HeadRenderer never string-casts a nested array to "Array".
            $defaults = $this->defaultAuthorIdentity($article->locale, $canonical);
            $person = ['@type' => 'Person', 'name' => $article->author];
            $authorUrl = trim((string)($article->authorUrl ?? ''));
            if ($authorUrl === '') {
                $authorUrl = $defaults['url'];
            }
            $authorUrl = $this->absolutizeAuthorUrl($authorUrl, $canonical);
            if ($authorUrl !== '') {
                $person['url'] = $authorUrl;
            }
            $authorBio = trim((string)($article->authorBio ?? ''));
            if ($authorBio === '') {
                $authorBio = $defaults['bio'];
            }
            if ($authorBio !== '') {
                $person['description'] = $authorBio;
            }
            $authorJob = trim((string)($article->authorJobTitle ?? ''));
            if ($authorJob === '') {
                $authorJob = $defaults['jobTitle'];
            }
            if ($authorJob !== '') {
                $person['jobTitle'] = $authorJob;
            }
            $sameAs = $article->authorSameAs !== null && $article->authorSameAs !== []
                ? array_values($article->authorSameAs)
                : $defaults['sameAs'];
            if ($sameAs !== []) {
                $person['sameAs'] = $sameAs;
            }
            $articleFacts['authors'] = [$person];
        }
        if ($article->coverImage !== null && $article->coverImage !== '') {
            $articleFacts['image'] = [$article->coverImage];
        }

        $imageAlt = trim($article->title);
        if ($imageAlt === '') {
            $imageAlt = WidgetI18n::label('博客文章封面');
        }

        return [
            'page_type' => 'blog_post',
            'title' => $article->title,
            'description' => $article->excerpt,
            'canonical_url' => $canonical,
            'robots' => 'index,follow',
            'image' => $article->coverImage,
            'image_alt' => $imageAlt,
            'article' => $articleFacts,
            'breadcrumbs' => $breadcrumbs,
            'feeds' => $this->siteFeeds($this->originFromUrl($canonical)),
            'sitemap' => [
                'include' => true,
                'changefreq' => 'weekly',
                'priority' => '0.7',
            ],
            'geo' => ['include' => true],
        ];
    }

    /**
     * @param list<BlogArticle> $articles
     * @return array<string, mixed>
     */
    public function buildListProfile(array $articles, string $listCanonical = '/blog'): array
    {
        $items = [];
        $shareImage = '';
        $shareAlt = '';
        foreach ($articles as $article) {
            $item = [
                'name' => $article->title,
                'url' => $article->publicUrl,
                'description' => $article->excerpt,
                'published_at' => $article->publishedAt,
            ];
            if ($article->coverImage !== null && trim($article->coverImage) !== '') {
                $item['image'] = trim($article->coverImage);
            }
            $items[] = $item;
            if ($shareImage === '' && $article->coverImage !== null && trim($article->coverImage) !== '') {
                $shareImage = trim($article->coverImage);
                $shareAlt = trim($article->title);
            }
        }

        $title = WidgetI18n::label('汉服博客');
        if ($shareImage === '') {
            // Stable storefront share asset when the list has no cover yet.
            $shareImage = '/pub/media/catalog/hanfu/r2/homepage/taoyuan-qingmeng.webp';
        }
        if ($shareAlt === '') {
            $shareAlt = $title;
        }

        return [
            'page_type' => 'blog_list',
            'title' => $title,
            'description' => WidgetI18n::label('阅读汉服穿搭灵感、形制科普与节日搭配指南，系统了解明制、宋制、唐制与马面裙的选购要点、穿着建议、保养提醒与礼仪场景搭配方法，帮助你更快做出更合适且更安心的选择。'),
            'canonical_url' => $listCanonical,
            'robots' => 'index,follow',
            'image' => $shareImage,
            'image_alt' => $shareAlt,
            'item_list' => $items,
            'feeds' => $this->siteFeeds($this->originFromUrl($listCanonical)),
            'breadcrumbs' => [
                ['name' => WidgetI18n::label('首页'), 'url' => $this->localeAwarePath('/', $listCanonical)],
                ['name' => WidgetI18n::label('博客'), 'url' => $this->localeAwarePath('/blog', $listCanonical)],
            ],
            'sitemap' => [
                'include' => true,
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ],
            'geo' => ['include' => true],
        ];
    }

    /**
     * @return list<array{type:string,title:string,href:string}>
     */
    public function siteFeeds(string $origin = ''): array
    {
        return [[
            'type' => 'application/rss+xml',
            'title' => WidgetI18n::label('博客 RSS'),
            'href' => $this->absoluteFeedHref(BlogNamespace::rssPublicPath(), $origin),
        ]];
    }

    /**
     * @return list<array{type:string,title:string,href:string}>
     */
    public function categoryFeeds(string $categorySlug, string $origin = '', string $categoryTitle = ''): array
    {
        $title = $categoryTitle !== ''
            ? WidgetI18n::label('「%{1}」分类 RSS', '', [$categoryTitle])
            : WidgetI18n::label('博客分类 RSS');

        return [[
            'type' => 'application/rss+xml',
            'title' => $title,
            'href' => $this->absoluteFeedHref(BlogNamespace::categoryRssPublicPath($categorySlug), $origin),
        ]];
    }

    /**
     * When a post only has author name, fill Helpful Content Who identity chain.
     *
     * @return array{url:string,sameAs:list<string>,jobTitle:string,bio:string}
     */
    public function defaultAuthorIdentity(string $locale = '', string $canonicalOrBase = ''): array
    {
        $origin = $this->originFromUrl($canonicalOrBase);
        if ($origin === '') {
            $base = rtrim(trim($canonicalOrBase), '/');
            if ($base !== '' && preg_match('#^https?://#i', $base) === 1) {
                $origin = $base;
            }
        }
        $normalized = strtolower(str_replace('_', '-', trim($locale)));
        $isChinese = $normalized === '' || str_starts_with($normalized, 'zh');

        return [
            'url' => $origin !== '' ? $origin . '/about' : '/about',
            'sameAs' => [
                'https://www.instagram.com/changan.hanfu',
            ],
            'jobTitle' => 'Hanfu editorial research',
            'bio' => $isChinese
                ? '本店编辑部：形制、面料与文化语境研究。'
                : 'Editorial desk: silhouette, fabric, and cultural context research.',
        ];
    }

    private function absolutizeAuthorUrl(string $url, string $canonical): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $origin = $this->originFromUrl($canonical);
        if ($origin === '') {
            return $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }

        return $origin . '/' . ltrim($url, '/');
    }

    /**
     * Keep /{currency}/{locale}/ (or /{locale}/) from $canonical when building
     * BreadcrumbList item URLs so they match self-referencing storefront URLs.
     */
    private function localeAwarePath(string $path, string $canonical): string
    {
        $path = '/' . ltrim(trim($path), '/');
        $canonical = trim($canonical);
        if ($canonical === '') {
            return $path;
        }

        $prefix = '';
        $origin = '';
        if (preg_match('#^https?://#i', $canonical) === 1) {
            $parts = parse_url($canonical);
            if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
                $origin = $parts['scheme'] . '://' . $parts['host'];
                if (!empty($parts['port'])) {
                    $origin .= ':' . $parts['port'];
                }
                $canonPath = (string)($parts['path'] ?? '/');
            } else {
                $canonPath = $canonical;
            }
        } else {
            $canonPath = $canonical;
            if (!str_starts_with($canonPath, '/')) {
                $canonPath = '/' . $canonPath;
            }
        }

        if (preg_match('#^(.*?)/blog(?:/|$)#i', $canonPath, $matches) === 1) {
            $prefix = rtrim((string)$matches[1], '/');
        }

        if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
            return $origin !== '' ? $origin . $path : $path;
        }

        if ($path === '/') {
            $joined = $prefix === '' ? '/' : $prefix . '/';
        } else {
            $joined = $prefix . $path;
        }

        return $origin !== '' ? $origin . $joined : $joined;
    }

    private function originFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }

    private function absoluteFeedHref(string $path, string $origin): string
    {
        $path = '/' . ltrim($path, '/');
        $origin = rtrim($origin, '/');
        if ($origin === '') {
            return $path;
        }

        return $origin . $path;
    }
}
