<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;

final class BlogSeoFactsBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function buildDetailProfile(BlogArticle $article): array
    {
        $breadcrumbs = [
            ['name' => (string)__('首页'), 'url' => '/'],
            ['name' => (string)__('博客'), 'url' => '/blog'],
            ['name' => $article->title, 'url' => $article->publicUrl],
        ];

        $articleFacts = [
            'headline' => $article->title,
            'description' => $article->excerpt,
            'datePublished' => $article->publishedAt,
            'dateModified' => $article->updatedAt ?: $article->publishedAt,
            'mainEntityOfPage' => $article->canonicalUrl,
        ];
        if ($article->author !== null && $article->author !== '') {
            $articleFacts['author'] = [['name' => $article->author]];
        }
        if ($article->coverImage !== null && $article->coverImage !== '') {
            $articleFacts['image'] = [$article->coverImage];
        }

        return [
            'page_type' => 'blog_post',
            'title' => $article->title,
            'description' => $article->excerpt,
            'canonical_url' => $article->canonicalUrl,
            'robots' => 'index,follow',
            'image' => $article->coverImage,
            'article' => $articleFacts,
            'breadcrumbs' => $breadcrumbs,
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
        foreach ($articles as $article) {
            $items[] = [
                'name' => $article->title,
                'url' => $article->publicUrl,
                'description' => $article->excerpt,
            ];
        }

        return [
            'page_type' => 'blog_list',
            'title' => (string)__('博客'),
            'description' => (string)__('最新博客文章'),
            'canonical_url' => $listCanonical,
            'robots' => 'index,follow',
            'item_list' => $items,
            'breadcrumbs' => [
                ['name' => (string)__('首页'), 'url' => '/'],
                ['name' => (string)__('博客'), 'url' => '/blog'],
            ],
            'sitemap' => [
                'include' => true,
                'changefreq' => 'weekly',
                'priority' => '0.6',
            ],
            'geo' => ['include' => true],
        ];
    }
}
