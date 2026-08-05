<?php

declare(strict_types=1);

namespace Weline\Order\Service;

use Weline\Order\Api\Data\MoneySnapshot;
use Weline\Order\Api\Data\ShippingSnapshot;
use Weline\Order\Model\CheckoutGroup;

/**
 * CheckoutGroup invariants（REQ-010 / DEC-015）.
 */
final class CheckoutGroupInvariant
{
    public const ERROR_MONEY = 'checkout_group_money_mismatch';
    public const ERROR_OWNER = 'checkout_group_shipping_owner_invalid';
    public const ERROR_SNAPSHOT = 'checkout_group_snapshot_mutate';
    public const ERROR_EMPTY = 'checkout_group_empty';

    /**
     * @param list<array<string, mixed>> $orders Each with money{...} and is_shipping_charge_owner
     * @param array<string, mixed> $groupTotals
     */
    public function assertMoneyConservation(array $orders, array $groupTotals): void
    {
        if ($orders === []) {
            throw new OrderFacadeConflictException(self::ERROR_EMPTY, \__('CheckoutGroup 不能无 Order'));
        }
        $sub = 0;
        $ship = 0;
        $tax = 0;
        $grand = 0;
        foreach ($orders as $o) {
            $m = $o['money'] ?? [];
            $sub += (int)($m['subtotal_minor'] ?? 0);
            $ship += (int)($m['shipping_amount_minor'] ?? 0);
            $tax += (int)($m['tax_amount_minor'] ?? 0);
            $grand += (int)($m['grand_total_minor'] ?? 0);
        }
        if ($sub !== (int)($groupTotals['subtotal_minor'] ?? -1)
            || $ship !== (int)($groupTotals['shipping_amount_minor'] ?? -1)
            || $tax !== (int)($groupTotals['tax_amount_minor'] ?? -1)
            || $grand !== (int)($groupTotals['grand_total_minor'] ?? -1)
        ) {
            throw new OrderFacadeConflictException(
                self::ERROR_MONEY,
                \__('CheckoutGroup 金额不守恒'),
                [
                    'orders_subtotal' => $sub,
                    'group_subtotal' => $groupTotals['subtotal_minor'] ?? null,
                    'orders_shipping' => $ship,
                    'group_shipping' => $groupTotals['shipping_amount_minor'] ?? null,
                ],
            );
        }
    }

    /**
     * @param list<array<string, mixed>> $orders
     */
    public function assertSingleShippingOwner(array $orders, int $expectedShippingMinor, ?string $ownerUuid): void
    {
        $owners = [];
        $shipSum = 0;
        foreach ($orders as $o) {
            $ship = (int)(($o['money']['shipping_amount_minor'] ?? 0));
            $shipSum += $ship;
            if (!empty($o['is_shipping_charge_owner'])) {
                $owners[] = (string)($o['order_uuid'] ?? '');
                if ($ship !== $expectedShippingMinor) {
                    throw new OrderFacadeConflictException(
                        self::ERROR_OWNER,
                        \__('shipping owner 必须承载组运费 100%'),
                        ['owner' => $o['order_uuid'] ?? null, 'shipping' => $ship],
                    );
                }
            } elseif ($ship !== 0) {
                throw new OrderFacadeConflictException(
                    self::ERROR_OWNER,
                    \__('非 owner Order 运费必须为 0'),
                    ['order_uuid' => $o['order_uuid'] ?? null, 'shipping' => $ship],
                );
            }
        }
        if ($expectedShippingMinor > 0) {
            if (count($owners) !== 1 || ($ownerUuid !== null && $owners[0] !== $ownerUuid)) {
                throw new OrderFacadeConflictException(
                    self::ERROR_OWNER,
                    \__('必须恰好一个 shipping charge owner'),
                    ['owners' => $owners, 'expected' => $ownerUuid],
                );
            }
        }
        if ($shipSum !== $expectedShippingMinor) {
            throw new OrderFacadeConflictException(
                self::ERROR_OWNER,
                \__('运费合计不守恒'),
                ['sum' => $shipSum, 'expected' => $expectedShippingMinor],
            );
        }
    }

    public function assertSnapshotFrozen(MoneySnapshot $original, MoneySnapshot $candidate): void
    {
        if ($original->toArray() !== $candidate->toArray()) {
            throw new OrderFacadeConflictException(
                self::ERROR_SNAPSHOT,
                \__('MoneySnapshot 不可变'),
            );
        }
    }

    /** @return list<string> */
    public function allowedGroupTransitions(string $from): array
    {
        return match ($from) {
            CheckoutGroup::STATUS_PENDING => [CheckoutGroup::STATUS_PAID, CheckoutGroup::STATUS_CANCELLED],
            CheckoutGroup::STATUS_PAID => [CheckoutGroup::STATUS_COMPLETED, CheckoutGroup::STATUS_CANCELLED],
            default => [],
        };
    }

    public function canTransitionGroup(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        return in_array($to, $this->allowedGroupTransitions($from), true);
    }
}
