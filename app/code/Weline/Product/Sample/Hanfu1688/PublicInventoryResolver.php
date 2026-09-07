<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

final class PublicInventoryResolver
{
    /**
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    public function payload(array $offer, ?string $globalOfferUuid = null, int $storeId = 0): array
    {
        $quantity = $this->resolve($offer);
        if ($quantity === null) {
            return [];
        }
        $globalOfferUuid = strtolower(trim((string)$globalOfferUuid));
        if ($globalOfferUuid === '') {
            return ['stock' => $quantity];
        }

        return [
            'inventory' => [[
                'store_id' => max(0, $storeId),
                'global_offer_uuid' => $globalOfferUuid,
                'on_hand_minor' => $quantity,
            ]],
        ];
    }

    /** @param array<string,mixed> $offer */
    public function resolve(array $offer): ?int
    {
        $total = 0;
        $hasPublishedQuantity = false;
        foreach (is_array($offer['variants'] ?? null) ? $offer['variants'] : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $raw = $variant['public_available_quantity'] ?? null;
            if (is_int($raw)) {
                $quantity = $raw;
            } elseif (is_string($raw) && preg_match('/^\d+$/D', $raw) === 1) {
                $quantity = (int)$raw;
            } else {
                continue;
            }
            if ($quantity < 0) {
                continue;
            }
            $hasPublishedQuantity = true;
            if ($quantity > PHP_INT_MAX - $total) {
                throw new \OverflowException('hanfu_1688_public_inventory_overflow');
            }
            $total += $quantity;
        }

        return $hasPublishedQuantity ? $total : null;
    }
}
