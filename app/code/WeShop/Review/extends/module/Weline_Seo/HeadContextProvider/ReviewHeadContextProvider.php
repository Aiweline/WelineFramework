<?php

declare(strict_types=1);

namespace WeShop\Review\Extends\Module\Weline_Seo\HeadContextProvider;

use WeShop\Review\Service\ReviewSeoDataService;
use Weline\Framework\Http\Url;
use Weline\Seo\Interface\HeadContextProviderInterface;

class ReviewHeadContextProvider implements HeadContextProviderInterface
{
    private const REVIEW_ITEM_LIMIT = 5;

    public function __construct(
        private readonly ReviewSeoDataService $reviewSeoDataService,
        private readonly ?Url $url = null
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $context): array
    {
        $productId = $this->resolveProductId($template, $context);
        if ($this->isReviewListingPage($context)) {
            return $this->applyReviewListingPolicy($template, $context, $productId);
        }

        if (($context['page_type'] ?? '') !== 'product' || $productId <= 0) {
            return $context;
        }

        try {
            $reviewSeo = $this->reviewSeoDataService->getProductReviewSeo(
                $productId,
                (string) ($context['locale'] ?? ''),
                self::REVIEW_ITEM_LIMIT
            );
        } catch (\Throwable) {
            return $context;
        }

        $aggregate = is_array($reviewSeo['aggregate'] ?? null) ? $reviewSeo['aggregate'] : [];
        $reviewCount = (int) ($aggregate['review_count'] ?? 0);
        $ratingValue = (float) ($aggregate['rating_value'] ?? 0);
        if ($reviewCount <= 0 || $ratingValue <= 0.0) {
            return $context;
        }

        $product = $this->normalizeProduct($context['product'] ?? []);
        $product['rating'] = $ratingValue;
        $product['review_count'] = $reviewCount;

        $context['product'] = $product;
        $context['review_seo'] = $reviewSeo;

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function applyReviewListingPolicy($template, array $context, int $productId): array
    {
        $context['robots'] = 'noindex,follow';
        unset($context['review_seo']);

        if ($productId > 0) {
            $productUrl = $this->buildProductUrl($template, $productId, (string) ($context['url'] ?? $context['canonical_url'] ?? ''));
            if ($productUrl !== '') {
                $context['canonical_url'] = $productUrl;
            }
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function isReviewListingPage(array $context): bool
    {
        $url = (string) ($context['url'] ?? $context['canonical_url'] ?? '');
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));

        return $path !== '' && (
            $path === '/review'
            || str_contains($path, '/review/')
            || str_ends_with($path, '/review')
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveProductId($template, array $context): int
    {
        $productId = (int) $this->readValue($context['product'] ?? null, ['product_id', 'id', 'entity_id']);
        if ($productId > 0) {
            return $productId;
        }

        if (is_object($template) && method_exists($template, 'getData')) {
            try {
                $productId = (int) ($template->getData('product_id') ?? 0);
            } catch (\Throwable) {
                $productId = 0;
            }
        }

        return $productId;
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

    private function buildProductUrl($template, int $productId, string $currentUrl): string
    {
        $url = '';
        if (is_object($template) && method_exists($template, 'getUrl')) {
            try {
                $url = (string) $template->getUrl('product/view', ['id' => $productId]);
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

    private function absoluteUrl(string $url, string $currentUrl): string
    {
        if ($url === '' || preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $origin = $this->origin($currentUrl);
        if ($origin === '') {
            return $url;
        }

        return rtrim($origin, '/') . '/' . ltrim($url, '/');
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
