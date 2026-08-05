<?php

declare(strict_types=1);

namespace Weline\Vendor\Model;

/**
 * Immutable commission/legal/account split snapshot DTO（P4A-002）.
 * Owned by Weline_Vendor — never recalculated; Order/Payment only carry refs.
 */
final class VendorSplitSnapshot
{
    public const SCHEMA_VERSION = 'vendor.split.v2';

    /**
     * @param array<string, mixed> $legal
     * @param array<string, mixed> $account
     * @param array<string, mixed> $commission
     */
    public function __construct(
        public readonly string $snapshotId,
        public readonly string $vendorId,
        public readonly int $websiteId,
        public readonly int $storeId,
        public readonly string $storeMode,
        public readonly string $environment,
        public readonly string $checkoutGroupRef,
        public readonly string $orderRef,
        public readonly string $paymentRef,
        public readonly string $currency,
        public readonly int $grossMinor,
        public readonly int $vendorShareMinor,
        public readonly int $platformShareMinor,
        public readonly int $commissionBps,
        public readonly array $legal,
        public readonly array $account,
        public readonly array $commission,
        public readonly string $payloadHash,
        public readonly string $createdAt,
        public readonly string $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'schema_version' => $this->schemaVersion,
            'vendor_id' => $this->vendorId,
            'website_id' => $this->websiteId,
            'store_id' => $this->storeId,
            'store_mode_snapshot' => $this->storeMode,
            'environment' => $this->environment,
            'checkout_group_ref' => $this->checkoutGroupRef,
            'order_ref' => $this->orderRef,
            'payment_ref' => $this->paymentRef,
            'currency' => $this->currency,
            'gross_minor' => $this->grossMinor,
            'vendor_share_minor' => $this->vendorShareMinor,
            'platform_share_minor' => $this->platformShareMinor,
            'commission_bps' => $this->commissionBps,
            'legal' => $this->legal,
            'account' => $this->account,
            'commission' => $this->commission,
            'payload_hash' => $this->payloadHash,
            'created_at' => $this->createdAt,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            snapshotId: (string) ($data['snapshot_id'] ?? ''),
            vendorId: (string) ($data['vendor_id'] ?? ''),
            websiteId: (int) ($data['website_id'] ?? -1),
            storeId: (int) ($data['store_id'] ?? 0),
            storeMode: (string) ($data['store_mode_snapshot'] ?? ''),
            environment: (string) ($data['environment'] ?? ''),
            checkoutGroupRef: (string) ($data['checkout_group_ref'] ?? ''),
            orderRef: (string) ($data['order_ref'] ?? ''),
            paymentRef: (string) ($data['payment_ref'] ?? ''),
            currency: (string) ($data['currency'] ?? ''),
            grossMinor: (int) ($data['gross_minor'] ?? 0),
            vendorShareMinor: (int) ($data['vendor_share_minor'] ?? 0),
            platformShareMinor: (int) ($data['platform_share_minor'] ?? 0),
            commissionBps: (int) ($data['commission_bps'] ?? 0),
            legal: is_array($data['legal'] ?? null) ? $data['legal'] : [],
            account: is_array($data['account'] ?? null) ? $data['account'] : [],
            commission: is_array($data['commission'] ?? null) ? $data['commission'] : [],
            payloadHash: (string) ($data['payload_hash'] ?? ''),
            createdAt: (string) ($data['created_at'] ?? ''),
            schemaVersion: (string) ($data['schema_version'] ?? self::SCHEMA_VERSION),
        );
    }
}
