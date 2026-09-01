<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Category;
use Weline\Blog\Model\Post;

final class BlogContentResolver
{
    public function __construct(
        private readonly Post $postModel,
        private readonly Category $categoryModel,
        private readonly CmsBlogPageAdapter $cmsAdapter,
        private readonly BlogCategoryAttributeService $categoryAttributes,
    ) {
    }

    public function resolveBySlug(int $websiteId, string $locale, string $slug, string $baseUrl = ''): ?BlogArticle
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return null;
        }

        $post = $this->findPublishedPost($websiteId, $locale, $slug);
        if ($post !== null) {
            return $this->postToArticle($post, $baseUrl);
        }

        $cmsPage = $this->cmsAdapter->getPublishedPage($websiteId, $slug);
        if ($cmsPage !== null) {
            return $this->cmsAdapter->toBlogArticle($cmsPage, $baseUrl);
        }

        return null;
    }

    /**
     * @return list<BlogArticle>
     */
    public function listPublishedArticles(int $websiteId, string $locale, int $limit = 50, string $baseUrl = ''): array
    {
        return $this->collectArticles($websiteId, $locale, 0, $limit, $baseUrl);
    }

    /**
     * @return list<BlogArticle>
     */
    public function listPublishedArticlesByCategory(
        int $websiteId,
        string $locale,
        int $categoryId,
        int $limit = 50,
        string $baseUrl = '',
    ): array {
        return $this->collectArticles($websiteId, $locale, max(0, $categoryId), $limit, $baseUrl);
    }

    /**
     * @return list<BlogArticle>
     */
    public function listRelatedArticles(
        int $websiteId,
        string $locale,
        BlogArticle $article,
        int $limit = 4,
        string $baseUrl = '',
    ): array {
        $categoryId = (int)($article->sourceRef['category_id'] ?? 0);
        $candidates = $categoryId > 0
            ? $this->listPublishedArticlesByCategory($websiteId, $locale, $categoryId, $limit + 5, $baseUrl)
            : $this->listPublishedArticles($websiteId, $locale, $limit + 5, $baseUrl);

        $selfId = $article->entityId();
        $out = [];
        foreach ($candidates as $candidate) {
            if ($candidate->entityId() === $selfId && $candidate->contentKind === $article->contentKind) {
                continue;
            }
            $out[] = $candidate;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listCategories(int $websiteId = 0, string $locale = ''): array
    {
        $model = clone $this->categoryModel;
        $query = $model->clearData()->reset();
        $query->where(Category::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN');
        $rows = $query
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->order(Category::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();
        if (!is_array($rows)) {
            return [];
        }

        $ids = array_values(array_filter(array_map(
            static fn($row): int => is_array($row) ? (int)($row[Category::schema_fields_ID] ?? 0) : 0,
            $rows,
        )));
        $localizedNames = $this->categoryAttributes->readNameMap($websiteId, $ids, $locale);

        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            $slug = trim((string)($row[Category::schema_fields_SLUG] ?? ''));
            if ($id <= 0 || $slug === '') {
                continue;
            }
            $fallbackName = (string)($row[Category::schema_fields_NAME] ?? $slug);
            $displayName = $localizedNames[$id]
                ?? $this->categoryAttributes->resolveDisplayName($websiteId, $id, $locale, $fallbackName);
            $out[] = [
                'category_id' => $id,
                'name' => $displayName,
                'slug' => $slug,
                'url' => BlogNamespace::categoryPublicPath($slug),
                'sort_order' => (int)($row[Category::schema_fields_SORT_ORDER] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function resolveCategoryBySlug(int $websiteId, string $slug, string $locale = ''): ?array
    {
        $slug = trim(strtolower($slug), '/ ');
        if ($slug === '') {
            return null;
        }
        $model = clone $this->categoryModel;
        $row = $model->clearData()->reset()
            ->where(Category::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
            ->where(Category::schema_fields_SLUG, $slug)
            ->find()
            ->fetchArray();
        if (!is_array($row) || (int)($row[Category::schema_fields_ID] ?? 0) <= 0) {
            return null;
        }

        $categoryId = (int)$row[Category::schema_fields_ID];
        $fallbackName = (string)($row[Category::schema_fields_NAME] ?? $slug);
        $displayName = $this->categoryAttributes->resolveDisplayName(
            $websiteId,
            $categoryId,
            $locale,
            $fallbackName,
        );

        return [
            'category_id' => $categoryId,
            'name' => $displayName,
            'slug' => (string)($row[Category::schema_fields_SLUG] ?? $slug),
            'url' => BlogNamespace::categoryPublicPath((string)($row[Category::schema_fields_SLUG] ?? $slug)),
        ];
    }

    /**
     * @return list<BlogArticle>
     */
    private function collectArticles(
        int $websiteId,
        string $locale,
        int $categoryId,
        int $limit,
        string $baseUrl,
    ): array {
        $articles = [];
        foreach ($this->listPublishedPosts($websiteId, $locale, max($limit, 50), $categoryId) as $post) {
            $articles[] = $this->postToArticle($post, $baseUrl);
        }

        if ($categoryId <= 0) {
            foreach ($this->cmsAdapter->listPublishedBlogPages($websiteId, 1, $limit) as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $slug = trim(strtolower((string)($page['slug'] ?? '')));
                if ($slug === '' || $this->findPublishedPost($websiteId, $locale, $slug) !== null) {
                    continue;
                }
                $articles[] = $this->cmsAdapter->toBlogArticle($page, $baseUrl);
            }
        }

        usort($articles, static function (BlogArticle $a, BlogArticle $b): int {
            return strcmp((string)($b->publishedAt ?? ''), (string)($a->publishedAt ?? ''));
        });

        return array_slice($articles, 0, $limit);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPublishedPost(int $websiteId, string $locale, string $slug): ?array
    {
        $query = clone $this->postModel;
        $query->clearData()->reset()
            ->where(Post::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
            ->where(Post::schema_fields_SLUG, $slug)
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        if ($locale !== '') {
            $query->where(Post::schema_fields_LOCALE, $locale);
        }
        $row = $query->find()->fetchArray();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listPublishedPosts(int $websiteId, string $locale, int $limit, int $categoryId = 0): array
    {
        $query = clone $this->postModel;
        $query->clearData()->reset()
            ->where(Post::schema_fields_WEBSITE_ID, BlogWebsiteScope::websiteIdsForQuery($websiteId), 'IN')
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED);
        if ($locale !== '') {
            $query->where(Post::schema_fields_LOCALE, $locale);
        }
        if ($categoryId > 0) {
            $query->where(Post::schema_fields_CATEGORY_ID, $categoryId);
        }
        $rows = $query->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->limit($limit)
            ->select()
            ->fetchArray();

        return is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row))) : [];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function postToArticle(array $row, string $baseUrl): BlogArticle
    {
        $slug = (string)($row[Post::schema_fields_SLUG] ?? '');
        $path = BlogNamespace::publicPath($slug);
        $absolute = $this->absoluteUrl($path, $baseUrl);
        $categoryId = (int)($row[Post::schema_fields_CATEGORY_ID] ?? 0);
        $categoryMeta = $this->resolveCategoryMeta($categoryId, (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0), (string)($row[Post::schema_fields_LOCALE] ?? ''));

        return new BlogArticle(
            contentKind: BlogArticle::KIND_POST,
            websiteId: (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0),
            locale: (string)($row[Post::schema_fields_LOCALE] ?? ''),
            slug: $slug,
            identifier: BlogNamespace::identifierFromSlug($slug),
            title: (string)($row[Post::schema_fields_TITLE] ?? ''),
            excerpt: (string)($row[Post::schema_fields_EXCERPT] ?? ''),
            publishedAt: (string)($row[Post::schema_fields_PUBLISHED_AT] ?? '') ?: null,
            updatedAt: (string)($row[Post::schema_fields_UPDATED_AT] ?? '') ?: null,
            author: (string)($row[Post::schema_fields_AUTHOR] ?? '') ?: null,
            coverImage: (string)($row[Post::schema_fields_COVER_IMAGE] ?? '') ?: null,
            categories: ($categoryMeta['name'] ?? '') !== '' ? [(string)$categoryMeta['name']] : [],
            canonicalUrl: $absolute !== '' ? $absolute : $path,
            publicUrl: $path,
            sourceRef: [
                'kind' => BlogArticle::KIND_POST,
                'post_id' => (int)($row[Post::schema_fields_ID] ?? 0),
                'category_id' => $categoryId,
                'category_slug' => (string)($categoryMeta['slug'] ?? ''),
                'category_url' => (string)($categoryMeta['url'] ?? ''),
            ],
            keywords: (string)($row[Post::schema_fields_KEYWORDS] ?? '') ?: null,
        );
    }

    /**
     * @return array{name?:string,slug?:string,url?:string}
     */
    private function resolveCategoryMeta(int $categoryId, int $websiteId = 0, string $locale = ''): array
    {
        if ($categoryId <= 0) {
            return [];
        }
        $model = clone $this->categoryModel;
        $model->clearData()->reset()->load($categoryId);
        if ($model->getCategoryId() <= 0) {
            return [];
        }
        $slug = trim((string)($model->getData(Category::schema_fields_SLUG) ?? ''));
        $fallbackName = trim((string)($model->getData(Category::schema_fields_NAME) ?? ''));
        if ($websiteId <= 0) {
            $websiteId = (int)($model->getData(Category::schema_fields_WEBSITE_ID) ?? 0);
        }
        $name = $this->categoryAttributes->resolveDisplayName(
            $websiteId,
            $categoryId,
            $locale,
            $fallbackName,
        );

        return [
            'name' => $name,
            'slug' => $slug,
            'url' => $slug !== '' ? BlogNamespace::categoryPublicPath($slug) : '',
        ];
    }

    private function resolveCategoryName(int $categoryId, int $websiteId = 0, string $locale = ''): string
    {
        return (string)($this->resolveCategoryMeta($categoryId, $websiteId, $locale)['name'] ?? '');
    }

    private function absoluteUrl(string $path, string $baseUrl): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return $path;
        }

        return $baseUrl . '/' . ltrim($path, '/');
    }
}
