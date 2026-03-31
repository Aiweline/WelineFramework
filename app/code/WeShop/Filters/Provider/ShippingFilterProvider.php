<?php

declare(strict_types=1);

namespace WeShop\Filters\Provider;

use WeShop\Product\Model\Product;
use Weline\Framework\Manager\ObjectManager;

class ShippingFilterProvider extends AbstractFilterProvider
{
    public const SHIPPING_FREE = 'free_shipping';
    public const SHIPPING_SAME_DAY = 'same_day';
    public const SHIPPING_NEXT_DAY = 'next_day';
    public const SHIPPING_EXPRESS = 'express';

    /**
     * @var string[]
     */
    private const FREE_SHIPPING_FIELDS = [
        'free_shipping',
        'is_free_shipping',
        'shipping_free',
    ];

    /**
     * @var string[]
     */
    private const SAME_DAY_FIELDS = [
        'same_day_delivery',
        'is_same_day_delivery',
        'supports_same_day_delivery',
    ];

    /**
     * @var string[]
     */
    private const NEXT_DAY_FIELDS = [
        'next_day_delivery',
        'is_next_day_delivery',
        'supports_next_day_delivery',
    ];

    /**
     * @var string[]
     */
    private const EXPRESS_FIELDS = [
        'express_delivery',
        'is_express_delivery',
        'supports_express_delivery',
    ];

    /**
     * @var string[]
     */
    private const TOKEN_LIST_FIELDS = [
        'shipping_methods',
        'available_shipping_methods',
        'supported_shipping_methods',
        'shipping_options',
        'delivery_methods',
        'shipping_tags',
        'fulfillment_tags',
        'delivery_speed',
        'shipping_speed',
        'shipping_service_level',
    ];

    /**
     * @var string[]
     */
    private const DELIVERY_DAY_FIELDS = [
        'delivery_days',
        'estimated_delivery_days',
        'shipping_days',
        'lead_time_days',
    ];

    /**
     * @var string[]
     */
    private const DELIVERY_HOUR_FIELDS = [
        'delivery_hours',
        'estimated_delivery_hours',
        'shipping_hours',
        'lead_time_hours',
    ];

    /**
     * @param Product|null $productModel
     */
    public function __construct(
        private readonly ?Product $productModel = null
    ) {
        $this->sortOrder = 25;
        $this->displayType = 'checkbox';
    }

    public function getCode(): string
    {
        return 'shipping';
    }

    public function getName(): string
    {
        return __('Shipping');
    }

    public function getOptions(int $categoryId, array $productIds, array $appliedFilters = []): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = $this->loadProducts($productIds);
        if ($products === []) {
            return [];
        }

        $options = [];
        foreach ([
            self::SHIPPING_FREE => __('Free Shipping'),
            self::SHIPPING_SAME_DAY => __('Same Day Delivery'),
            self::SHIPPING_NEXT_DAY => __('Next Day Delivery'),
            self::SHIPPING_EXPRESS => __('Express Delivery'),
        ] as $value => $label) {
            $count = count($this->getMatchingProductIds($products, $value));
            if ($count <= 0) {
                continue;
            }

            $options[] = $this->buildOption(
                $value,
                (string) $label,
                $count,
                $this->isValueSelected($value, $appliedFilters)
            );
        }

