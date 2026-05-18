<?php

declare(strict_types=1);

namespace WeShop\Price\Service;

use WeShop\Product\Model\Product;
use Weline\Currency\Service\CurrencyRateService;
use Weline\Framework\Event\EventsManager;

class PriceService
{
    public function __construct(
        private readonly Product $productModel,
        private readonly EventsManager $eventsManager,
        private readonly ?CurrencyRateService $currencyRateService = null
    ) {
    }

    public function calculatePrice(int $productId, ?int $customerId = null, int $quantity = 1): float
    {
        $productId = max(0, $productId);
        if ($productId <= 0) {
            throw new \InvalidArgumentException((string) __('Product ID is required.'));
        }

        $product = clone $this->productModel;
        $product->load($productId);
        if (!$product->getId()) {
            throw new \RuntimeException((string) __('Product does not exist.'));
        }

        return (float) ($this->resolveProduct($product, $customerId, $quantity)['price'] ?? 0.0);
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveProduct(Product $product, ?int $customerId = null, int $quantity = 1, array $context = []): array
    {
        $productData = $product->getData();
        if (!is_array($productData)) {
            $productData = [];
        }

        $resolved = $this->resolvePricePayload($productData, $customerId, $quantity, $context + [
            'product_id' => (int) $product->getId(),
        ]);

        return array_merge($productData, $resolved);
    }

    /**
     * @param array<string, mixed> $productData
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function resolveProductData(array $productData, ?int $customerId = null, int $quantity = 1, array $context = []): array
    {
        return array_merge($productData, $this->resolvePricePayload($productData, $customerId, $quantity, $context));
    }

    public function formatPrice(float $price, ?string $currency = null): string
    {
        $targetCurrency = trim((string) $currency) !== '' ? strtoupper((string) $currency) : null;
        return $this->getCurrencyRateService()->format($price, null, $targetCurrency);
    }

    /**
     * @param array<string, mixed> $productData
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolvePricePayload(array $productData, ?int $customerId, int $quantity, array $context): array
    {
        $quantity = max(1, $quantity);
        $basePrice = max(0.0, $this->normalizePositiveFloat($productData[Product::schema_fields_price] ?? null) ?? 0.0);

        $candidateSpecialPrices = array_filter([
            $this->normalizePositiveFloat($productData['special_price'] ?? null),
            $this->normalizePositiveFloat($productData['sale_price'] ?? null),
        ], static fn (?float $price): bool => $price !== null);

        $specialCandidate = null;
        if ($basePrice <= 0.0 && $candidateSpecialPrices !== []) {
            $basePrice = min($candidateSpecialPrices);
        } elseif ($candidateSpecialPrices !== []) {
            $belowBase = array_values(array_filter(
                $candidateSpecialPrices,
                static fn (float $price): bool => $price < $basePrice
            ));
            if ($belowBase !== []) {
                $specialCandidate = min($belowBase);
            }
        }

        $contextualCandidates = array_filter([
            $this->resolveCustomerSpecificPrice($productData, $customerId, $context),
            $this->resolveTierPrice($productData, $quantity, $context),
        ], static fn (?float $price): bool => $price !== null);

        if ($basePrice <= 0.0 && $contextualCandidates !== []) {
            $basePrice = min($contextualCandidates);
        } elseif ($contextualCandidates !== []) {
            $belowBase = array_values(array_filter(
                $contextualCandidates,
                static fn (float $price): bool => $price < $basePrice
            ));
            if ($belowBase !== []) {
                $specialCandidate = $specialCandidate === null
                    ? min($belowBase)
                    : min(array_merge([$specialCandidate], $belowBase));
            }
        }

        $resolved = [
            'price' => $specialCandidate ?? $basePrice,
            'original_price' => $basePrice,
            'base_price' => $basePrice,
            'final_price' => $specialCandidate ?? $basePrice,
            'special_price' => $specialCandidate,
            'sale_price' => $specialCandidate,
            'has_discount' => $specialCandidate !== null,
            'discount_amount' => $specialCandidate !== null ? max(0.0, $basePrice - $specialCandidate) : 0.0,
            'discount_percent' => $specialCandidate !== null && $basePrice > 0.0
                ? (int) round((($basePrice - $specialCandidate) / $basePrice) * 100)
                : 0,
        ];

        $legacyPrice = (float) $resolved['price'];
        $legacyOriginalPrice = (float) $resolved['original_price'];
        $legacySpecialPrice = $resolved['special_price'];
        $legacyContext = $context;
        $legacyEventData = [
            'product' => $productData,
            'customer_id' => $customerId,
            'quantity' => $quantity,
            'context' => &$legacyContext,
            'price' => &$legacyPrice,
            'original_price' => &$legacyOriginalPrice,
            'special_price' => &$legacySpecialPrice,
            'price_data' => &$resolved,
        ];
        $this->eventsManager->dispatch('WeShop_Price::calculate_price', $legacyEventData);

        return $this->sanitizeResolvedPayload($resolved, $legacyPrice, $legacyOriginalPrice, $legacySpecialPrice);
    }

    /**
     * @param array<string, mixed> $resolved
     * @return array<string, mixed>
     */
    private function sanitizeResolvedPayload(array $resolved, float $price, float $originalPrice, mixed $specialPrice): array
    {
        $finalPrice = max(0.0, $price);
        $original = max(0.0, $originalPrice);
        if ($original <= 0.0) {
            $original = $finalPrice;
        }

        $special = $this->normalizePositiveFloat($specialPrice);
        if ($special !== null && $special > $finalPrice) {
            $finalPrice = $special;
        }

        if ($original < $finalPrice) {
            $original = $finalPrice;
        }

        $hasDiscount = $original > $finalPrice;
        $special = $hasDiscount ? $finalPrice : null;
        $discountAmount = $hasDiscount ? round($original - $finalPrice, 2) : 0.0;
        $discountPercent = $hasDiscount && $original > 0.0
            ? (int) round(($discountAmount / $original) * 100)
            : 0;

        $resolved['price'] = round($finalPrice, 2);
        $resolved['final_price'] = round($finalPrice, 2);
        $resolved['original_price'] = round($original, 2);
        $resolved['base_price'] = round($original, 2);
        $resolved['special_price'] = $special !== null ? round($special, 2) : null;
        $resolved['sale_price'] = $special !== null ? round($special, 2) : null;
        $resolved['has_discount'] = $hasDiscount;
        $resolved['discount_amount'] = $discountAmount;
        $resolved['discount_percent'] = $discountPercent;

        return $resolved;
    }

    private function normalizePositiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $normalized = (float) $value;

        return $normalized > 0.0 ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $productData
     * @param array<string, mixed> $context
     */
    private function resolveCustomerSpecificPrice(array $productData, ?int $customerId, array $context): ?float
    {
        if ($customerId === null || $customerId <= 0) {
            return $this->normalizePositiveFloat($context['customer_price'] ?? null);
        }

        foreach ($this->normalizeCustomerPriceRows($context + $productData) as $row) {
            if ((int) ($row['customer_id'] ?? 0) !== $customerId) {
                continue;
            }

            $price = $this->normalizePositiveFloat($row['price'] ?? null);
            if ($price !== null) {
                return $price;
            }
        }

        return $this->normalizePositiveFloat($context['customer_price'] ?? null);
    }

    /**
     * @param array<string, mixed> $productData
     * @param array<string, mixed> $context
     */
    private function resolveTierPrice(array $productData, int $quantity, array $context): ?float
    {
        $bestTier = null;
        foreach ($this->normalizeTierPriceRows($context + $productData) as $row) {
            $minQty = max(1, (int) ($row['qty'] ?? 0));
            $price = $this->normalizePositiveFloat($row['price'] ?? null);
            if ($price === null || $quantity < $minQty) {
                continue;
            }

            if (
                $bestTier === null
                || $minQty > (int) $bestTier['qty']
                || ($minQty === (int) $bestTier['qty'] && $price < (float) $bestTier['price'])
            ) {
                $bestTier = [
                    'qty' => $minQty,
                    'price' => $price,
                ];
            }
        }

        return $bestTier['price'] ?? $this->normalizePositiveFloat($context['tier_price'] ?? null);
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array{customer_id: int, price: float|int|string|null}>
     */
    private function normalizeCustomerPriceRows(array $source): array
    {
        $rows = [];
        foreach (['customer_prices', 'price_by_customer', 'customer_price_overrides'] as $key) {
            $value = $source[$key] ?? null;
            if ($value === null) {
                continue;
            }

            foreach ($this->normalizeStructuredRows($value, ['customer_id', 'user_id', 'id'], ['price', 'value']) as $row) {
                $rows[] = [
                    'customer_id' => (int) ($row['key'] ?? 0),
                    'price' => $row['price'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array{qty: int, price: float|int|string|null}>
     */
    private function normalizeTierPriceRows(array $source): array
    {
        $rows = [];
        foreach (['tier_prices', 'price_tiers', 'quantity_prices'] as $key) {
            $value = $source[$key] ?? null;
            if ($value === null) {
                continue;
            }

            foreach ($this->normalizeStructuredRows($value, ['qty', 'quantity', 'min_qty'], ['price', 'value']) as $row) {
                $rows[] = [
                    'qty' => (int) ($row['key'] ?? 0),
                    'price' => $row['price'] ?? null,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param mixed $value
     * @param array<int, string> $keyCandidates
     * @param array<int, string> $priceCandidates
     * @return array<int, array{key: int|string, price: mixed}>
     */
    private function normalizeStructuredRows(mixed $value, array $keyCandidates, array $priceCandidates): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($value)) {
            return [];
        }

        $rows = [];
        foreach ($value as $rowKey => $rowValue) {
            if (is_numeric($rowKey) && is_array($rowValue)) {
                $resolvedKey = null;
                foreach ($keyCandidates as $candidate) {
                    if (array_key_exists($candidate, $rowValue)) {
                        $resolvedKey = $rowValue[$candidate];
                        break;
                    }
                }

                $resolvedPrice = null;
                foreach ($priceCandidates as $candidate) {
                    if (array_key_exists($candidate, $rowValue)) {
                        $resolvedPrice = $rowValue[$candidate];
                        break;
                    }
                }

                if ($resolvedKey === null) {
                    continue;
                }

                $rows[] = [
                    'key' => $resolvedKey,
                    'price' => $resolvedPrice,
                ];
                continue;
            }

            if (!is_numeric($rowKey)) {
                $rows[] = [
                    'key' => $rowKey,
                    'price' => $rowValue,
                ];
            }
        }

        return $rows;
    }

    protected function getCurrencyRateService(): CurrencyRateService
    {
        return $this->currencyRateService ?? \Weline\Framework\Manager\ObjectManager::getInstance(CurrencyRateService::class);
    }
}
