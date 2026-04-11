<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Service;

use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post;
use GuoLaiRen\PageBuilder\Model\Page;

final class BlogFrontendDataService
{
    public function __construct(
        private readonly Post $postModel,
        private readonly Category $categoryModel,
        private readonly BlogPageResolver $blogPageResolver
    ) {
    }

    public function resolveScopedWebsiteId(?Page $page, int $requestWebsiteId, int $fallbackWebsiteId = 0): int
    {
        return $this->blogPageResolver->resolveContentWebsiteId($page, $requestWebsiteId, $fallbackWebsiteId);
    }

    /**
     * @return array{
     *     website_id:int,
     *     blog_posts:list<array<string,mixed>>,
     *     blog_categories:list<array<string,mixed>>,
     *     recent_posts:list<array<string,mixed>>,
     *     current_page:int,
     *     page_size:int,
     *     has_more:bool,
     *     pagination:array<string,mixed>,
     *     all_tags:list<string>
     * }
     */
    public function getListViewData(int $websiteId, int $page = 1, int $pageSize = 12): array
    {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);

        $categories = $this->getCategoryList($websiteId);
        $categoryMap = $this->buildCategoryNameMap($categories);
        $result = $this->getPostListResult($websiteId, null, $page, $pageSize, $categoryMap);

