<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;

final class BlogSearchHitPresenter
{
    /**
     * @param list<BlogArticle> $articles
     * @return list<array<string, mixed>>
     */
    public function prepareHits(array $articles): array
    {
        $hits = [];
        foreach ($articles as $article) {
            $hits[] = [
                'type' => 'blog',
                'content_kind' => $article->contentKind,
                'title' => $article->title,
                'url' => $article->publicUrl,
                'excerpt' => $article->excerpt,
                'published_at' => $article->publishedAt,
                'image' => $article->coverImage,
                'categories' => $article->categories,
                'category_id' => (int)($article->sourceRef['category_id'] ?? 0) ?: null,
                'entity_id' => $article->entityId(),
                'post_id' => (int)($article->sourceRef['post_id'] ?? 0) ?: null,
                'cms_page_id' => (int)($article->sourceRef['cms_page_id'] ?? 0) ?: null,
                'source_ref' => $article->sourceRef,
            ];
        }

        return $hits;
    }
}
