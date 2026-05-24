<?php

declare(strict_types=1);

namespace WeShop\Review\Extends\Module\Weline_Seo\StructuredDataProvider;

use Weline\Seo\Interface\StructuredDataProviderInterface;

class ReviewStructuredDataProvider implements StructuredDataProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function provideStructuredData($template, array $context): array
    {
        if (($context['page_type'] ?? '') !== 'product') {
            return [];
        }

        $reviewSeo = is_array($context['review_seo'] ?? null) ? $context['review_seo'] : [];
        $items = is_array($reviewSeo['items'] ?? null) ? $reviewSeo['items'] : [];
        if ($items === []) {
            return [];
        }

        $pageUrl = trim((string) ($context['canonical_url'] ?? $context['url'] ?? ''));
        if ($pageUrl === '') {
            return [];
        }

        $aggregate = is_array($reviewSeo['aggregate'] ?? null) ? $reviewSeo['aggregate'] : [];
        $bestRating = (int) ($aggregate['best_rating'] ?? 5);
        $worstRating = (int) ($aggregate['worst_rating'] ?? 1);

        $nodes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $node = $this->buildReviewNode($item, $pageUrl, $bestRating, $worstRating);
            if ($node !== []) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function buildReviewNode(array $item, string $pageUrl, int $bestRating, int $worstRating): array
    {
        $reviewId = (int) ($item['id'] ?? 0);
        $rating = (float) ($item['rating'] ?? 0);
        if ($reviewId <= 0 || $rating <= 0.0) {
            return [];
        }

        $node = [
            '@type' => 'Review',
            '@id' => $pageUrl . '#review-' . $reviewId,
            'itemReviewed' => ['@id' => $pageUrl . '#product'],
            'author' => [
                '@type' => 'Person',
                'name' => (string) ($item['author_name'] ?? ''),
            ],
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => (string) $rating,
                'bestRating' => (string) max(1, $bestRating),
                'worstRating' => (string) max(1, $worstRating),
            ],
        ];

        $title = trim((string) ($item['title'] ?? ''));
        if ($title !== '') {
            $node['name'] = $title;
        }

        $body = trim((string) ($item['body'] ?? ''));
        if ($body !== '') {
            $node['reviewBody'] = $body;
        }

        $datePublished = trim((string) ($item['created_at'] ?? ''));
        if ($datePublished !== '') {
            $node['datePublished'] = $datePublished;
        }

        $media = is_array($item['media'] ?? null) ? $item['media'] : [];
        $images = $this->collectMediaUrls($media, 'image', $pageUrl);
        if ($images !== []) {
            $node['image'] = $images;
        }

        $videos = $this->collectVideoObjects($media, $pageUrl, $title);
        if ($videos !== []) {
            $node['video'] = $videos;
        }

        $properties = $this->buildAdditionalProperties(is_array($item['rating_scores'] ?? null) ? $item['rating_scores'] : []);
        if ($properties !== []) {
            $node['additionalProperty'] = $properties;
        }

        return $node;
    }

    /**
     * @param array<int, mixed> $media
     * @return array<int, string>
     */
    private function collectMediaUrls(array $media, string $type, string $pageUrl): array
    {
        $urls = [];
        foreach ($media as $item) {
            if (!is_array($item) || (string) ($item['type'] ?? '') !== $type) {
                continue;
            }

            $url = $this->absoluteUrl((string) ($item['url'] ?? ''), $pageUrl);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @param array<int, mixed> $media
     * @return array<int, array<string, mixed>>
     */
    private function collectVideoObjects(array $media, string $pageUrl, string $reviewTitle): array
    {
        $videos = [];
        foreach ($media as $item) {
            if (!is_array($item) || (string) ($item['type'] ?? '') !== 'video') {
                continue;
            }

            $url = $this->absoluteUrl((string) ($item['url'] ?? ''), $pageUrl);
            if ($url === '') {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $video = [
                '@type' => 'VideoObject',
                'contentUrl' => $url,
                'name' => $label !== '' ? $label : ($reviewTitle !== '' ? $reviewTitle : (string) __('评论视频')),
            ];
            $videos[] = $video;
        }

        return $videos;
    }

    /**
     * @param array<int, mixed> $ratingScores
     * @return array<int, array<string, mixed>>
     */
    private function buildAdditionalProperties(array $ratingScores): array
    {
        $properties = [];
        foreach ($ratingScores as $score) {
            if (!is_array($score)) {
                continue;
            }

            $name = trim((string) ($score['name'] ?? ''));
            $code = trim((string) ($score['code'] ?? ''));
            $value = (int) ($score['value'] ?? 0);
            if ($name === '' || $value <= 0) {
                continue;
            }

            $property = [
                '@type' => 'PropertyValue',
                'name' => $name,
                'value' => (string) $value,
            ];
            if ($code !== '') {
                $property['propertyID'] = $code;
            }

            $properties[] = $property;
        }

        return $properties;
    }

    private function absoluteUrl(string $url, string $pageUrl): string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '//')) {
            return '';
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        if (!str_starts_with($url, '/')) {
            return '';
        }

        $origin = $this->origin($pageUrl);
        return $origin !== '' ? rtrim($origin, '/') . $url : '';
    }

    private function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }
}
