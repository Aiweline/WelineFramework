<?php

declare(strict_types=1);

namespace Weline\Geo\Service;

class SeoProfileGeoMetadataNormalizer
{
    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function normalize(array $profile): array
    {
        $metadata = [];
        foreach ([$profile['metadata'] ?? [], $profile['_weline_geo'] ?? [], $profile['geo'] ?? []] as $source) {
            $metadata = array_replace_recursive($metadata, $this->toArray($source));
        }

        foreach ([
            'type' => ['type', 'item_type', 'page_type'],
            'title' => ['title', 'headline', 'name'],
            'summary' => ['summary', 'description', 'content_text'],
            'canonical_url' => ['canonical_url', 'url'],
            'image' => ['image', 'cover_image', 'featured_image'],
            'published_at' => ['published_at', 'date_published', 'datePublished'],
            'modified_at' => ['modified_at', 'updated_at', 'date_modified', 'dateModified'],
            'author' => ['author', 'author_name'],
            'content_html' => ['content_html'],
        ] as $target => $keys) {
            $this->copyFirst($metadata, $target, $profile, $keys);
        }

        foreach (['images', 'authors', 'tags', 'keywords'] as $key) {
            if (!isset($metadata[$key]) && isset($profile[$key])) {
                $value = $this->listValue($profile[$key]);
                if ($value !== []) {
                    $metadata[$key] = $value;
                }
            }
        }

        $this->mergeArticleFacts($metadata, $this->toArray($profile['article'] ?? []));
        $this->mergeProductFacts($metadata, $this->toArray($profile['product'] ?? []));

        if (!isset($metadata['url']) && isset($metadata['canonical_url'])) {
            $metadata['url'] = $metadata['canonical_url'];
        }
        if (!isset($metadata['type']) && isset($profile['page_type'])) {
            $metadata['type'] = $this->normalizeType((string)$profile['page_type']);
        }

        return $this->filterEmpty($metadata);
    }

    /**
     * @param array<string, mixed> $profile
     * @return array<string, mixed>
     */
    public function toFeedItemData(array $profile): array
    {
        $metadata = $this->normalize($profile);
        $published = $metadata['published_at'] ?? $profile['published_at'] ?? time();

        return [
            'title' => (string)($metadata['title'] ?? $profile['title'] ?? ''),
            'content' => (string)($metadata['summary'] ?? $profile['description'] ?? $profile['content'] ?? ''),
            'url' => (string)($metadata['canonical_url'] ?? $metadata['url'] ?? $profile['canonical_url'] ?? $profile['url'] ?? ''),
            'metadata' => $metadata,
            'is_published' => $this->isIndexable($profile) ? 1 : 0,
            'published_at' => $this->timestamp($published),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $source
     * @param string[] $keys
     */
    private function copyFirst(array &$metadata, string $target, array $source, array $keys): void
    {
        if (isset($metadata[$target]) && $metadata[$target] !== '') {
            return;
        }
        foreach ($keys as $key) {
            if (!array_key_exists($key, $source)) {
                continue;
            }
            $value = $source[$key];
            if (is_array($value)) {
                if ($value !== []) {
                    $metadata[$target] = $value;
                    return;
                }
                continue;
            }
            $value = trim((string)$value);
            if ($value !== '') {
                $metadata[$target] = $target === 'type' ? $this->normalizeType($value) : $value;
                return;
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $article
     */
    private function mergeArticleFacts(array &$metadata, array $article): void
    {
        foreach ([
            'title' => ['headline', 'title', 'name'],
            'summary' => ['summary', 'description', 'excerpt'],
            'image' => ['image', 'cover_image', 'featured_image'],
            'published_at' => ['datePublished', 'date_published', 'published_at'],
            'modified_at' => ['dateModified', 'date_modified', 'updated_at', 'modified_at'],
            'author' => ['author', 'author_name'],
            'section' => ['articleSection', 'article_section', 'section', 'category'],
        ] as $target => $keys) {
            $this->copyFirst($metadata, $target, $article, $keys);
        }
        foreach (['authors', 'tags', 'keywords'] as $key) {
            if (!isset($metadata[$key]) && isset($article[$key])) {
                $value = $this->listValue($article[$key]);
                if ($value !== []) {
                    $metadata[$key] = $value;
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $product
     */
    private function mergeProductFacts(array &$metadata, array $product): void
    {
        if ($product === []) {
            return;
        }
        $metadata['type'] = $metadata['type'] ?? 'product';
        foreach ([
            'product_id' => ['product_id', 'id', 'entity_id'],
            'sku' => ['sku'],
            'spu' => ['spu', 'product_group_id', 'productGroupID'],
            'brand' => ['brand', 'brand_name', 'manufacturer'],
            'price' => ['price', 'final_price'],
            'currency' => ['currency', 'price_currency'],
            'availability' => ['availability', 'stock_status'],
            'stock' => ['stock', 'qty'],
            'category' => ['category'],
            'category_path' => ['category_path'],
            'rating' => ['rating', 'rating_value'],
            'review_count' => ['review_count', 'reviewCount'],
            'image' => ['image', 'main_image'],
        ] as $target => $keys) {
            $this->copyFirst($metadata, $target, $product, $keys);
        }
        if (!isset($metadata['images'])) {
            $images = $this->listValue($product['images'] ?? []);
            if ($images !== []) {
                $metadata['images'] = $images;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, mixed>
     */
    private function listValue(mixed $value): array
    {
        if ($value === null || $value === '' || $value === false) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_is_list($decoded) ? $decoded : [$decoded];
            }
            if (str_contains($value, ',')) {
                return array_values(array_filter(array_map('trim', explode(',', $value))));
            }
            return [trim($value)];
        }
        if (!is_array($value)) {
            return [$value];
        }
        return array_is_list($value) ? $value : [$value];
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function filterEmpty(array $metadata): array
    {
        return array_filter($metadata, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function normalizeType(string $type): string
    {
        $type = strtolower(str_replace([' ', '-'], '_', trim($type)));
        return match ($type) {
            'blog_post', 'post', 'article' => 'article',
            'news_article', 'news' => 'news',
            'category', 'collection', 'collection_page', 'blog_list', 'blog_category' => 'collection',
            default => $type,
        };
    }

    /**
     * @param array<string, mixed> $profile
     */
    private function isIndexable(array $profile): bool
    {
        $robots = strtolower((string)($profile['robots'] ?? ''));
        $geo = $this->toArray($profile['geo'] ?? []);
        if (isset($geo['include']) && filter_var($geo['include'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === false) {
            return false;
        }
        return !str_contains($robots, 'noindex');
    }

    private function timestamp(mixed $value): int
    {
        if (is_numeric($value)) {
            return (int)$value;
        }
        if (is_string($value) && trim($value) !== '') {
            return strtotime($value) ?: time();
        }
        return time();
    }
}