        return [
            'website_id' => $websiteId,
            'blog_posts' => $result['items'],
            'blog_categories' => $categories,
            'recent_posts' => $this->getRecentPosts($websiteId, 10, $categoryMap),
            'current_page' => $page,
            'page_size' => $pageSize,
            'has_more' => count($result['items']) === $pageSize,
            'pagination' => $result['pagination'],
            'all_tags' => $this->getAllTags($websiteId),
        ];
    }

    /**
     * @return array{
     *     website_id:int,
     *     current_category:array<string,mixed>,
     *     blog_posts:list<array<string,mixed>>,
     *     blog_categories:list<array<string,mixed>>,
     *     recent_posts:list<array<string,mixed>>,
     *     current_page:int,
     *     page_size:int,
     *     has_more:bool,
     *     pagination:array<string,mixed>,
     *     all_tags:list<string>,
     *     category_posts:list<array<string,mixed>>
     * }|null
     */
    public function getCategoryViewData(int $websiteId, string $categorySlug, int $page = 1, int $pageSize = 12): ?array
    {
        $currentCategory = $this->getCategoryBySlug($websiteId, $categorySlug);
        if ($currentCategory === null) {
            return null;
        }

        $categories = $this->getCategoryList($websiteId);
        $categoryMap = $this->buildCategoryNameMap($categories);
        $result = $this->getPostListResult(
            $websiteId,
            (int)$currentCategory['category_id'],
            max(1, $page),
            max(1, $pageSize),
            $categoryMap
        );

        return [
            'website_id' => $websiteId,
            'current_category' => $currentCategory,
            'blog_posts' => $result['items'],
            'blog_categories' => $categories,
            'recent_posts' => $this->getRecentPosts($websiteId, 10, $categoryMap),
            'current_page' => max(1, $page),
            'page_size' => max(1, $pageSize),
            'has_more' => count($result['items']) === max(1, $pageSize),
            'pagination' => $result['pagination'],
            'all_tags' => $this->getAllTags($websiteId),
            'category_posts' => $this->getCategoryPosts((int)$currentCategory['category_id'], $websiteId, 20, $categoryMap),
        ];
    }

    /**
     * @return array{
     *     website_id:int,
     *     current_post:array<string,mixed>,
     *     post:array<string,mixed>,
     *     blog_post:array<string,mixed>,
     *     related_posts:list<array<string,mixed>>,
     *     blog_categories:list<array<string,mixed>>,
     *     recent_posts:list<array<string,mixed>>,
     *     all_tags:list<string>
     * }|null
     */
    public function getDetailViewData(int $websiteId, string $slug, int $relatedLimit = 6, int $recentLimit = 10): ?array
    {
        $categories = $this->getCategoryList($websiteId);
        $categoryMap = $this->buildCategoryNameMap($categories);
        $currentPost = $this->getPostBySlug($websiteId, $slug, $categoryMap);
        if ($currentPost === null) {
            return null;
        }

        return [
            'website_id' => $websiteId,
            'current_post' => $currentPost,
            'post' => $currentPost,
            'blog_post' => $currentPost,
            'related_posts' => $this->getRelatedPosts(
                $websiteId,
                (int)($currentPost['category_id'] ?? 0),
                (int)($currentPost['post_id'] ?? 0),
                $relatedLimit,
                $categoryMap
            ),
            'blog_categories' => $categories,
            'recent_posts' => $this->getRecentPosts($websiteId, $recentLimit, $categoryMap),
            'all_tags' => $this->getAllTags($websiteId),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getCategoryList(int $websiteId): array
    {
        if ($websiteId <= 0) {
            return [];
        }

        $category = clone $this->categoryModel;
        $items = $category->clear()
            ->where(Category::schema_fields_STATUS, Category::STATUS_ENABLED)
            ->where(Category::schema_fields_SITE_ID, $websiteId)
            ->order(Category::schema_fields_SORT_ORDER, 'ASC')
            ->select()
            ->fetch()
            ->getItems();

        $result = [];
        foreach ($items as $cat) {
            if (!$cat->getId()) {
                continue;
            }

            $slug = (string)$cat->getData(Category::schema_fields_SLUG);
            $result[] = [
                'category_id' => (int)$cat->getId(),
                'name' => (string)$cat->getData(Category::schema_fields_NAME),
                'slug' => $slug,
                'url' => '/blog/category/' . $slug,
                'description' => (string)($cat->getData(Category::schema_fields_DESCRIPTION) ?? ''),
                'cover_image' => (string)($cat->getData(Category::schema_fields_COVER_IMAGE) ?? ''),
                'meta_title' => (string)($cat->getData(Category::schema_fields_META_TITLE) ?? ''),
                'meta_description' => (string)($cat->getData(Category::schema_fields_META_DESCRIPTION) ?? ''),
            ];
        }

        return $result;
    }

    public function getCategoryBySlug(int $websiteId, string $slug): ?array
    {
        $slug = trim($slug);
        if ($websiteId <= 0 || $slug === '') {
            return null;
        }

        $category = clone $this->categoryModel;
        $category->clear()
            ->where(Category::schema_fields_SLUG, $slug)
            ->where(Category::schema_fields_STATUS, Category::STATUS_ENABLED)
            ->where(Category::schema_fields_SITE_ID, $websiteId)
            ->find()
            ->fetch();

        if (!$category->getId()) {
            return null;
        }

        return [
            'category_id' => (int)$category->getId(),
            'name' => (string)$category->getData(Category::schema_fields_NAME),
            'slug' => (string)$category->getData(Category::schema_fields_SLUG),
            'url' => '/blog/category/' . (string)$category->getData(Category::schema_fields_SLUG),
            'description' => (string)($category->getData(Category::schema_fields_DESCRIPTION) ?? ''),
            'cover_image' => (string)($category->getData(Category::schema_fields_COVER_IMAGE) ?? ''),
            'meta_title' => (string)($category->getData(Category::schema_fields_META_TITLE) ?? ''),
            'meta_description' => (string)($category->getData(Category::schema_fields_META_DESCRIPTION) ?? ''),
        ];
    }

    /**
     * @param array<int, string> $categoryNameMap
     */
    public function getPostBySlug(int $websiteId, string $slug, array $categoryNameMap = []): ?array
    {
        $slug = trim($slug);
        if ($websiteId <= 0 || $slug === '') {
            return null;
        }

        $post = clone $this->postModel;
        $post->clear()
            ->where(Post::schema_fields_SLUG, $slug)
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
            ->where(Post::schema_fields_SITE_ID, $websiteId)
            ->find()
            ->fetch();

        if (!$post->getId()) {
            return null;
        }

        if ($categoryNameMap === []) {
            $categoryNameMap = $this->buildCategoryNameMap($this->getCategoryList($websiteId));
        }

        return $this->mapPost($post, $categoryNameMap);
    }

    /**
     * @param array<int, string> $categoryNameMap
     * @return list<array<string,mixed>>
     */
    public function getRecentPosts(int $websiteId, int $limit = 10, array $categoryNameMap = []): array
    {
        if ($websiteId <= 0) {
            return [];
        }

        $post = clone $this->postModel;
        $items = $post->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
            ->where(Post::schema_fields_SITE_ID, $websiteId)
            ->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->limit(max(1, $limit))
            ->select()
            ->fetch()
            ->getItems();

        if ($categoryNameMap === []) {
            $categoryNameMap = $this->buildCategoryNameMap($this->getCategoryList($websiteId));
        }

        return $this->mapPosts($items, $categoryNameMap);
    }

    /**
     * @param array<int, string> $categoryNameMap
     * @return list<array<string,mixed>>
     */
    public function getRelatedPosts(int $websiteId, int $categoryId, int $excludePostId, int $limit = 6, array $categoryNameMap = []): array
    {
        if ($websiteId <= 0) {
            return [];
        }

        $post = clone $this->postModel;
        $query = $post->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
            ->where(Post::schema_fields_SITE_ID, $websiteId);

        if ($excludePostId > 0) {
            $query->where(Post::schema_fields_ID, $excludePostId, '!=');
        }
        if ($categoryId > 0) {
            $query->where(Post::schema_fields_CATEGORY_ID, $categoryId);
        }

        $items = $query->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->limit(max(1, $limit))
            ->select()
            ->fetch()
            ->getItems();

        if ($categoryNameMap === []) {
            $categoryNameMap = $this->buildCategoryNameMap($this->getCategoryList($websiteId));
        }

        return $this->mapPosts($items, $categoryNameMap);
    }

    /**
     * @param array<int, string> $categoryNameMap
     * @return list<array<string,mixed>>
     */
    public function getCategoryPosts(int $categoryId, int $websiteId, int $limit = 20, array $categoryNameMap = []): array
    {
        if ($websiteId <= 0 || $categoryId <= 0) {
            return [];
        }

        $result = $this->getPostListResult($websiteId, $categoryId, 1, max(1, $limit), $categoryNameMap);

        return $result['items'];
    }

    /**
     * @return list<string>
     */
    public function getAllTags(int $websiteId): array
    {
        if ($websiteId <= 0) {
            return [];
        }

        $post = clone $this->postModel;
        $items = $post->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
            ->where(Post::schema_fields_SITE_ID, $websiteId)
            ->where(Post::schema_fields_TAGS, '', '!=')
            ->select()
            ->fetch()
            ->getItems();

        $allTags = [];
        foreach ($items as $item) {
            if (!$item->getId()) {
                continue;
            }

            $rawTags = (string)($item->getData(Post::schema_fields_TAGS) ?? '');
            foreach (array_filter(array_map('trim', explode(',', $rawTags))) as $tag) {
                $allTags[$tag] = true;
            }
        }

        return array_slice(array_keys($allTags), 0, 20);
    }

    /**
     * @param array<int, string> $categoryNameMap
     * @return array{items:list<array<string,mixed>>, pagination:array<string,mixed>}
     */
    private function getPostListResult(
        int $websiteId,
        ?int $categoryId,
        int $page,
        int $pageSize,
        array $categoryNameMap = []
    ): array {
        if ($websiteId <= 0) {
            return ['items' => [], 'pagination' => []];
        }

        $post = clone $this->postModel;
        $query = $post->clear()
            ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
            ->where(Post::schema_fields_SITE_ID, $websiteId);

        if ($categoryId !== null && $categoryId > 0) {
            $query->where(Post::schema_fields_CATEGORY_ID, $categoryId);
        }

        $result = $query
            ->order(Post::schema_fields_IS_FEATURED, 'DESC')
            ->order(Post::schema_fields_PUBLISHED_AT, 'DESC')
            ->page(max(1, $page), max(1, $pageSize))
            ->pagination()
            ->select()
            ->fetch();

        if ($categoryNameMap === []) {
            $categoryNameMap = $this->buildCategoryNameMap($this->getCategoryList($websiteId));
        }

        return [
            'items' => $this->mapPosts($result->getItems(), $categoryNameMap),
            'pagination' => $result->getPagination(),
        ];
    }

    /**
     * @param list<array<string,mixed>> $categories
     * @return array<int, string>
     */
    private function buildCategoryNameMap(array $categories): array
    {
        $map = [];
        foreach ($categories as $category) {
            $map[(int)$category['category_id']] = (string)$category['name'];
        }

        return $map;
    }

    /**
     * @param iterable<object> $items
     * @param array<int, string> $categoryNameMap
     * @return list<array<string,mixed>>
     */
    private function mapPosts(iterable $items, array $categoryNameMap): array
    {
        $result = [];
        foreach ($items as $item) {
            if (!method_exists($item, 'getId') || !$item->getId()) {
                continue;
            }
            $result[] = $this->mapPost($item, $categoryNameMap);
        }

        return $result;
    }

    /**
     * @param array<int, string> $categoryNameMap
     * @return array<string,mixed>
     */
    private function mapPost(object $post, array $categoryNameMap): array
    {
        $slug = (string)$post->getData(Post::schema_fields_SLUG);
        $categoryId = (int)($post->getData(Post::schema_fields_CATEGORY_ID) ?? 0);

        return [
            'post_id' => (int)$post->getId(),
            'title' => (string)$post->getData(Post::schema_fields_TITLE),
            'slug' => $slug,
            'url' => '/blog/' . $slug,
            'summary' => (string)($post->getData(Post::schema_fields_SUMMARY) ?? ''),
            'content' => (string)($post->getData(Post::schema_fields_CONTENT) ?? ''),
            'cover_image' => (string)($post->getData(Post::schema_fields_COVER_IMAGE) ?? ''),
            'author' => (string)($post->getData(Post::schema_fields_AUTHOR) ?? ''),
            'published_at' => (string)($post->getData(Post::schema_fields_PUBLISHED_AT) ?? ''),
            'view_count' => (int)($post->getData(Post::schema_fields_VIEW_COUNT) ?? 0),
            'category_id' => $categoryId,
            'category_name' => $categoryNameMap[$categoryId] ?? '',
            'tags' => (string)($post->getData(Post::schema_fields_TAGS) ?? ''),
        ];
    }
}
