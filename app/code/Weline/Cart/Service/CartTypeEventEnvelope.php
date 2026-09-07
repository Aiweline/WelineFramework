<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Framework\Manager\ObjectManager;

/**
 * Non-destructive decorator for cart domain events: appends cart_type + type_payload.
 */
final class CartTypeEventEnvelope
{
    /**
     * @param array<string, mixed> $eventData
     * @param array<string, mixed>|null $summaryOrCart Preferred cart/summary bag for type resolution
     * @return array<string, mixed>
     */
    public static function append(
        array $eventData,
        ?array $summaryOrCart = null,
        ?CommerceCartTypeRegistry $registry = null,
    ): array {
        $registry ??= ObjectManager::getInstance(CommerceCartTypeRegistry::class);
        $bag = $summaryOrCart
            ?? (is_array($eventData['summary'] ?? null) ? $eventData['summary'] : null)
            ?? (is_array($eventData['cart'] ?? null) ? $eventData['cart'] : null)
            ?? [];

        $cartType = self::resolveCartType($bag, $eventData);
        $eventData['cart_type'] = $cartType;
        $eventData['type_payload'] = self::buildTypePayload($cartType, $bag, $registry);

        return $eventData;
    }

    /**
     * @param array<string, mixed> $bag
     * @param array<string, mixed> $eventData
     */
    private static function resolveCartType(array $bag, array $eventData): string
    {
        foreach ([$bag['cart_type'] ?? null, $eventData['cart_type'] ?? null] as $candidate) {
            $normalized = strtolower(trim((string)$candidate));
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return CommerceCartTypeRegistry::CODE_TOC;
    }

    /**
     * @param array<string, mixed> $bag
     * @return array<string, mixed>
     */
    private static function buildTypePayload(
        string $cartType,
        array $bag,
        CommerceCartTypeRegistry $registry,
    ): array {
        if (isset($bag['type_payload']) && is_array($bag['type_payload'])) {
            $payload = $bag['type_payload'];
            if (!array_key_exists('discounts_applied', $payload)) {
                $type = $registry->get($cartType)
                    ?? $registry->get(CommerceCartTypeRegistry::CODE_TOC);
                $payload['discounts_applied'] = $type !== null
                    ? !$type->disablesStorefrontDiscounts()
                    : true;
            }

            return $payload;
        }

        $type = $registry->get($cartType);
        $discountsApplied = $type !== null
            ? !$type->disablesStorefrontDiscounts()
            : $cartType === CommerceCartTypeRegistry::CODE_TOC;

        $payload = [
            'discounts_applied' => $discountsApplied,
        ];

        if ($cartType !== CommerceCartTypeRegistry::CODE_TOC
            && ($type === null || $type->disablesStorefrontDiscounts())
        ) {
            foreach (['hang_status', 'group_id', 'deposit_amount_minor', 'deposit_ratio_bps'] as $key) {
                if (!array_key_exists($key, $bag)) {
                    continue;
                }
                $value = $bag[$key];
                if ($value === null || $value === '') {
                    continue;
                }
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
