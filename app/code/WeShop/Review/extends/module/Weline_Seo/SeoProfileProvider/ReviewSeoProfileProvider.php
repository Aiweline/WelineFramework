<?php

declare(strict_types=1);

namespace WeShop\Review\Extends\Module\Weline_Seo\SeoProfileProvider;

use WeShop\Review\Service\ReviewSeoDataService;
use Weline\Framework\Http\Url;
use Weline\Seo\Interface\SeoProfileProviderInterface;

class ReviewSeoProfileProvider implements SeoProfileProviderInterface
{
    private const REVIEW_ITEM_LIMIT = 5;

    public function __construct(
        private readonly ?ReviewSeoDataService $reviewSeoDataService = null,
        private readonly ?Url $url = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provideSeoProfile($template, array $context): array
    {
        $productId = $this->resolveProductId($template, $context);
        if ($this->isReviewListingPage($context)) {
            return $this->reviewListingProfile($template, $context, $productId);
        }

        if (($context['page_type'] ?? '') === 'product' && $productId > 0) {
            return $this->productReviewProfile($template, $context, $productId);
        }

        $productReviews = $this->productReviews($context);
        if ($productReviews !== []) {
            return [
                'schema_nodes' => $productReviews,
            ];
        }
        if (($context['page_type'] ?? '') === 'product') {
            return [];
        }

        $reviews = $this->reviews($this->readTemplate($template, 'reviews') ?? $context['reviews'] ?? [], $context);
        if ($reviews === []) {
            return [];
        }

        return [
            'page_type' => 'review_page',
            'schema_nodes' => $reviews,
            'geo' => [
                'type' => 'review',
                'include' => true,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function productReviewProfile($template, array $context, int $productId): array
    {
        $reviewSeo = is_array($context['review_seo'] ?? null) ? $context['review_seo'] : [];
        if ($reviewSeo === [] && $this->reviewSeoDataService) {
            try {
                $reviewSeo = $this->reviewSeoDataService->getProductReviewSeo(
                    $productId,
                    (string)($context['locale'] ?? ''),
                    self::REVIEW_ITEM_LIMIT
                );
            } catch (\Throwable) {
                $reviewSeo = [];
            }
        }

        $aggregate = is_array($reviewSeo['aggregate'] ?? null) ? $reviewSeo['aggregate'] : [];
        $reviewCount = (int)($aggregate['review_count'] ?? 0);
        $ratingValue = (float)($aggregate['rating_value'] ?? 0);
        if ($reviewCount <= 0 || $ratingValue <= 0.0) {
            return [];
        }

        $product = $this->normalizeProduct($context['product'] ?? []);
        $product['rating'] = $ratingValue;
        $product['review_count'] = $reviewCount;

        $reviewContext = array_replace($context, [
            'product' => $product,
            'review_seo' => $reviewSeo,
        ]);
        $nodes = $this->productReviews($reviewContext);

        $profile = [
            'product' => $product,
            'review_seo' => $reviewSeo,
        ];
        if ($nodes !== []) {
            $profile['schema_nodes'] = $nodes;
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function reviewListingProfile($template, array $context, int $productId): array
    {
        $profile = [
            'robots' => 'noindex,follow',
            'review_seo' => null,
            'sitemap' => ['include' => false],
            'geo' => ['include' => false],
        ];

        if ($productId > 0) {
            $productUrl = $this->buildProductUrl($template, $productId, (string)($context['url'] ?? $context['canonical_url'] ?? ''));
            if ($productUrl !== '') {
                $profile['canonical_url'] = $productUrl;
            }
        }

        return $profile;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function productReviews(array $context): array
    {
        if (($context['page_type'] ?? '') !== 'product') {
            return [];
        }

        $reviewSeo = is_array($context['review_seo'] ?? null) ? $context['review_seo'] : [];
        $items = is_array($reviewSeo['items'] ?? null) ? $reviewSeo['items'] : [];
        if ($items === []) {
            return [];
        }

        $pageUrl = trim((string)($context['canonical_url'] ?? $context['url'] ?? ''));
        if ($pageUrl === '') {
            return [];
        }

        $aggregate = is_array($reviewSeo['aggregate'] ?? null) ? $reviewSeo['aggregate'] : [];
        $bestRating = (int)($aggregate['best_rating'] ?? 5);
        $worstRating = (int)($aggregate['worst_rating'] ?? 1);

        $nodes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $node = $this->productReviewNode($item, $pageUrl, $bestRating, $worstRating);
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
    private function productReviewNode(array $item, string $pageUrl, int $bestRating, int $worstRating): array
    {
        $reviewId = (int)($item['id'] ?? 0);
        $rating = (float)($item['rating'] ?? 0);
        if ($reviewId <= 0 || $rating <= 0.0) {
            return [];
        }

        $node = [
            '@type' => 'Review',
            '@id' => $pageUrl . '#review-' . $reviewId,
            'itemReviewed' => ['@id' => $pageUrl . '#product'],
            'author' => [
                '@type' => 'Person',
                'name' => (string)($item['author_name'] ?? ''),
            ],
            'reviewRating' => [
                '@type' => 'Rating',
                'ratingValue' => (string)$rating,
                'bestRating' => (string)max(1, $bestRating),
                'worstRating' => (string)max(1, $worstRating),
            ],
        ];

        $title = trim((string)($item['title'] ?? ''));
        if ($title !== '') {
            $node['name'] = $title;
        }

        $body = trim((string)($item['body'] ?? ''));
        if ($body !== '') {
            $node['reviewBody'] = $body;
        }

        $datePublished = trim((string)($item['created_at'] ?? ''));
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

        $properties = $this->additionalProperties(is_array($item['rating_scores'] ?? null) ? $item['rating_scores'] : []);
        if ($properties !== []) {
            $node['additionalProperty'] = $properties;
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    private function reviews(mixed $items, array $context): array
    {
        if (!is_array($items)) {
            return [];
        }

        $product = is_array($context['product'] ?? null) ? $context['product'] : [];
        $productName = trim((string)($product['name'] ?? $product['title'] ?? $context['title'] ?? ''));
        $nodes = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $body = trim((string)($item['content'] ?? $item['body'] ?? ''));
            $rating = $item['rating'] ?? $item['rating_value'] ?? null;
            if ($body === '' && !is_numeric($rating)) {
                continue;
            }
            $node = [
                '@type' => 'Review',
                'reviewBody' => $body,
                'author' => [
                    '@type' => 'Person',
                    'name' => trim((string)($item['customer_name'] ?? $item['author'] ?? __('Anonymous'))),
                ],
            ];
            if ($productName !== '') {
                $node['itemReviewed'] = [
                    '@type' => 'Product',
                    'name' => $productName,
                ];
            }
            if (is_numeric($rating)) {
                $node['reviewRating'] = [
                    '@type' => 'Rating',
                    'ratingValue' => (string)$rating,
                    'bestRating' => '5',
                    'worstRating' => '1',
                ];
            }
            $date = trim((string)($item['created_at'] ?? $item['datePublished'] ?? ''));
            if ($date !== '') {
                $node['datePublished'] = $this->formatDate($date);
            }
            $nodes[] = $node;
        }

        return $nodes;
    }

    private function readTemplate($template, string $key): mixed
    {
        return is_object($template) && method_exists($template, 'getData') ? $template->getData($key) : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveProductId($template, array $context): int
    {
        $productId = (int)$this->readValue($context['product'] ?? null, ['product_id', 'id', 'entity_id']);
        if ($productId > 0) {
            return $productId;
        }

        if (is_object($template) && method_exists($template, 'getData')) {
            try {
                return (int)($template->getData('product_id') ?? 0);
            } catch (\Throwable) {
            }
        }

        return 0;
    }

    /**
     * @param mixed $product
     * @return array<string, mixed>
     */
    private function normalizeProduct(mixed $product): array
    {
        if (is_array($product)) {
            return $product;
        }

        if (is_object($product) && method_exists($product, 'getData')) {
            try {
                $data = $product->getData();
                if (is_array($data)) {
                    return $data;
                }
            } catch (\Throwable) {
            }
        }

        return [];
    }

    /**
     * @param string[] $keys
     */
    private function readValue(mixed $source, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }

            if (!is_object($source)) {
                continue;
            }

            if (method_exists($source, 'getData')) {
                try {
                    $value = $source->getData($key);
                    if ($value !== null && $value !== '') {
                        return $value;
                    }
                } catch (\Throwable) {
                }
            }

            $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
            if (method_exists($source, $method)) {
                try {
                    return $source->{$method}();
                } catch (\Throwable) {
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isReviewListingPage(array $context): bool
    {
        $url = (string)($context['url'] ?? $context['canonical_url'] ?? '');
        $path = strtolower((string)(parse_url($url, PHP_URL_PATH) ?: ''));

        return $path !== '' && (
            $path === '/review'
            || str_contains($path, '/review/')
            || str_ends_with($path, '/review')
        );
    }

    private function buildProductUrl($template, int $productId, string $currentUrl): string
    {
        $url = '';
        if (is_object($template) && method_exists($template, 'getUrl')) {
            try {
                $url = (string)$template->getUrl('product/view', ['id' => $productId]);
            } catch (\Throwable) {
                $url = '';
            }
        }

        if ($url === '' && $this->url) {
            try {
                $url = $this->url->getUrl('product/view', ['id' => $productId]);
            } catch (\Throwable) {
                $url = '';
            }
        }

        if ($url === '') {
            $url = '/product/view?id=' . $productId;
        }

        return $this->absoluteUrl($url, $currentUrl);
    }

    /**
     * @param array<int, mixed> $media
     * @return array<int, string>
     */
    private function collectMediaUrls(array $media, string $type, string $pageUrl): array
    {
        $urls = [];
        foreach ($media as $item) {
            if (!is_array($item) || (string)($item['type'] ?? '') !== $type) {
                continue;
            }

            $url = $this->absoluteUrl((string)($item['url'] ?? ''), $pageUrl);
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
            if (!is_array($item) || (string)($item['type'] ?? '') !== 'video') {
                continue;
            }

            $url = $this->absoluteUrl((string)($item['url'] ?? ''), $pageUrl);
            if ($url === '') {
                continue;
            }

            $label = trim((string)($item['label'] ?? ''));
            $videos[] = [
                '@type' => 'VideoObject',
                'contentUrl' => $url,
                'name' => $label !== '' ? $label : ($reviewTitle !== '' ? $reviewTitle : (string)__('Review video')),
            ];
        }

        return $videos;
    }

    /**
     * @param array<int, mixed> $ratingScores
     * @return array<int, array<string, mixed>>
     */
    private function additionalProperties(array $ratingScores): array
    {
        $properties = [];
        foreach ($ratingScores as $score) {
            if (!is_array($score)) {
                continue;
            }

            $name = trim((string)($score['name'] ?? ''));
            $code = trim((string)($score['code'] ?? ''));
            $value = (int)($score['value'] ?? 0);
            if ($name === '' || $value <= 0) {
                continue;
            }

            $property = [
                '@type' => 'PropertyValue',
                'name' => $name,
                'value' => (string)$value,
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

    private function formatDate(string $date): string
    {
        $time = strtotime($date);
        return date('c', $time ?: time());
    }
}
