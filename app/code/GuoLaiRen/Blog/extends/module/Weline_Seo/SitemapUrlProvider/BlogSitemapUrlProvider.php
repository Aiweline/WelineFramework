<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Extends\Module\Weline_Seo\SitemapUrlProvider;

use GuoLaiRen\Blog\Model\Category;
use GuoLaiRen\Blog\Model\Post;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Provider\AbstractSitemapUrlProvider;

class BlogSitemapUrlProvider extends AbstractSitemapUrlProvider
{
    public function getScope(): string
    {
        return 'blog';
    }

    public function getModule(): string
    {
        return 'GuoLaiRen_Blog';
    }

    /**
     * @return int[]
     */
    public function getWebsiteIds(): array
    {
        $websites = w_query('websites', 'getWebsiteList', []);
        $ids = [];
        foreach ((array)$websites as $website) {
            $id = (int)($website['website_id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    public function getUrlsForWebsite(int $websiteId): array
    {
        $website = w_query('websites', 'getWebsiteById', ['website_id' => $websiteId]);
        $baseUrl = rtrim((string)($website['url'] ?? w_env('website.url', '')), '/');
        if ($baseUrl === '') {
            return [];
        }

        $categories = $this->categoryMap($websiteId);
        return array_merge(
            $this->postUrls($websiteId, $baseUrl, $website, $categories),
            $this->categoryUrls($websiteId, $baseUrl)
        );
    }

    public function getDescription(): string
    {
        return __('GuoLaiRen Blog sitemap URL and news metadata provider');
    }

    /**
     * @param array<string, mixed> $website
     * @param array<int, array<string, mixed>> $categories
     * @return array<int, array<string, mixed>>
     */
    private function postUrls(int $websiteId, string $baseUrl, array $website, array $categories): array
    {
        /** @var Post $post */
        $post = ObjectManager::getInstance(Post::class);
        try {
            $rows = $post->reset()
                ->where(Post::schema_fields_SITE_ID, $websiteId)
                ->where(Post::schema_fields_STATUS, Post::STATUS_PUBLISHED)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $urls = [];
        foreach ($rows as $row) {
            $postId = (int)($row[Post::schema_fields_ID] ?? 0);
            $slug = trim((string)($row[Post::schema_fields_SLUG] ?? ''), '/');
            if ($postId <= 0 || $slug === '') {
                continue;
            }
            $category = $categories[(int)($row[Post::schema_fields_CATEGORY_ID] ?? 0)] ?? [];
            $loc = $baseUrl . '/blog/' . rawurlencode($slug);
            $metadata = [
                'images' => $this->imageMetadata((string)($row[Post::schema_fields_COVER_IMAGE] ?? ''), $baseUrl, (string)($row[Post::schema_fields_TITLE] ?? '')),
            ];
            if ($this->isNewsCategory($category) && $this->isRecentNews($row[Post::schema_fields_PUBLISHED_AT] ?? '')) {
                $metadata['news'] = [
                    'publication' => [
                        'name' => (string)($website['name'] ?? 'Weline'),
                        'language' => $this->newsLanguage((string)($website['locale'] ?? w_env('user.lang', 'en_US'))),
                    ],
                    'publication_date' => (string)($row[Post::schema_fields_PUBLISHED_AT] ?? ''),
                    'title' => (string)($row[Post::schema_fields_TITLE] ?? ''),
                ];
            }

            $urls[] = [
                'url_key' => 'post-' . $postId,
                'loc' => $loc,
                'lastmod' => $this->formatDate((string)($row[Post::schema_fields_UPDATED_AT] ?? $row[Post::schema_fields_PUBLISHED_AT] ?? '')),
                'changefreq' => isset($metadata['news']) ? 'daily' : 'weekly',
                'priority' => isset($metadata['news']) ? '0.8' : '0.7',
                'metadata' => $metadata,
            ];
        }
        return $urls;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categoryUrls(int $websiteId, string $baseUrl): array
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        try {
            $rows = $category->reset()
                ->where(Category::schema_fields_SITE_ID, $websiteId)
                ->where(Category::schema_fields_STATUS, Category::STATUS_ENABLED)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $urls = [];
        foreach ($rows as $row) {
            $categoryId = (int)($row[Category::schema_fields_ID] ?? 0);
            $slug = trim((string)($row[Category::schema_fields_SLUG] ?? ''), '/');
            if ($categoryId <= 0 || $slug === '') {
                continue;
            }
            $urls[] = [
                'url_key' => 'category-' . $categoryId,
                'loc' => $baseUrl . '/blog/category/' . rawurlencode($slug),
                'lastmod' => $this->formatDate((string)($row[Category::schema_fields_UPDATED_AT] ?? '')),
                'changefreq' => 'weekly',
                'priority' => '0.6',
                'metadata' => [
                    'images' => $this->imageMetadata((string)($row[Category::schema_fields_COVER_IMAGE] ?? ''), $baseUrl, (string)($row[Category::schema_fields_NAME] ?? '')),
                ],
            ];
        }
        return $urls;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function categoryMap(int $websiteId): array
    {
        /** @var Category $category */
        $category = ObjectManager::getInstance(Category::class);
        try {
            $rows = $category->reset()
                ->where(Category::schema_fields_SITE_ID, $websiteId)
                ->where(Category::schema_fields_STATUS, Category::STATUS_ENABLED)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $id = (int)($row[Category::schema_fields_ID] ?? 0);
            if ($id > 0) {
                $map[$id] = $row;
            }
        }
        return $map;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function imageMetadata(string $image, string $baseUrl, string $title): array
    {
        $loc = $this->absoluteUrl($image, $baseUrl);
        if ($loc === '') {
            return [];
        }
        return [[
            'loc' => $loc,
            'title' => $title,
        ]];
    }

    /**
     * @param array<string, mixed> $category
     */
    private function isNewsCategory(array $category): bool
    {
        foreach ([Category::schema_fields_SLUG, Category::schema_fields_NAME] as $key) {
            $value = strtolower(trim((string)($category[$key] ?? '')));
            if ($value !== '' && preg_match('/(^|[-_\\s])news($|[-_\\s])/', $value)) {
                return true;
            }
        }
        return false;
    }

    private function isRecentNews(mixed $publishedAt): bool
    {
        $time = is_numeric($publishedAt) ? (int)$publishedAt : strtotime((string)$publishedAt);
        return $time !== false && $time >= time() - 2 * 86400;
    }

    private function newsLanguage(string $language): string
    {
        $language = strtolower(str_replace('_', '-', trim($language)));
        return match ($language) {
            'zh', 'zh-hans', 'zh-hans-cn', 'zh-cn' => 'zh-cn',
            'zh-hant', 'zh-hant-tw', 'zh-tw' => 'zh-tw',
            default => $language ?: 'en',
        };
    }

    private function formatDate(string $date): string
    {
        $time = strtotime($date);
        return date('Y-m-d', $time ?: time());
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    }
}
