<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Deal;

/**
 * Cross-module request to upsert an automatic deal discount rule.
 *
 * Consumers (e.g. Weline_Promotion) must not write Marketing Rule models directly.
 */
final class ExternalDealDiscountRequest
{
    public const DISCOUNT_NONE = 'none';
    public const DISCOUNT_PERCENTAGE = 'percentage';
    public const DISCOUNT_FIXED = 'fixed_amount';

    /**
     * @param list<string> $skus
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $sourceModule,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $sourceKey = '',
        public readonly string $displayName = '',
        public readonly string $discountType = self::DISCOUNT_NONE,
        public readonly float $discountValue = 0.0,
        public readonly array $skus = [],
        public readonly bool $active = false,
        public readonly int $existingRuleId = 0,
        public readonly int $priority = 80,
        public readonly array $metadata = [],
    ) {
    }
}
