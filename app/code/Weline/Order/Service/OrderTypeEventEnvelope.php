<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Order\Model\Order;

/**
 * Non-destructive decorator for order domain events: appends order_type + type_payload.
 */
final class OrderTypeEventEnvelope
{
    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    public static function append(array $eventData, ?CommerceOrderTypeRegistry $registry = null): array
    {
        $registry ??= ObjectManager::getInstance(CommerceOrderTypeRegistry::class);
        $order = $eventData['order'] ?? null;
        $orderType = self::resolveOrderType($order, $eventData);
        $eventData['order_type'] = $orderType;
        $eventData['type_payload'] = self::buildTypePayload($orderType, $order, $registry);

        return $eventData;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private static function resolveOrderType(mixed $order, array $eventData): string
    {
        $raw = '';
        if ($order instanceof Order) {
            $raw = (string)$order->getData(Order::schema_fields_ORDER_TYPE);
        } elseif (is_array($order)) {
            $raw = (string)($order[Order::schema_fields_ORDER_TYPE] ?? $order['order_type'] ?? '');
        }
        if ($raw === '' && isset($eventData['order_type'])) {
            $raw = (string)$eventData['order_type'];
        }
        $normalized = strtolower(trim($raw));

        return $normalized !== '' ? $normalized : CommerceOrderTypeRegistry::CODE_TOC;
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildTypePayload(
        string $orderType,
        mixed $order,
        CommerceOrderTypeRegistry $registry,
    ): array {
        $type = $registry->get($orderType);
        $discountsApplied = $type !== null
            ? !$type->disablesStorefrontDiscounts()
            : $orderType === CommerceOrderTypeRegistry::CODE_TOC;

        if ($orderType === CommerceOrderTypeRegistry::CODE_TOC
            || ($type !== null && !$type->disablesStorefrontDiscounts())
        ) {
            return [
                'discounts_applied' => $discountsApplied,
            ];
        }

        // tob (or discount-disabled types): hang_status / group / deposit when available
        $payload = [
            'discounts_applied' => false,
        ];
        $facts = self::extractFacts($order);
        foreach ([
            'hang_status',
            'group_id',
            'price_list_id',
            'list_version',
            'deposit_ratio_bps',
            'deposit_amount_minor',
            'balance_amount_minor',
            'goods_subtotal_minor',
            'b2b_snapshot_ref',
            'moq',
            'qty_step',
        ] as $key) {
            if (!array_key_exists($key, $facts)) {
                continue;
            }
            $value = $facts[$key];
            if ($value === null || $value === '') {
                continue;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function extractFacts(mixed $order): array
    {
        $bags = [];
        if ($order instanceof Order) {
            $bags[] = $order->getData();
            foreach ([
                Order::schema_fields_MONEY_SNAPSHOT_JSON,
                Order::schema_fields_CATALOG_SNAPSHOT_JSON,
                Order::schema_fields_SCOPE_SNAPSHOT_JSON,
            ] as $jsonField) {
                $decoded = self::decodeJsonBag($order->getData($jsonField));
                if ($decoded !== []) {
                    $bags[] = $decoded;
                }
            }
        } elseif (is_array($order)) {
            $bags[] = $order;
            foreach (['money_snapshot_json', 'catalog_snapshot_json', 'scope_snapshot_json', 'type_payload'] as $key) {
                if (!isset($order[$key])) {
                    continue;
                }
                if (is_array($order[$key])) {
                    $bags[] = $order[$key];
                    continue;
                }
                $decoded = self::decodeJsonBag($order[$key]);
                if ($decoded !== []) {
                    $bags[] = $decoded;
                }
            }
        }

        $facts = [];
        foreach ($bags as $bag) {
            if (!is_array($bag)) {
                continue;
            }
            foreach ($bag as $key => $value) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                if (array_key_exists($key, $facts) && $facts[$key] !== null && $facts[$key] !== '') {
                    continue;
                }
                $facts[$key] = $value;
            }
            $nested = $bag['type_payload'] ?? null;
            if (is_array($nested)) {
                foreach ($nested as $key => $value) {
                    if (!is_string($key) || $key === '') {
                        continue;
                    }
                    if (array_key_exists($key, $facts) && $facts[$key] !== null && $facts[$key] !== '') {
                        continue;
                    }
                    $facts[$key] = $value;
                }
            }
        }

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonBag(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
