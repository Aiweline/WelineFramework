<?php

declare(strict_types=1);

namespace Weline\Vendor\Service;

use Weline\Vendor\Model\VendorIdentity;

/**
 * Thin backend command facade. Domain validation, rollout and persistence stay
 * in the existing Vendor services; controllers only pass trusted form input.
 */
final class VendorAdminService
{
    private ?VendorSettlementService $runtime = null;

    public function __construct(private readonly VendorRolloutGate $rollout)
    {
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function registerVendor(array $input): array
    {
        return $this->runtime()->vendors()->registerVendor([
            'vendor_id' => trim((string)($input['vendor_id'] ?? '')),
            'code' => trim((string)($input['code'] ?? '')),
            'legal_name' => trim((string)($input['legal_name'] ?? '')),
            'environment' => strtolower(trim((string)($input['environment'] ?? VendorIdentity::ENV_SANDBOX))),
            'status' => trim((string)($input['status'] ?? VendorIdentity::STATUS_ACTIVE)),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function authorizeWebsite(array $input): array
    {
        return $this->runtime()->vendors()->authorizeWebsite(
            trim((string)($input['vendor_id'] ?? '')),
            (int)($input['website_id'] ?? -1),
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function bindProduct(array $input): array
    {
        return $this->runtime()->vendors()->bindProduct([
            'vendor_id' => trim((string)($input['vendor_id'] ?? '')),
            'website_id' => (int)($input['website_id'] ?? -1),
            'store_id' => (int)($input['store_id'] ?? 0),
            'product_sku' => trim((string)($input['product_sku'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function upsertSplitRule(array $input): array
    {
        return $this->runtime()->upsertRule([
            'vendor_id' => trim((string)($input['vendor_id'] ?? '')),
            'website_id' => (int)($input['website_id'] ?? -1),
            'commission_bps' => (int)($input['commission_bps'] ?? -1),
            'currency' => strtoupper(trim((string)($input['currency'] ?? 'CNY'))),
            'legal_entity' => trim((string)($input['legal_entity'] ?? '')),
        ]);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function schedulePayout(array $input): array
    {
        return $this->runtime()->schedulePayout(
            trim((string)($input['snapshot_id'] ?? '')),
            trim((string)($input['idempotency_key'] ?? '')) ?: null,
        );
    }

    private function runtime(): VendorSettlementService
    {
        return $this->runtime ??= VendorSettlementService::forRuntime($this->rollout);
    }
}
