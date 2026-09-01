<?php

declare(strict_types=1);

namespace Weline\Blog\Service;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Model\Post;

final class CmsBlogPageAdapter
{
    public function isAvailable(): bool
    {
        return function_exists('w_query');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPublishedPage(int $websiteId, string $slug): ?array
    {
        if (!$this->isAvailable() || $websiteId < 0 || trim($slug) === '') {
            return null;
        }

        try {
            $page = w_query('cms', 'getPage', [
                'identifier' => BlogNamespace::identifierFromSlug($slug),
                'path_group' => BlogNamespace::PREFIX,
                'website_id' => $websiteId,
                'status' => 'published',
            ]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($page) ? $page : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPageForConflictCheck(int $websiteId, string $slug): ?array
    {
        if (!$this->isAvailable() || $websiteId < 0 || trim($slug) === '') {
            return null;
        }

        try {
            $page = w_query('cms', 'getPage', [
                'identifier' => BlogNamespace::identifierFromSlug($slug),
                'path_group' => BlogNamespace::PREFIX,
                'website_id' => $websiteId,
            ]);
        } catch (\Throwable) {
            return null;
        }

        return is_array($page) ? $page : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function renderPagePayload(int $websiteId, string $slug, int $pageId = 0): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $params = [
            'identifier' => BlogNamespace::identifierFromSlug($slug),
            'path_group' => BlogNamespace::PREFIX,
            'website_id' => $websiteId,
        ];
        if ($pageId > 0) {
            $params['page_id'] = $pageId;
        }

        try {
            $payload = w_query('cms', 'renderPagePayload', $params);
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param array<string, mixed> $page
     */
    public function toBlogArticle(array $page, string $baseUrl = ''): BlogArticle
    {
        $slug = trim(strtolower((string)($page['slug'] ?? BlogNamespace::slugFromIdentifier((string)($page['identifier'] ?? '')))));
        $websiteId = (int)($page['website_id'] ?? 0);
        $locale = (string)($page['locale_code'] ?? $page['locale'] ?? '');
        $path = BlogNamespace::publicPath($slug);
        $absolute = $this->absoluteUrl($path, $baseUrl);

        return new BlogArticle(
            contentKind: BlogArticle::KIND_CMS,
            websiteId: $websiteId,
            locale: $locale,
            slug: $slug,
            identifier: BlogNamespace::identifierFromSlug($slug),
            title: (string)($page['title'] ?? ''),
            excerpt: (string)($page['meta_description'] ?? $page['description'] ?? $page['title'] ?? ''),
            publishedAt: (string)($page['published_at'] ?? $page['updated_at'] ?? '') ?: null,
            updatedAt: (string)($page['updated_at'] ?? '') ?: null,
            author: null,
            coverImage: (string)($page['meta_image'] ?? $page['cover_image'] ?? '') ?: null,
            categories: [],
            canonicalUrl: $absolute !== '' ? $absolute : $path,
            publicUrl: $path,
            sourceRef: [
                'kind' => BlogArticle::KIND_CMS,
                'cms_page_id' => (int)($page['page_id'] ?? 0),
            ],
            keywords: (string)($page['meta_keywords'] ?? '') ?: null,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPublishedBlogPages(int $websiteId, int $page = 1, int $pageSize = 200): array
    {
        if (!$this->isAvailable() || $websiteId < 0) {
            return [];
        }

        try {
            $result = w_query('cms', 'listPages', [
                'website_id' => $websiteId,
                'path_group' => BlogNamespace::PREFIX,
                'status' => 'published',
                'page' => $page,
                'page_size' => $pageSize,
            ]);
        } catch (\Throwable) {
            return [];
        }

        $items = is_array($result['items'] ?? null) ? $result['items'] : [];

        return array_values(array_filter($items, static fn($row): bool => is_array($row)));
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
