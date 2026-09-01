<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;

final class BlogSitemapUrlBuilder
{
    public function __construct(
        private readonly BlogContentResolver $resolver,
        private readonly CmsBlogPageAdapter $cmsAdapter,
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

        $urls = [[
            'url_key' => 'blog-list',
            'loc' => BlogNamespace::publicPath(),
            'lastmod' => date('Y-m-d'),
            'changefreq' => 'weekly',
            'priority' => '0.6',
            'entity_type' => 'blog_article',
            'metadata' => [
                'page_type' => 'blog_list',
                'content_kind' => 'blog_list',
                'title' => (string)__('博客'),
            ],
        ]];

        foreach ($this->resolver->listPublishedArticles($websiteId, '', 500, $baseUrl) as $article) {
            $urls[] = $this->articleToUrl($article);
        }

        return $urls;
    }

    /**
     * @return array<string, mixed>
     */
    public function articleToUrl(BlogArticle $article): array
    {
        $id = $article->entityId();
        $urlKey = $article->contentKind === BlogArticle::KIND_POST
            ? 'blog-post-' . $id
            : 'blog-cms-' . $id;

        return [
            'url_key' => $urlKey,
            'loc' => $article->publicUrl,
            'lastmod' => substr((string)($article->updatedAt ?: $article->publishedAt ?: date('Y-m-d')), 0, 10),
            'changefreq' => 'weekly',
            'priority' => '0.7',
            'entity_type' => 'blog_article',
            'entity_id' => $id,
            'metadata' => [
                'page_type' => 'blog_post',
                'content_kind' => $article->contentKind,
                'title' => $article->title,
                'images' => $article->coverImage ? [['loc' => $article->coverImage, 'title' => $article->title]] : [],
            ],
        ];
    }
}
