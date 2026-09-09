<?php

declare(strict_types=1);

namespace Weline\Blog\Api\Data;

/**
 * Unified blog article projection for frontend, Search, SEO, and Sitemap.
 */
final class BlogArticle
{
    public const KIND_POST = 'blog_post';
    public const KIND_CMS = 'cms_page';

    /**
     * @param list<string> $categories
     * @param array<string, mixed> $sourceRef
     * @param array<string, mixed>|null $cmsPayload
     * @param list<string>|null $authorSameAs
     */
    public function __construct(
        public readonly string $contentKind,
        public readonly int $websiteId,
        public readonly string $locale,
        public readonly string $slug,
        public readonly string $identifier,
        public readonly string $title,
        public readonly string $excerpt,
        public readonly ?string $publishedAt,
        public readonly ?string $updatedAt,
        public readonly ?string $author,
        public readonly ?string $coverImage,
        public readonly array $categories,
        public readonly string $canonicalUrl,
        public readonly string $publicUrl,
        public readonly array $sourceRef,
        public readonly ?string $keywords = null,
        public readonly ?array $cmsPayload = null,
        public readonly ?string $authorUrl = null,
        public readonly ?string $authorBio = null,
        public readonly ?string $authorJobTitle = null,
        public readonly ?array $authorSameAs = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $sourceRef = is_array($row['source_ref'] ?? null) ? $row['source_ref'] : [];
        $categories = is_array($row['categories'] ?? null) ? array_values(array_map('strval', $row['categories'])) : [];
        $cmsPayload = is_array($row['cms_payload'] ?? null) ? $row['cms_payload'] : null;
        $sameAs = self::normalizeSameAs($row['author_same_as'] ?? null);

        return new self(
            contentKind: (string)($row['content_kind'] ?? self::KIND_POST),
            websiteId: (int)($row['website_id'] ?? 0),
            locale: (string)($row['locale'] ?? ''),
            slug: (string)($row['slug'] ?? ''),
            identifier: (string)($row['identifier'] ?? ''),
            title: (string)($row['title'] ?? ''),
            excerpt: (string)($row['excerpt'] ?? ''),
            publishedAt: isset($row['published_at']) ? (string)$row['published_at'] : null,
            updatedAt: isset($row['updated_at']) ? (string)$row['updated_at'] : null,
            author: isset($row['author']) ? (string)$row['author'] : null,
            coverImage: isset($row['cover_image']) ? (string)$row['cover_image'] : null,
            categories: $categories,
            canonicalUrl: (string)($row['canonical_url'] ?? ''),
            publicUrl: (string)($row['public_url'] ?? ''),
            sourceRef: $sourceRef,
            keywords: isset($row['keywords']) ? (string)$row['keywords'] : null,
            cmsPayload: $cmsPayload,
            authorUrl: isset($row['author_url']) ? (string)$row['author_url'] : null,
            authorBio: isset($row['author_bio']) ? (string)$row['author_bio'] : null,
            authorJobTitle: isset($row['author_job_title']) ? (string)$row['author_job_title'] : null,
            authorSameAs: $sameAs,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content_kind' => $this->contentKind,
            'website_id' => $this->websiteId,
            'locale' => $this->locale,
            'slug' => $this->slug,
            'identifier' => $this->identifier,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'published_at' => $this->publishedAt,
            'updated_at' => $this->updatedAt,
            'author' => $this->author,
            'author_url' => $this->authorUrl,
            'author_bio' => $this->authorBio,
            'author_job_title' => $this->authorJobTitle,
            'author_same_as' => $this->authorSameAs,
            'cover_image' => $this->coverImage,
            'categories' => $this->categories,
            'canonical_url' => $this->canonicalUrl,
            'public_url' => $this->publicUrl,
            'source_ref' => $this->sourceRef,
            'keywords' => $this->keywords,
            'cms_payload' => $this->cmsPayload,
        ];
    }

    public function entityId(): int
    {
        if ($this->contentKind === self::KIND_POST) {
            return (int)($this->sourceRef['post_id'] ?? 0);
        }

        return (int)($this->sourceRef['cms_page_id'] ?? 0);
    }

    /**
     * @return list<string>|null
     */
    public static function normalizeSameAs(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $urls = [];
            foreach ($raw as $entry) {
                if (!is_string($entry)) {
                    continue;
                }
                $url = trim($entry);
                if ($url !== '' && preg_match('#^https?://#i', $url)) {
                    $urls[] = $url;
                }
            }
            $urls = array_values(array_unique($urls));

            return $urls === [] ? null : $urls;
        }
        if (!is_string($raw)) {
            return null;
        }
        $parts = preg_split('/[\s,，;；|]+/u', $raw) ?: [];
        $urls = [];
        foreach ($parts as $part) {
            $url = trim((string)$part);
            if ($url !== '' && preg_match('#^https?://#i', $url)) {
                $urls[] = $url;
            }
        }
        $urls = array_values(array_unique($urls));

        return $urls === [] ? null : $urls;
    }
}
