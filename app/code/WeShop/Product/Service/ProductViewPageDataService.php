<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\QA\Service\QAService;
use WeShop\Review\Service\ReviewService;

class ProductViewPageDataService
{
    private const REVIEW_PAGE_SIZE = 5;

    public function __construct(
        private readonly ProductService $productService,
        private readonly PriceService $priceService,
        private readonly ProductEavService $productEavService,
        private readonly ProductRecommendationService $productRecommendationService,
        private readonly ReviewService $reviewService,
        private readonly QAService $qaService
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function build(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        $product = $this->productService->getProduct($productId);
        if (!$product || !$product->getId() || !$this->isEnabled($product)) {
            return null;
        }

        $attributes = $this->getAttributes($productId);
        $reviewsPayload = $this->getReviews($productId);
        $questions = $this->getQuestions($productId);
        $productData = $this->mapProduct($product, $attributes, $reviewsPayload);

        return [
            'product' => $productData,
            'product_images' => $this->buildProductImages($productData['images'], $productData['name']),
            'attributes' => $attributes,
            'related_products' => $this->productRecommendationService->getRecommendations([$productId], 4),
            'reviews' => $reviewsPayload['items'],
            'qa' => $questions,
            'breadcrumbs' => $this->buildBreadcrumbs($product),
            'title' => $productData['name'],
            'meta_title' => (string) ($product->getData(Product::schema_fields_meta_name) ?: $productData['name']),
            'meta_description' => (string) ($product->getData(Product::schema_fields_meta_description) ?: $productData['short_description']),
            'meta_keywords' => (string) ($product->getData(Product::schema_fields_meta_keywords) ?? ''),
        ];
    }

    protected function isEnabled(Product $product): bool
    {
        $status = $product->getData(Product::schema_fields_status);

        return $status === 1 || $status === '1' || $status === 'enabled';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getAttributes(int $productId): array
    {
        try {
            $attributes = $this->productEavService->getProductAttributesViewModel($productId);
            return is_array($attributes) ? $attributes : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, total: int, average: float}
     */
    protected function getReviews(int $productId): array
    {
        try {
            $reviews = $this->reviewService->getProductReviews($productId, 1, self::REVIEW_PAGE_SIZE);
            $items = is_array($reviews['items'] ?? null) ? $reviews['items'] : [];
            $total = (int) ($reviews['total'] ?? count($items));

            return [
                'items' => $items,
                'total' => $total,
                'average' => $this->reviewService->getAverageRating($productId),
            ];
        } catch (\Throwable) {
            return [
                'items' => [],
                'total' => 0,
                'average' => 0.0,
            ];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function getQuestions(int $productId): array
    {
        try {
            $questions = $this->qaService->getProductQuestions($productId);
            return is_array($questions) ? $questions : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     * @param array{items: array<int, array<string, mixed>>, total: int, average: float} $reviewsPayload
     * @return array<string, mixed>
     */
    protected function mapProduct(Product $product, array $attributes, array $reviewsPayload): array
    {
        $images = $this->extractImages($product);
        $ratingDistribution = $this->buildRatingDistribution($reviewsPayload['items']);
        $priceData = $this->priceService->resolveProduct($product);

        return [
            'product_id' => (int) $product->getId(),
            'name' => (string) ($product->getData(Product::schema_fields_name) ?? ''),
            'short_description' => (string) ($product->getData(Product::schema_fields_short_description) ?? ''),
            'description' => (string) ($product->getData(Product::schema_fields_description) ?? ''),
            'description_title' => (string) __('Product Description'),
            'price' => (float) ($priceData['price'] ?? 0),
            'original_price' => (float) ($priceData['original_price'] ?? 0),
            'special_price' => $this->normalizeNullableFloat($priceData['special_price'] ?? null),
            'has_discount' => (bool) ($priceData['has_discount'] ?? false),
            'discount_amount' => (float) ($priceData['discount_amount'] ?? 0),
            'discount_percent' => (int) ($priceData['discount_percent'] ?? 0),
            'cost' => (float) ($product->getData(Product::schema_fields_cost) ?? 0),
            'sku' => (string) ($product->getData(Product::schema_fields_sku) ?? ''),
            'stock' => (int) ($product->getData(Product::schema_fields_stock) ?? 0),
            'weight' => (float) ($product->getData(Product::schema_fields_weight) ?? 0),
            'image' => (string) ($product->getData(Product::schema_fields_image) ?? ''),
            'main_image' => $images[0] ?? '',
            'images' => $images,
            'brand' => (string) ($product->getData('brand') ?? ''),
            'brand_id' => (int) ($product->getData('brand_id') ?? 0),
            'options' => $this->normalizeArrayField($product->getData('options')),
            'highlights' => $this->normalizeStringList($product->getData('highlights')),
            'specifications' => $this->flattenSpecifications($attributes),
            'in_stock' => (int) ($product->getData(Product::schema_fields_stock) ?? 0) > 0,
            'stock_status' => (int) ($product->getData(Product::schema_fields_stock) ?? 0) > 0 ? 'in_stock' : 'out_of_stock',
            'rating' => (float) $reviewsPayload['average'],
            'review_count' => (int) $reviewsPayload['total'],
            'rating_distribution' => $ratingDistribution,
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function extractImages(Product $product): array
    {
        $images = [];

        $primaryImage = (string) ($product->getData(Product::schema_fields_image) ?? '');
        if ($primaryImage !== '') {
            $images[] = $primaryImage;
        }

        $additionalImages = $this->normalizeArrayField($product->getData(Product::schema_fields_images));
        foreach ($additionalImages as $image) {
            if (is_string($image) && $image !== '') {
                $images[] = $image;
            }
        }

        return array_values(array_unique($images));
    }

    /**
     * @param array<int, string> $images
     * @return array<int, array<string, string>>
     */
    protected function buildProductImages(array $images, string $productName): array
    {
        $productImages = [];
        foreach ($images as $index => $image) {
            $productImages[] = [
                'url' => $image,
                'alt' => trim($productName . ' ' . (string) ($index + 1)),
            ];
        }

        return $productImages;
    }

    /**
     * @param array<int, array<string, mixed>> $reviews
     * @return array<int, int>
     */
    protected function buildRatingDistribution(array $reviews): array
    {
        $distribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $count = count($reviews);
        if ($count === 0) {
            return $distribution;
        }

        foreach ($reviews as $review) {
            $rating = max(1, min(5, (int) round((float) ($review['rating'] ?? 0))));
            ++$distribution[$rating];
        }

        foreach ($distribution as $rating => $ratingCount) {
            $distribution[$rating] = (int) round(($ratingCount / $count) * 100);
        }

        return $distribution;
    }

    /**
     * @param array<int, array<string, mixed>> $attributes
     * @return array<int, array<string, string>>
     */
    protected function flattenSpecifications(array $attributes): array
    {
        $specifications = [];
        foreach ($attributes as $group) {
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            foreach ($items as $item) {
                $label = trim((string) ($item['label'] ?? ''));
                $value = trim((string) ($item['value'] ?? ''));
                if ($label === '' || $value === '') {
                    continue;
                }

                $specifications[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        return $specifications;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildBreadcrumbs(Product $product): array
    {
        try {
            $categories = $product->getCategoriesWithLocale();
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($categories)) {
            return [];
        }

        $breadcrumbs = [];
        foreach ($categories as $category) {
            if (!is_array($category)) {
                continue;
            }

            $categoryId = (int) ($category['category_id'] ?? $category['id'] ?? 0);
            $name = $this->firstString($category, [
                'name',
                'category_name',
                'category.name',
                'category_local.name',
            ]);

            if ($name === '') {
                continue;
            }

            $breadcrumbs[] = [
                'category_id' => $categoryId,
                'name' => $name,
                'url' => $categoryId > 0 ? 'catalog/category/view?id=' . $categoryId : '',
            ];
        }

        return $breadcrumbs;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, string> $keys
     */
    protected function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }

            if (is_array($value)) {
                $nested = $this->firstString($value, ['name']);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    /**
     * @return array<int, mixed>
     */
    protected function normalizeArrayField(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeStringList(mixed $value): array
    {
        $values = $this->normalizeArrayField($value);
        $result = [];
        foreach ($values as $item) {
            if (is_string($item) && trim($item) !== '') {
                $result[] = trim($item);
            }
        }

        return $result;
    }

    protected function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
