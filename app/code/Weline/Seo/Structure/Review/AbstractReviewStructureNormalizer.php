<?php

declare(strict_types=1);

namespace Weline\Seo\Structure\Review;

use Weline\Seo\Structure\AbstractSeoStructureNormalizer;
use Weline\Seo\Structure\SeoStructureFactsInterface;

/**
 * 评价结构化事实归一化基类。
 *
 * 标准 context 键：reviews
 * 每项典型字段：author、reviewBody、reviewRating、datePublished
 */
abstract class AbstractReviewStructureNormalizer extends AbstractSeoStructureNormalizer implements SeoStructureFactsInterface
{
    /**
     * @return array<int, array<string, mixed>>
     */
    abstract public function normalize(mixed $reviews): array;

    /**
     * @param array<string, mixed> $review
     * @return array<string, mixed>|null
     */
    protected function normalizeReview(array $review): ?array
    {
        $body = $this->firstString($review, ['reviewBody', 'body', 'content', 'text']);
        $author = $this->firstString($review, ['author', 'author_name', 'reviewer']);
        if ($body === '' && $author === '') {
            return null;
        }

        $normalized = [];
        if ($body !== '') {
            $normalized['reviewBody'] = $body;
        }
        if ($author !== '') {
            $normalized['author'] = $author;
        }
        $rating = $this->firstString($review, ['ratingValue', 'rating', 'score']);
        if ($rating !== '') {
            $normalized['reviewRating'] = [
                '@type' => 'Rating',
                'ratingValue' => $rating,
            ];
        }
        $published = $this->firstString($review, ['datePublished', 'published_at', 'created_at']);
        if ($published !== '') {
            $normalized['datePublished'] = $published;
        }

        return $normalized === [] ? null : $normalized;
    }
}
