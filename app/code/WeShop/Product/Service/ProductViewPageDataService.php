<?php

declare(strict_types=1);

namespace WeShop\Product\Service;

use WeShop\Price\Service\PriceService;
use WeShop\Product\Model\Product;
use WeShop\QA\Service\QAService;
use WeShop\Review\Service\ReviewService;
use Weline\Framework\Manager\ObjectManager;

class ProductViewPageDataService
{
    private const REVIEW_PAGE_SIZE = 5;

    private ?ConfigurableProductService $configurableProductService;

    public function __construct(
        private readonly ProductService $productService,
        private readonly PriceService $priceService,
        private readonly ProductEavService $productEavService,
        private readonly ProductRecommendationService $productRecommendationService,
        private readonly ReviewService $reviewService,
        private readonly QAService $qaService,
        mixed $configurableProductService = null
    ) {
        $this->configurableProductService = $configurableProductService instanceof ConfigurableProductService
            ? $configurableProductService
            : null;
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
        self::cooperativeBuildYield();
        if (!$product || !$product->getId() || !$this->isEnabled($product)) {
            return null;
        }
        $product = clone $product;

        $attributes = $this->getAttributes($productId);
        self::cooperativeBuildYield();
        $reviewsPayload = $this->getReviews($productId);
        self::cooperativeBuildYield();
        $questions = $this->getQuestions($productId);
        self::cooperativeBuildYield();
        $productData = $this->mapProduct($product, $attributes, $reviewsPayload);
        self::cooperativeBuildYield();
        $configurableOptions = $this->getConfigurableOptions($productId);
        if (($configurableOptions['attributes'] ?? []) === []) {
            self::cooperativeBuildYield();
            $configurableOptions = $this->buildConfigurableOptionsFromChildren($product);
        }
        if (($configurableOptions['attributes'] ?? []) === []) {
            self::cooperativeBuildYield();
            $configurableOptions = $this->buildFallbackPurchasableOptions($product);
        }
        if (($configurableOptions['attributes'] ?? []) !== []) {
            $productData['configurable_options'] = $configurableOptions;
            $productData['is_configurable'] = true;
        }

        return [
            'product' => $productData,
            'product_images' => $this->buildProductImages($productData['images'], $productData['name']),
            'attributes' => $attributes,
            'configurable_options' => $configurableOptions,
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

    private static function cooperativeBuildYield(): void
    {
        if (!\class_exists(\Weline\Framework\Runtime\Runtime::class, false)
            || !\Weline\Framework\Runtime\Runtime::isPersistent()
            || !\Weline\Framework\Runtime\SchedulerSystem::isSchedulerActive()
            || !\Fiber::getCurrent()) {
            return;
        }

        static $fiberYieldAt = null;
        $fiber = \Fiber::getCurrent();
        if (!$fiber instanceof \Fiber) {
            return;
        }
        if (!$fiberYieldAt instanceof \WeakMap) {
            $fiberYieldAt = new \WeakMap();
        }

        $now = \microtime(true);
        $lastYieldAt = (float)($fiberYieldAt[$fiber] ?? 0.0);
        if ($lastYieldAt <= 0.0) {
            $fiberYieldAt[$fiber] = $now;
            return;
        }
        if (($now - $lastYieldAt) < 0.01) {
            return;
        }

        $fiberYieldAt[$fiber] = $now;
        \Weline\Framework\Runtime\SchedulerSystem::yield();
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
        $attributeValueMap = $this->buildAttributeValueMap($attributes);

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
            'brand' => (string) ($attributeValueMap['brand'] ?? $product->getData('brand') ?? ''),
            'brand_id' => (int) ($product->getData('brand_id') ?? 0),
            'options' => $this->normalizeArrayField($product->getData('options')),
            'configurable_options' => [],
            'is_configurable' => false,
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
     * @param array<int, array<string, mixed>> $attributes
     * @return array<string, string>
     */
    protected function buildAttributeValueMap(array $attributes): array
    {
        $map = [];
        foreach ($attributes as $group) {
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            foreach ($items as $item) {
                $code = trim((string)($item['code'] ?? ''));
                $value = trim((string)($item['value'] ?? ''));
                if ($code !== '' && $value !== '') {
                    $map[$code] = $value;
                }
            }
        }

        return $map;
    }

    /**
     * @return array{attributes: array<int, array<string, mixed>>, variants: array<int, array<string, mixed>>}
     */
    protected function getConfigurableOptions(int $productId): array
    {
        try {
            $service = $this->configurableProductService ?? ObjectManager::getInstance(ConfigurableProductService::class);
            $options = $service->getConfigurableOptions($productId);
            return [
                'attributes' => is_array($options['attributes'] ?? null) ? $options['attributes'] : [],
                'variants' => is_array($options['variants'] ?? null) ? $options['variants'] : [],
            ];
        } catch (\Throwable) {
            return ['attributes' => [], 'variants' => []];
        }
    }

    /**
     * @return array{attributes: array<int, array<string, mixed>>, variants: array<int, array<string, mixed>>}
     */
    protected function buildConfigurableOptionsFromChildren(Product $product): array
    {
        $productId = (int)$product->getId();
        if ($productId <= 0) {
            return ['attributes' => [], 'variants' => []];
        }

        try {
            $pdo = $product->getConnection()->getConnector()->getLink();
            $childrenStmt = $pdo->prepare(
                'SELECT product_id, sku, name, price, stock, image FROM "m_weshop_product" WHERE parent_id = ? AND status = 1 ORDER BY product_id ASC'
            );
            $childrenStmt->execute([$productId]);
            $children = $childrenStmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($children === []) {
                return ['attributes' => [], 'variants' => []];
            }

            $childIds = array_map(static fn(array $row): int => (int)$row['product_id'], $children);
            $placeholders = implode(',', array_fill(0, count($childIds), '?'));
            $optionStmt = $pdo->prepare(
                'SELECT po.product_id, po.attribute_id, po.option_id, a.code AS attribute_code, a.name AS attribute_name, '
                . 'o.code AS option_code, o.value AS option_value, o.swatch_color, o.swatch_image, o.swatch_text '
                . 'FROM "m_weshop_product_option_id" po '
                . 'JOIN "m_eav_attribute" a ON a.attribute_id = po.attribute_id '
                . 'JOIN "m_eav_attribute_option" o ON o.option_id = po.option_id '
                . "WHERE po.product_id IN ({$placeholders}) ORDER BY a.attribute_id, o.option_id"
            );
            $optionStmt->execute($childIds);
            $optionRows = $optionStmt->fetchAll(\PDO::FETCH_ASSOC);
            if ($optionRows === []) {
                return ['attributes' => [], 'variants' => []];
            }

            $childrenById = [];
            foreach ($children as $child) {
                $childrenById[(int)$child['product_id']] = $child;
            }

            $attributes = [];
            $variantOptionIds = [];
            foreach ($optionRows as $row) {
                $attributeId = (int)$row['attribute_id'];
                $optionId = (int)$row['option_id'];
                $childId = (int)$row['product_id'];
                $variantOptionIds[$childId][] = $optionId;
                $attributes[$attributeId] ??= [
                    'attribute_id' => $attributeId,
                    'code' => (string)$row['attribute_code'],
                    'name' => (string)$row['attribute_name'],
                    'origin_name' => (string)$row['attribute_name'],
                    'options' => [],
                ];
                $attributes[$attributeId]['options'][$optionId] ??= [
                    'option_id' => $optionId,
                    'code' => (string)$row['option_code'],
                    'value' => (string)$row['option_value'],
                    'origin_value' => (string)$row['option_value'],
                    'swatch_type' => $row['swatch_image'] !== '' ? 'image' : ($row['swatch_color'] !== '' ? 'color' : ($row['swatch_text'] !== '' ? 'text' : null)),
                    'swatch_value' => (string)($row['swatch_image'] ?: ($row['swatch_color'] ?: $row['swatch_text'])),
                    'option_image' => (string)($childrenById[$childId]['image'] ?? ''),
                    'available_product_ids' => [],
                ];
                $attributes[$attributeId]['options'][$optionId]['available_product_ids'][] = $childId;
            }

            $variants = [];
            foreach ($children as $child) {
                $childId = (int)$child['product_id'];
                $variants[] = [
                    'product_id' => $childId,
                    'sku' => (string)$child['sku'],
                    'name' => (string)$child['name'],
                    'price' => (float)$child['price'],
                    'stock' => (int)$child['stock'],
                    'image' => (string)$child['image'],
                    'option_ids' => $variantOptionIds[$childId] ?? [],
                ];
            }

            foreach ($attributes as &$attribute) {
                $attribute['options'] = array_values($attribute['options']);
            }
            unset($attribute);

            return ['attributes' => array_values($attributes), 'variants' => $variants];
        } catch (\Throwable) {
            return ['attributes' => [], 'variants' => []];
        }
    }

    /**
     * Demo/category sample products may not have generated child variants yet.
     * Keep the storefront purchasable by exposing EAV-like option selectors that
     * are submitted with the cart request but do not require variant resolution.
     *
     * @return array{attributes: array<int, array<string, mixed>>, variants: array<int, array<string, mixed>>}
     */
    protected function buildFallbackPurchasableOptions(Product $product): array
    {
        $sku = (string) ($product->getData(Product::schema_fields_sku) ?? '');
        if (!str_starts_with($sku, 'DEMO-CAT-')) {
            return ['attributes' => [], 'variants' => []];
        }

        return [
            'attributes' => [
                [
                    'attribute_id' => 900001,
                    'code' => 'color',
                    'name' => (string) __('颜色'),
                    'origin_name' => 'Color',
                    'options' => [
                        ['option_id' => 900101, 'code' => 'black', 'value' => (string) __('黑色'), 'origin_value' => 'Black', 'swatch_type' => 'color', 'swatch_value' => '#111827', 'available_product_ids' => [(int) $product->getId()]],
                        ['option_id' => 900102, 'code' => 'navy', 'value' => (string) __('藏青'), 'origin_value' => 'Navy', 'swatch_type' => 'color', 'swatch_value' => '#1e3a8a', 'available_product_ids' => [(int) $product->getId()]],
                        ['option_id' => 900103, 'code' => 'beige', 'value' => (string) __('米色'), 'origin_value' => 'Beige', 'swatch_type' => 'color', 'swatch_value' => '#d6b98c', 'available_product_ids' => [(int) $product->getId()]],
                    ],
                ],
                [
                    'attribute_id' => 900002,
                    'code' => 'size',
                    'name' => (string) __('尺码'),
                    'origin_name' => 'Size',
                    'options' => [
                        ['option_id' => 900201, 'code' => 'm', 'value' => 'M', 'origin_value' => 'M', 'swatch_type' => 'text', 'swatch_value' => 'M', 'available_product_ids' => [(int) $product->getId()]],
                        ['option_id' => 900202, 'code' => 'l', 'value' => 'L', 'origin_value' => 'L', 'swatch_type' => 'text', 'swatch_value' => 'L', 'available_product_ids' => [(int) $product->getId()]],
                        ['option_id' => 900203, 'code' => 'xl', 'value' => 'XL', 'origin_value' => 'XL', 'swatch_type' => 'text', 'swatch_value' => 'XL', 'available_product_ids' => [(int) $product->getId()]],
                    ],
                ],
            ],
            'variants' => [],
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
