<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Model;

/**
 * 下单瞬间冻结的资产分摊快照；后续余额变更不回算。
 */
final class AssetOrderAllocationSnapshot
{
    /**
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $orderRef,
        public readonly string $payableId,
        public readonly string $accountId,
        public readonly string $reservationId,
        public readonly int $assetAmountMinor,
        public readonly int $cashAmountMinor,
        public readonly string $assetCode,
        public readonly string $namespace,
        public readonly string $hash,
        public readonly array $meta = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_ref' => $this->orderRef,
            'payable_id' => $this->payableId,
            'account_id' => $this->accountId,
            'reservation_id' => $this->reservationId,
            'asset_amount_minor' => $this->assetAmountMinor,
            'cash_amount_minor' => $this->cashAmountMinor,
            'asset_code' => $this->assetCode,
            'namespace' => $this->namespace,
            'hash' => $this->hash,
            'meta' => $this->meta,
        ];
    }
}
