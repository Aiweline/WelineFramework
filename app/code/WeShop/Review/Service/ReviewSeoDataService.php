<?php

declare(strict_types=1);

namespace WeShop\Review\Service;

use WeShop\Review\Model\Review;
use Weline\Framework\Manager\ObjectManager;

class ReviewSeoDataService
{
    private const MAX_REVIEW_ITEMS = 5;
    private const BODY_MAX_LENGTH = 500;
    private const TITLE_MAX_LENGTH = 120;
    private const AUTHOR_MAX_LENGTH = 80;

    public function __construct(
        private readonly ReviewService $reviewService,
        private readonly ?ReviewRatingOptionService $ratingOptionService = null
    ) {
    }

    /**
     * @return array{
     *     aggregate: array{rating_value: float, review_count: int, best_rating: int, worst_rating: int},
     *     items: array<int, array<string, mixed>>
     * }
     */
    public function getProductReviewSeo(int $productId, string $locale, int $limit = self::MAX_REVIEW_ITEMS): array
    {
        $limit = max(0, min(self::MAX_REVIEW_ITEMS, $limit));
        if ($productId <= 0) {
            return $this->emptyPayload();
        }

        $payload = $this->reviewService->getProductReviews($productId, 1, max(1, $limit));
        $rows = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $ratingOptionMap = $this->getRatingOptionService()->getEnabledOptionMap();

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !$this->isApprovedRow($row)) {
                continue;
            }

            $item = $this->mapReviewItem($row, $ratingOptionMap);
            if ($item !== []) {
                $items[] = $item;
            }

            if (count($items) >= $limit) {
                break;
            }
        }

        $reviewCount = $this->resolveReviewCount($payload, $rows, $items);
        $ratingValue = $reviewCount > 0 ? round((float) $this->reviewService->getAverageRating($productId), 2) : 0.0;

        return [
            'aggregate' => [
                'rating_value' => $ratingValue,
                'review_count' => $reviewCount,
                'best_rating' => 5,
                'worst_rating' => 1,
            ],
            'items' => $items,
        ];
    }

    /**
     * @return array{
     *     aggregate: array{rating_value: float, review_count: int, best_rating: int, worst_rating: int},
     *     items: array<int, array<string, mixed>>
     * }
     */
    private function emptyPayload(): array
    {
        return [
            'aggregate' => [
                'rating_value' => 0.0,
                'review_count' => 0,
                'best_rating' => 5,
                'worst_rating' => 1,
            ],
            'items' => [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isApprovedRow(array $row): bool
    {
        $status = trim((string) ($row[Review::schema_fields_STATUS] ?? $row['status'] ?? ''));
        return $status === '' || $status === Review::STATUS_APPROVED;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, mixed> $rows
     * @param array<int, array<string, mixed>> $items
     */
    private function resolveReviewCount(array $payload, array $rows, array $items): int
    {
        $reportedTotal = max(0, (int) ($payload['total'] ?? count($items)));
        if ($this->hasExplicitNonApprovedRows($rows) && $reportedTotal <= count($rows)) {
            return count($items);
        }

        return $reportedTotal;
    }

    /**
     * @param array<int, mixed> $rows
     */
    private function hasExplicitNonApprovedRows(array $rows): bool
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $status = trim((string) ($row[Review::schema_fields_STATUS] ?? $row['status'] ?? ''));
            if ($status !== '' && $status !== Review::STATUS_APPROVED) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array<string, mixed>> $ratingOptionMap
     * @return array<string, mixed>
     */
    private function mapReviewItem(array $row, array $ratingOptionMap): array
    {
        $reviewId = (int) ($row[Review::schema_fields_ID] ?? $row['id'] ?? 0);
        $rating = max(0.0, min(5.0, (float) ($row[Review::schema_fields_RATING] ?? $row['rating'] ?? 0)));
        if ($reviewId <= 0 || $rating <= 0.0) {
            return [];
        }

        return [
            'id' => $reviewId,
            'author_name' => $this->normalizeAuthorName($row),
            'rating' => $rating,
            'title' => $this->normalizeText((string) ($row[Review::schema_fields_TITLE] ?? $row['title'] ?? ''), self::TITLE_MAX_LENGTH),
            'body' => $this->normalizeText((string) ($row[Review::schema_fields_CONTENT] ?? $row['content'] ?? ''), self::BODY_MAX_LENGTH),
            'created_at' => $this->normalizeDate((string) ($row[Review::schema_fields_CREATED_AT] ?? $row['created_at'] ?? '')),
            'url_fragment' => '#review-' . $reviewId,
            'media' => $this->normalizeMedia($this->reviewService->decodeMediaItems($row[Review::schema_fields_MEDIA_ITEMS] ?? $row['media_items'] ?? '')),
            'rating_scores' => $this->normalizeRatingScores(
                $this->reviewService->decodeRatingScores($row[Review::schema_fields_RATING_SCORES] ?? $row['rating_scores'] ?? ''),
                $ratingOptionMap
            ),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function normalizeAuthorName(array $row): string
    {
        foreach (['customer_name', 'author_name', 'nickname', 'name'] as $key) {
            $name = $this->normalizeText((string) ($row[$key] ?? ''), self::AUTHOR_MAX_LENGTH);
            if ($name !== '' && strtolower($name) !== 'anonymous') {
                return $name;
            }
        }

        return (string) __('匿名用户');
    }

    private function normalizeText(string $text, int $maxLength): string
    {
        $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return mb_substr($text, 0, max(0, $maxLength));
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);
        return $timestamp ? date('c', $timestamp) : '';
    }

    /**
     * @param array<int, array<string, mixed>> $mediaItems
     * @return array<int, array{type: string, url: string, label: string}>
     */
    private function normalizeMedia(array $mediaItems): array
    {
        $media = [];
        foreach ($mediaItems as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = strtolower(trim((string) ($item['type'] ?? '')));
            $url = trim((string) ($item['url'] ?? ''));
            if (!in_array($type, ['image', 'video'], true) || !$this->isPublicMediaUrl($url)) {
                continue;
            }

            $media[] = [
                'type' => $type,
                'url' => mb_substr($url, 0, 1000),
                'label' => $this->normalizeText((string) ($item['label'] ?? ''), self::TITLE_MAX_LENGTH),
            ];
        }

        return $media;
    }

    private function isPublicMediaUrl(string $url): bool
    {
        return $url !== ''
            && ((str_starts_with($url, '/') && !str_starts_with($url, '//'))
                || (bool) preg_match('/^https?:\/\//i', $url));
    }

    /**
     * @param array<string, int> $ratingScores
     * @param array<string, array<string, mixed>> $ratingOptionMap
     * @return array<int, array{code: string, name: string, value: int}>
     */
    private function normalizeRatingScores(array $ratingScores, array $ratingOptionMap): array
    {
        $scores = [];
        foreach ($ratingScores as $code => $score) {
            $code = trim((string) $code);
            if ($code === '' || !isset($ratingOptionMap[$code])) {
                continue;
            }

            $value = max(1, min(5, (int) $score));
            $label = trim((string) ($ratingOptionMap[$code]['label'] ?? $code));
            $scores[] = [
                'code' => $code,
                'name' => (string) __($label !== '' ? $label : $code),
                'value' => $value,
            ];
        }

        return $scores;
    }

    private function getRatingOptionService(): ReviewRatingOptionService
    {
        return $this->ratingOptionService ?? ObjectManager::getInstance(ReviewRatingOptionService::class);
    }
}
