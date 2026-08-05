<?php

declare(strict_types=1);

namespace Weline\Cart\Api\Data;

/**
 * Catalog offer identity for Cart V2（REQ-009）.
 */
final class OfferIdentity
{
    public const SELECTION_SCHEMA_V1 = 'v1';

    public function __construct(
        public readonly string $providerCode,
        public readonly string $globalOfferUuid,
        public readonly ?int $legacyProductId = null,
        public readonly string $selectionSchemaVersion = self::SELECTION_SCHEMA_V1,
    ) {
        if (trim($providerCode) === '') {
            throw new \InvalidArgumentException(__('provider_code 不能为空'));
        }
        if (trim($globalOfferUuid) === '') {
            throw new \InvalidArgumentException(__('global_offer_uuid 不能为空'));
        }
        if ($legacyProductId !== null && $legacyProductId < 0) {
            throw new \InvalidArgumentException(__('legacy_product_id 须 >=0'));
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $legacy = $data['legacy_product_id'] ?? $data['product_id'] ?? null;
        return new self(
            providerCode: strtolower(trim((string)($data['provider_code'] ?? ''))),
            globalOfferUuid: trim((string)($data['global_offer_uuid'] ?? $data['offer_uuid'] ?? '')),
            legacyProductId: $legacy === null || $legacy === '' ? null : (int)$legacy,
            selectionSchemaVersion: trim((string)($data['selection_schema_version'] ?? self::SELECTION_SCHEMA_V1))
                ?: self::SELECTION_SCHEMA_V1,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'provider_code' => $this->providerCode,
            'global_offer_uuid' => $this->globalOfferUuid,
            'legacy_product_id' => $this->legacyProductId,
            'selection_schema_version' => $this->selectionSchemaVersion,
        ];
    }
}
