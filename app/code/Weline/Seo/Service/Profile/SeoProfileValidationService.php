<?php

declare(strict_types=1);

namespace Weline\Seo\Service\Profile;

class SeoProfileValidationService
{
    /**
     * @param array<string, mixed> $profile
     * @return array{valid:bool,errors:string[],warnings:string[]}
     */
    public function validate(array $profile): array
    {
        $errors = [];
        $warnings = [];
        $pageType = $this->normalizePageType((string)($profile['page_type'] ?? 'web_page'));
        $robots = strtolower((string)($profile['robots'] ?? ''));

        foreach (['title', 'description', 'canonical_url', 'robots'] as $field) {
            if ($this->stringValue($profile[$field] ?? '') === '') {
                $errors[] = $field . ' is required.';
            }
        }

        if (str_contains($robots, 'noindex')) {
            if ($this->isIncludedPayload($profile['sitemap'] ?? [])) {
                $errors[] = 'noindex pages must be excluded from sitemap metadata.';
            }
            if ($this->isIncludedPayload($profile['geo'] ?? [])) {
                $errors[] = 'noindex pages must be excluded from GEO feed metadata.';
            }
        }

        if ($pageType === 'product') {
            $product = $this->toArray($profile['product'] ?? []);
            if ($this->firstString($product, ['name', 'title']) === '') {
                $errors[] = 'product pages require product.name.';
            }
            if ($this->firstString($product, ['price', 'final_price']) === '') {
                $warnings[] = 'product pages should expose price for Product/Offer schema and GEO feeds.';
            }
            if ($this->firstString($profile, ['image']) === '' && $this->firstString($product, ['image']) === '') {
                $warnings[] = 'product pages should expose a primary image.';
            }
        }

        if (in_array($pageType, ['article', 'blog_post', 'post', 'news', 'news_article'], true)) {
            $article = $this->toArray($profile['article'] ?? []);
            if ($this->firstString($article, ['headline', 'title', 'name']) === '' && $this->firstString($profile, ['title']) === '') {
                $errors[] = 'article pages require a headline.';
            }
            if ($this->firstString($article, ['datePublished', 'date_published', 'published_at']) === '') {
                $warnings[] = 'article pages should expose datePublished.';
            }
            if ($this->firstString($article, ['author', 'author_name']) === '' && $this->toArray($article['authors'] ?? []) === []) {
                $warnings[] = 'article pages should expose author facts.';
            }
        }

        if (in_array($pageType, ['category', 'collection', 'collection_page', 'blog_list', 'blog_category'], true)
            && $this->toList($profile['item_list'] ?? []) === []) {
            $warnings[] = 'collection pages should expose item_list facts.';
        }

        if (in_array($pageType, ['news', 'news_article'], true)) {
            $news = $this->toArray($this->toArray($profile['sitemap'] ?? [])['news'] ?? []);
            foreach (['publication_name', 'language', 'publication_date', 'title'] as $field) {
                if ($this->newsString($news, $field) === '') {
                    $errors[] = 'news sitemap requires ' . $field . '.';
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private function normalizePageType(string $pageType): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($pageType)));
    }

    private function isIncludedPayload(mixed $payload): bool
    {
        if ($payload === null || $payload === '' || $payload === []) {
            return false;
        }
        if (!is_array($payload)) {
            return true;
        }
        if (array_key_exists('include', $payload)) {
            return filter_var($payload['include'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
        }
        return $payload !== [];
    }

    /**
     * @param array<string, mixed> $source
     * @param string[] $keys
     */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->stringValue($source[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    private function newsString(array $news, string $field): string
    {
        if ($field === 'publication_name') {
            $publication = $this->toArray($news['publication'] ?? []);
            return $this->stringValue($publication['name'] ?? $news['publication_name'] ?? $news['name'] ?? '');
        }
        if ($field === 'language') {
            $publication = $this->toArray($news['publication'] ?? []);
            return $this->stringValue($publication['language'] ?? $news['language'] ?? '');
        }
        if ($field === 'publication_date') {
            return $this->stringValue($news['publication_date'] ?? $news['datePublished'] ?? $news['published_at'] ?? '');
        }
        return $this->stringValue($news[$field] ?? $news['headline'] ?? '');
    }

    private function stringValue(mixed $value): string
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        return trim((string)$value);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return array<int, mixed>
     */
    private function toList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_is_list($value) ? $value : [$value];
    }
}
