<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Model\Post;

final class BlogSearchQueryService
{
    public function __construct(
        private readonly Post $postModel,
        private readonly CmsBlogPageAdapter $cmsAdapter,
        private readonly BlogContentResolver $resolver,
    ) {
    }

    /**
     * @return list<BlogArticle>
     */
    public function search(string $query, int $websiteId, string $locale, int $limit = 20, ?int $categoryId = null): array
    {
        $query = trim(mb_strtolower($query));
        if ($query === '' || $websiteId < 0) {
            return [];
        }

        $articles = [];
        $model = clone $this->postModel;
        $dbQuery = $model->clearData()->reset()
            ->where(Post::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        if ($locale !== '') {
            $dbQuery->where(Post::schema_fields_LOCALE, $locale);
        }
        if ($categoryId !== null && $categoryId > 0) {
            $dbQuery->where(Post::schema_fields_CATEGORY_ID, $categoryId);
        }
        $dbQuery->where(
            'CONCAT(main_table.title,main_table.excerpt,main_table.keywords,main_table.slug)',
            '%' . $query . '%',
            'LIKE',
        );
        $rows = $dbQuery->limit($limit)->select()->fetchArray();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $resolved = $this->resolver->resolveBySlug(
                    $websiteId,
                    $locale,
                    (string)($row[Post::schema_fields_SLUG] ?? ''),
                );
                if ($resolved !== null) {
                    $articles[] = $resolved;
                }
            }
        }

        foreach ($this->cmsAdapter->listPublishedBlogPages($websiteId, 1, $limit) as $page) {
            if (!is_array($page)) {
                continue;
            }
            $haystack = mb_strtolower(implode(' ', [
                (string)($page['title'] ?? ''),
                (string)($page['meta_description'] ?? ''),
                (string)($page['meta_keywords'] ?? ''),
                (string)($page['slug'] ?? ''),
            ]));
            if (!str_contains($haystack, $query)) {
                continue;
            }
            $slug = (string)($page['slug'] ?? '');
            $existing = $this->resolver->resolveBySlug($websiteId, $locale, $slug);
            if ($existing !== null) {
                $articles[] = $existing;
            }
        }

        $unique = [];
        foreach ($articles as $article) {
            if (!$article instanceof BlogArticle) {
                continue;
            }
            $unique[$article->identifier] = $article;
        }

        return array_slice(array_values($unique), 0, $limit);
    }
}