        return $options;
    }

    public function apply(array $productIds, array $filterValues): array
    {
        if ($productIds === [] || $filterValues === []) {
            return $productIds;
        }

        $products = $this->loadProducts($productIds);
        if ($products === []) {
            return [];
        }

        $filteredIds = [];
        foreach ($filterValues as $value) {
            $filteredIds = array_merge($filteredIds, $this->getMatchingProductIds($products, (string) $value));
        }

        $filteredIds = array_values(array_unique(array_map('intval', $filteredIds)));
        if ($filteredIds === []) {
            return [];
        }

        return array_values(array_intersect(array_map('intval', $productIds), $filteredIds));
    }

    public function getValueLabel(string $value): string
    {
        return match ($value) {
            self::SHIPPING_FREE => (string) __('Free Shipping'),
            self::SHIPPING_SAME_DAY => (string) __('Same Day Delivery'),
            self::SHIPPING_NEXT_DAY => (string) __('Next Day Delivery'),
            self::SHIPPING_EXPRESS => (string) __('Express Delivery'),
            default => $value,
        };
    }

    /**
     * @param array<int, int|string> $productIds
     * @return array<int, array<string, mixed>>
     */
    protected function loadProducts(array $productIds): array
    {
        try {
            $productModel = $this->productModel ? clone $this->productModel : ObjectManager::getInstance(Product::class);
            $rows = $productModel->reset()
                ->where(Product::schema_fields_ID, $productIds, 'in')
                ->select()
                ->fetchArray();

            if (!is_array($rows) || $rows === []) {
                return [];
            }

            if (isset($rows[Product::schema_fields_ID])) {
                return [$rows];
            }

            $products = [];
            foreach ($rows as $row) {
                if (is_array($row)) {
                    $products[] = $row;
                }
            }

            return $products;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return int[]
     */
    private function getMatchingProductIds(array $products, string $capability): array
    {
        $productIds = [];
        foreach ($products as $product) {
            $productId = (int) ($product[Product::schema_fields_ID] ?? $product['product_id'] ?? 0);
            if ($productId <= 0) {
                continue;
            }

            if ($this->matchesCapability($product, $capability)) {
                $productIds[] = $productId;
            }
        }

        return $productIds;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function matchesCapability(array $product, string $capability): bool
    {
        $tokens = $this->collectProductTokens($product);
        $deliveryDays = $this->extractNumericValue($product, self::DELIVERY_DAY_FIELDS);
        $deliveryHours = $this->extractNumericValue($product, self::DELIVERY_HOUR_FIELDS);

        return match ($capability) {
            self::SHIPPING_FREE => $this->hasTruthyField($product, self::FREE_SHIPPING_FIELDS)
                || $this->hasTokenMatch($tokens, ['free_shipping', 'free_delivery', 'free']),
            self::SHIPPING_SAME_DAY => $this->hasTruthyField($product, self::SAME_DAY_FIELDS)
                || ($deliveryDays !== null && $deliveryDays <= 0.0)
                || ($deliveryHours !== null && $deliveryHours <= 24.0)
                || $this->hasTokenMatch($tokens, ['same_day', 'same_day_delivery', 'today', 'today_delivery']),
            self::SHIPPING_NEXT_DAY => $this->hasTruthyField($product, self::NEXT_DAY_FIELDS)
                || $this->matchesCapability($product, self::SHIPPING_SAME_DAY)
                || ($deliveryDays !== null && $deliveryDays <= 1.0)
                || ($deliveryHours !== null && $deliveryHours <= 48.0)
                || $this->hasTokenMatch($tokens, ['next_day', 'next_day_delivery', 'overnight', 'tomorrow']),
            self::SHIPPING_EXPRESS => $this->hasTruthyField($product, self::EXPRESS_FIELDS)
                || $this->hasTokenMatch($tokens, ['express', 'priority', 'expedited', 'overnight', 'dhl', 'fedex']),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $product
     * @param string[] $fields
     */
    private function hasTruthyField(array $product, array $fields): bool
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $product)) {
                continue;
            }

            if ($this->normalizeBool($product[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $product
     * @return string[]
     */
    private function collectProductTokens(array $product): array
    {
        $tokens = [];
        foreach (self::TOKEN_LIST_FIELDS as $field) {
            if (!array_key_exists($field, $product)) {
                continue;
            }

            $tokens = array_merge($tokens, $this->flattenTokens($product[$field]));
        }

        return array_values(array_unique(array_filter($tokens)));
    }

    /**
     * @param string[] $tokens
     * @param string[] $keywords
     */
    private function hasTokenMatch(array $tokens, array $keywords): bool
    {
        foreach ($tokens as $token) {
            foreach ($keywords as $keyword) {
                $normalizedKeyword = $this->normalizeToken($keyword);
                if ($token === $normalizedKeyword || str_contains($token, $normalizedKeyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $product
     * @param string[] $fields
     */
    private function extractNumericValue(array $product, array $fields): ?float
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $product)) {
                continue;
            }

            $value = $this->normalizeNumeric($product[$field]);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function flattenTokens(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->flattenTokens($decoded);
            }

            $parts = preg_split('/[\s,|;]+/', $value) ?: [];
            return $this->normalizeTokens($parts);
        }

        if (is_array($value)) {
            $tokens = [];
            foreach ($value as $item) {
                $tokens = array_merge($tokens, $this->flattenTokens($item));
            }

            return $this->normalizeTokens($tokens);
        }

        return $this->normalizeTokens([(string) $value]);
    }

    /**
     * @param array<int, string> $tokens
     * @return string[]
     */
    private function normalizeTokens(array $tokens): array
    {
        $normalized = [];
        foreach ($tokens as $token) {
            $token = $this->normalizeToken($token);
            if ($token !== '') {
                $normalized[] = $token;
            }
        }

        return $normalized;
    }

    private function normalizeToken(string $token): string
    {
        $token = strtolower(trim($token));
        $token = preg_replace('/[^a-z0-9]+/', '_', $token) ?? '';
        return trim($token, '_');
    }

    private function normalizeBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value > 0;
        }

        if (!is_string($value)) {
            return false;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y', 'on' => true,
            default => false,
        };
    }

    private function normalizeNumeric(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        if (!preg_match('/-?\d+(?:\.\d+)?/', $value, $matches)) {
            return null;
        }

        return (float) $matches[0];
    }
}
