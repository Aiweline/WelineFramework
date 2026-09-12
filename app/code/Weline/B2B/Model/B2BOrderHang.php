<?php

declare(strict_types=1);

namespace Weline\B2B\Model;

/** Immutable-ish hang-order projection for ToB deposit lifecycle. */
final class B2BOrderHang
{
    public const STATUS_AWAITING_DEPOSIT = 'awaiting_deposit';
    public const STATUS_AWAITING_MERCHANT_APPROVAL = 'awaiting_merchant_approval';
    public const STATUS_AWAITING_BALANCE = 'awaiting_balance';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_AWAITING_DEPOSIT,
        self::STATUS_AWAITING_MERCHANT_APPROVAL,
        self::STATUS_AWAITING_BALANCE,
        self::STATUS_COMPLETED,
        self::STATUS_REJECTED,
        self::STATUS_EXPIRED,
    ];

    public const DEPOSIT_RATIO_BPS = 3000;

    /**
     * @param list<string> $tokenIds
     * @param list<array<string,mixed>> $reservationSnapshots
     */
    public function __construct(
        public readonly string $hangId,
        public readonly string $orderRef,
        public readonly string $customerId,
        public readonly int $websiteId,
        public readonly string $hangStatus,
        public readonly int $goodsSubtotalTaxedMinor,
        public readonly int $depositAmountMinor,
        public readonly int $balanceAmountMinor,
        public readonly int $shippingAmountMinor,
        public readonly bool $isShippingOwner,
        public readonly int $depositRatioBps,
        public readonly array $tokenIds,
        public readonly ?string $groupId = null,
        public readonly ?string $priceListId = null,
        public readonly ?int $listVersion = null,
        public readonly ?string $depositIntentCode = null,
        public readonly ?string $balanceIntentCode = null,
        public readonly array $reservationSnapshots = [],
        public readonly ?int $inventoryReservedAtEpoch = null,
        public readonly ?int $inventoryExpiresAtEpoch = null,
        public readonly int $createdAtEpoch = 0,
        public readonly int $updatedAtEpoch = 0,
    ) {
        if ($hangId === '' || strlen($hangId) > 64) {
            throw new \InvalidArgumentException(__('B2B hang_id 非法'));
        }
        if ($orderRef === '' || strlen($orderRef) > 64) {
            throw new \InvalidArgumentException(__('B2B hang order_ref 非法'));
        }
        if ($customerId === '' || strlen($customerId) > 64) {
            throw new \InvalidArgumentException(__('B2B hang customer_id 非法'));
        }
        if ($websiteId < 0
            || $goodsSubtotalTaxedMinor < 0
            || $depositAmountMinor < 0
            || $balanceAmountMinor < 0
            || $shippingAmountMinor < 0
            || $depositRatioBps < 0
            || $depositRatioBps > 10000
        ) {
            throw new \InvalidArgumentException(__('B2B hang 金额非法'));
        }
        if (!in_array($hangStatus, self::STATUSES, true)) {
            throw new \InvalidArgumentException(__('B2B hang_status 非法：%{1}', [$hangStatus]));
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'hang_id' => $this->hangId,
            'order_ref' => $this->orderRef,
            'customer_id' => $this->customerId,
            'website_id' => $this->websiteId,
            'hang_status' => $this->hangStatus,
            'goods_subtotal_taxed_minor' => $this->goodsSubtotalTaxedMinor,
            'deposit_amount_minor' => $this->depositAmountMinor,
            'balance_amount_minor' => $this->balanceAmountMinor,
            'shipping_amount_minor' => $this->shippingAmountMinor,
            'is_shipping_owner' => $this->isShippingOwner,
            'deposit_ratio_bps' => $this->depositRatioBps,
            'token_ids' => array_values($this->tokenIds),
            'group_id' => $this->groupId,
            'price_list_id' => $this->priceListId,
            'list_version' => $this->listVersion,
            'deposit_intent_code' => $this->depositIntentCode,
            'balance_intent_code' => $this->balanceIntentCode,
            'reservation_snapshots' => array_values($this->reservationSnapshots),
            'inventory_reserved_at_epoch' => $this->inventoryReservedAtEpoch,
            'inventory_expires_at_epoch' => $this->inventoryExpiresAtEpoch,
            'created_at_epoch' => $this->createdAtEpoch,
            'updated_at_epoch' => $this->updatedAtEpoch,
        ];
    }

    /** @return array<string,mixed> */
    public function typePayload(): array
    {
        return [
            'group_id' => $this->groupId,
            'price_list_id' => $this->priceListId,
            'list_version' => $this->listVersion,
            'deposit_ratio_bps' => $this->depositRatioBps,
            'deposit_amount_minor' => $this->depositAmountMinor,
            'balance_amount_minor' => $this->balanceAmountMinor,
            'goods_subtotal_taxed_minor' => $this->goodsSubtotalTaxedMinor,
            'hang_status' => $this->hangStatus,
            'b2b_hang_id' => $this->hangId,
            'discounts_applied' => false,
        ];
    }

    public function with(
        ?string $hangStatus = null,
        ?string $depositIntentCode = null,
        ?string $balanceIntentCode = null,
        ?array $reservationSnapshots = null,
        ?int $inventoryReservedAtEpoch = null,
        ?int $inventoryExpiresAtEpoch = null,
        ?int $updatedAtEpoch = null,
        ?int $balanceAmountMinor = null,
    ): self {
        return new self(
            hangId: $this->hangId,
            orderRef: $this->orderRef,
            customerId: $this->customerId,
            websiteId: $this->websiteId,
            hangStatus: $hangStatus ?? $this->hangStatus,
            goodsSubtotalTaxedMinor: $this->goodsSubtotalTaxedMinor,
            depositAmountMinor: $this->depositAmountMinor,
            balanceAmountMinor: $balanceAmountMinor ?? $this->balanceAmountMinor,
            shippingAmountMinor: $this->shippingAmountMinor,
            isShippingOwner: $this->isShippingOwner,
            depositRatioBps: $this->depositRatioBps,
            tokenIds: $this->tokenIds,
            groupId: $this->groupId,
            priceListId: $this->priceListId,
            listVersion: $this->listVersion,
            depositIntentCode: $depositIntentCode ?? $this->depositIntentCode,
            balanceIntentCode: $balanceIntentCode ?? $this->balanceIntentCode,
            reservationSnapshots: $reservationSnapshots ?? $this->reservationSnapshots,
            inventoryReservedAtEpoch: $inventoryReservedAtEpoch ?? $this->inventoryReservedAtEpoch,
            inventoryExpiresAtEpoch: $inventoryExpiresAtEpoch ?? $this->inventoryExpiresAtEpoch,
            createdAtEpoch: $this->createdAtEpoch,
            updatedAtEpoch: $updatedAtEpoch ?? $this->updatedAtEpoch,
        );
    }

    public static function computeDepositMinor(int $goodsSubtotalTaxedMinor, int $ratioBps = self::DEPOSIT_RATIO_BPS): int
    {
        if ($goodsSubtotalTaxedMinor < 0 || $ratioBps < 0 || $ratioBps > 10000) {
            throw new \InvalidArgumentException(__('B2B deposit 计算参数非法'));
        }

        return intdiv($goodsSubtotalTaxedMinor * $ratioBps, 10000);
    }
}
