<?php

declare(strict_types=1);

namespace Weline\Compare\Service;

use Weline\Framework\Manager\ObjectManager;

class ProductCardSnapshotResolver
{
    /**
     * @return array<string, mixed>|null
     */
    public function resolve(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        try {
            if (class_exists(\Weline\Product\Service\StorefrontCatalogViewService::class)) {
                /** @var \Weline\Product\Service\StorefrontCatalogViewService $catalog */
                $catalog = ObjectManager::getInstance(\Weline\Product\Service\StorefrontCatalogViewService::class);
                $offer = $catalog->publishedOffer($productId);
                if (is_array($offer) && $offer !== []) {
                    return $this->normalizeOffer($offer);
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @param array<string, mixed> $offer
     * @return array<string, mixed>
     */
    private function normalizeOffer(array $offer): array
    {
        $productId = (int)($offer['product_id'] ?? 0);
        $priceMinor = (int)($offer['unit_price_minor'] ?? $offer['price_minor'] ?? 0);
        $price = $priceMinor > 0
            ? $priceMinor / 100
            : (float)($offer['price'] ?? 0);
        $currency = trim((string)($offer['currency'] ?? 'CNY'));
        $slug = trim((string)($offer['slug'] ?? ''));
        $url = $slug !== '' ? '/product/' . $slug : '/product/' . $productId;

        return [
            'product_id' => $productId,
            'name' => (string)($offer['name'] ?? ''),
            'sku' => (string)($offer['sku'] ?? ''),
            'image' => (string)($offer['image'] ?? ''),
            'price' => $price,
            'currency' => $currency,
            'formatted_price' => $currency . ' ' . number_format($price, 2),
            'short_description' => trim((string)($offer['short_description'] ?? $offer['description'] ?? '')),
            'url' => $url,
            'rating' => (float)($offer['rating'] ?? 0),
            'review_count' => (int)($offer['review_count'] ?? 0),
            'attribute_set_label' => trim((string)($offer['attribute_set_label'] ?? '')),
            'specifications' => $this->normalizeSpecifications($offer),
            'attributes' => is_array($offer['attributes'] ?? null) ? $offer['attributes'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $offer
     * @return list<array{code: string, value: string}>
     */
    private function normalizeSpecifications(array $offer): array
    {
        $specifications = $offer['specifications'] ?? null;
        if (!is_array($specifications)) {
            return [];
        }

        $out = [];
        foreach ($specifications as $spec) {
            if (!is_array($spec)) {
                continue;
            }
            $code = strtolower(trim((string)($spec['code'] ?? '')));
            $value = trim((string)($spec['value'] ?? ''));
            if ($code !== '' && $value !== '') {
                $out[] = ['code' => $code, 'value' => $value];
            }
        }

        return $out;
    }
}
