<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Post;
use Weline\Seo\Api\Url\UrlChangeNotifierInterface;

final class BlogPostUrlNotifier
{
    public function __construct(
        private readonly BlogSitemapUrlBuilder $sitemapBuilder,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public function notifyFromPostRow(array $row, string $action = 'upsert'): void
    {
        if (!interface_exists(UrlChangeNotifierInterface::class)) {
            return;
        }
        if ((string)($row[Post::schema_fields_STATUS] ?? '') !== Post::STATUS_PUBLISHED && $action !== 'delete') {
            $action = 'delete';
        }

        $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
        $websiteId = (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0);
        $article = new BlogArticle(
            contentKind: BlogArticle::KIND_POST,
            websiteId: $websiteId,
            locale: (string)($row[Post::schema_fields_LOCALE] ?? ''),
            slug: $slug,
            identifier: BlogNamespace::identifierFromSlug($slug),
            title: (string)($row[Post::schema_fields_TITLE] ?? ''),
            excerpt: (string)($row[Post::schema_fields_EXCERPT] ?? ''),
            publishedAt: (string)($row[Post::schema_fields_PUBLISHED_AT] ?? '') ?: null,
            updatedAt: (string)($row[Post::schema_fields_UPDATED_AT] ?? '') ?: null,
            author: (string)($row[Post::schema_fields_AUTHOR] ?? '') ?: null,
            coverImage: (string)($row[Post::schema_fields_COVER_IMAGE] ?? '') ?: null,
            categories: [],
            canonicalUrl: BlogNamespace::publicPath($slug),
            publicUrl: BlogNamespace::publicPath($slug),
            sourceRef: [
                'kind' => BlogArticle::KIND_POST,
                'post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
            ],
        );

        try {
            $notifier = \Weline\Framework\Manager\ObjectManager::getInstance(UrlChangeNotifierInterface::class);
            if (!$notifier instanceof UrlChangeNotifierInterface) {
                return;
            }
            $urlRow = $this->sitemapBuilder->articleToUrl($article);
            $notifier->notify([
                'module' => 'Weline_Blog',
                'scope' => 'blog_article',
                'action' => $action,
                'subject_type' => 'blog_article',
                'subject_id' => (int)($row[Post::schema_fields_ID] ?? 0),
                'url_key' => (string)($urlRow['url_key'] ?? ''),
                'url' => $article->publicUrl,
                'website_id' => $websiteId,
                'source' => 'Weline_Blog::post_save',
            ]);
        } catch (\Throwable) {
        }
    }
}
