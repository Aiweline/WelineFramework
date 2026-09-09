<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Category as BlogCategory;
use Weline\Blog\Model\Post;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * One-shot / CLI backfill of published storefront URLs into GEO FeedItems.
 */
final class FeedBackfillService
{
    public function __construct(
        private readonly EnsureDefaultFeedsService $ensureDefaultFeeds,
        private readonly FeedSubmitService $feedSubmit,
    ) {
    }

    /**
     * @return array{feeds: array<string,int>, products: int, articles: int, blog_categories: int, catalog_categories: int}
     */
    public function backfill(int $limitPerScope = 5000): array
    {
        $ensured = $this->ensureDefaultFeeds->ensure();
        $stats = [
            'feeds' => $ensured['feeds'],
            'products' => 0,
            'articles' => 0,
            'blog_categories' => 0,
            'catalog_categories' => 0,
        ];

        $stats['products'] = $this->backfillProducts($limitPerScope);
        $stats['articles'] = $this->backfillArticles($limitPerScope);
        $stats['blog_categories'] = $this->backfillBlogCategories($limitPerScope);
        $stats['catalog_categories'] = $this->backfillCatalogCategories($limitPerScope);

        return $stats;
    }

    private function backfillProducts(int $limit): int
    {
        if (!class_exists(\Weline\Product\Service\ProductSitemapUrlService::class)) {
            return 0;
        }

        $count = 0;
        try {
            /** @var \Weline\Product\Service\ProductSitemapUrlService $urls */
            $urls = ObjectManager::getInstance(\Weline\Product\Service\ProductSitemapUrlService::class);
            foreach ($this->websiteIds() as $websiteId) {
                foreach ($urls->getUrlsForWebsite($websiteId) as $row) {
                    if ($count >= $limit) {
                        return $count;
                    }
                    $url = trim((string)($row['loc'] ?? $row['url'] ?? ''));
                    if ($url === '') {
                        continue;
                    }
                    $productId = (int)($row['product_id'] ?? $row['subject_id'] ?? 0);
                    $this->feedSubmit->requestSubmit($url, 'product', [
                        'subject_type' => 'product',
                        'subject_id' => $productId,
                        'item_type' => 'product',
                        'item_id' => $productId,
                        'website_id' => $websiteId,
                        'title' => (string)($row['title'] ?? $row['name'] ?? $url),
                        'content' => (string)($row['description'] ?? ''),
                        'is_published' => 1,
                        'published_at' => time(),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                w_log_error('GEO product backfill failed: ' . $e->getMessage());
            }
        }

        return $count;
    }

    private function backfillArticles(int $limit): int
    {
        if (!class_exists(Post::class)) {
            return 0;
        }

        $count = 0;
        try {
            /** @var Post $post */
            $post = ObjectManager::getInstance(Post::class);
            $rows = $post->reset()
                ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
                ->order(Post::schema_fields_ID, 'DESC')
                ->limit($limit)
                ->select()
                ->fetchArray();
            $bases = $this->websiteBaseUrls();
            foreach ($rows as $row) {
                $slug = trim((string)($row[Post::schema_fields_SLUG] ?? ''), '/');
                if ($slug === '') {
                    continue;
                }
                $websiteId = (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0);
                $path = BlogNamespace::publicPath($slug);
                $base = rtrim((string)($bases[$websiteId] ?? reset($bases) ?: ''), '/');
                $url = $base !== '' ? $base . $path : $path;
                $postId = (int)($row[Post::schema_fields_ID] ?? 0);
                $this->feedSubmit->requestSubmit($url, 'article', [
                    'subject_type' => 'article',
                    'subject_id' => $postId,
                    'item_type' => 'article',
                    'item_id' => $postId,
                    'website_id' => $websiteId,
                    'title' => (string)($row[Post::schema_fields_TITLE] ?? ''),
                    'content' => (string)($row[Post::schema_fields_EXCERPT] ?? ''),
                    'is_published' => 1,
                    'published_at' => strtotime((string)($row[Post::schema_fields_PUBLISHED_AT] ?? '')) ?: time(),
                    'updated_at' => (string)($row[Post::schema_fields_UPDATED_AT] ?? date('Y-m-d H:i:s')),
                ]);
                $count++;
            }
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                w_log_error('GEO article backfill failed: ' . $e->getMessage());
            }
        }

        return $count;
    }

    private function backfillBlogCategories(int $limit): int
    {
        if (!class_exists(BlogCategory::class)) {
            return 0;
        }

        $count = 0;
        try {
            /** @var BlogCategory $category */
            $category = ObjectManager::getInstance(BlogCategory::class);
            $rows = $category->reset()
                ->order(BlogCategory::schema_fields_ID, 'DESC')
                ->limit($limit)
                ->select()
                ->fetchArray();
            $bases = $this->websiteBaseUrls();
            foreach ($rows as $row) {
                $slug = trim((string)($row[BlogCategory::schema_fields_SLUG] ?? ''), '/');
                if ($slug === '') {
                    continue;
                }
                $websiteId = (int)($row[BlogCategory::schema_fields_WEBSITE_ID] ?? 0);
                $path = BlogNamespace::categoryPublicPath($slug);
                $base = rtrim((string)($bases[$websiteId] ?? reset($bases) ?: ''), '/');
                $url = $base !== '' ? $base . $path : $path;
                $categoryId = (int)($row[BlogCategory::schema_fields_ID] ?? 0);
                $this->feedSubmit->requestSubmit($url, 'category', [
                    'subject_type' => 'category',
                    'subject_id' => $categoryId,
                    'item_type' => 'category',
                    'item_id' => $categoryId,
                    'website_id' => $websiteId,
                    'title' => (string)($row[BlogCategory::schema_fields_NAME] ?? ''),
                    'content' => '',
                    'is_published' => 1,
                    'published_at' => time(),
                    'updated_at' => (string)($row[BlogCategory::schema_fields_UPDATED_AT] ?? date('Y-m-d H:i:s')),
                ]);
                $count++;
            }
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                w_log_error('GEO blog category backfill failed: ' . $e->getMessage());
            }
        }

        return $count;
    }

    private function backfillCatalogCategories(int $limit): int
    {
        if (!class_exists(\Weline\Product\Service\StorefrontCategoryTreeIndex::class)) {
            return 0;
        }

        $count = 0;
        try {
            /** @var \Weline\Product\Service\StorefrontCategoryTreeIndex $tree */
            $tree = ObjectManager::getInstance(\Weline\Product\Service\StorefrontCategoryTreeIndex::class);
            $bases = $this->websiteBaseUrls();
            foreach ($this->websiteIds() as $websiteId) {
                $index = $tree->forWebsite($websiteId);
                if (!is_array($index)) {
                    continue;
                }
                $flat = array_values(array_filter(
                    $index['by_id'] ?? [],
                    static fn($node): bool => is_array($node)
                ));
                foreach ($flat as $node) {
                    if ($count >= $limit) {
                        return $count;
                    }
                    $url = trim((string)($node['url'] ?? ''));
                    $categoryId = (int)($node['id'] ?? $node['category_id'] ?? 0);
                    if ($url === '') {
                        $path = trim((string)($node['path'] ?? $node['handle'] ?? ''), '/');
                        if ($path === '') {
                            continue;
                        }
                        $base = rtrim((string)($bases[$websiteId] ?? reset($bases) ?: ''), '/');
                        $url = ($base !== '' ? $base : '') . '/category/' . $path;
                    }
                    if (!preg_match('#^https?://#i', $url)) {
                        $base = rtrim((string)($bases[$websiteId] ?? reset($bases) ?: ''), '/');
                        if ($base !== '' && str_starts_with($url, '/')) {
                            $url = $base . $url;
                        }
                    }
                    $this->feedSubmit->requestSubmit($url, 'category', [
                        'subject_type' => 'category',
                        'subject_id' => $categoryId,
                        'item_type' => 'category',
                        'item_id' => $categoryId > 0 ? $categoryId : (int)sprintf('%u', crc32($url)),
                        'website_id' => $websiteId,
                        'title' => (string)($node['name'] ?? $node['title'] ?? $url),
                        'content' => (string)($node['description'] ?? ''),
                        'is_published' => 1,
                        'published_at' => time(),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                w_log_error('GEO catalog category backfill failed: ' . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * @return list<int>
     */
    private function websiteIds(): array
    {
        return array_keys($this->websiteBaseUrls());
    }

    /**
     * @return array<int, string>
     */
    private function websiteBaseUrls(): array
    {
        $urls = [];
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            foreach ($website->reset()->select()->fetchArray() as $row) {
                $id = (int)($row[Website::schema_fields_ID] ?? $row['website_id'] ?? -1);
                $url = rtrim((string)($row[Website::schema_fields_URL] ?? $row['url'] ?? ''), '/');
                if ($id >= 0 && $url !== '') {
                    $urls[$id] = $url;
                }
            }
        } catch (\Throwable) {
        }

        return $urls;
    }
}
