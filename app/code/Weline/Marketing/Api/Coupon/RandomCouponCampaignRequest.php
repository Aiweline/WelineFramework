<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Coupon;

final class RandomCouponCampaignRequest
{
    public const DISCOUNT_PERCENTAGE = 'percentage';
    public const DISCOUNT_FIXED = 'fixed_amount';

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public readonly string $sourceModule,
        public readonly string $sourceType,
        public readonly string $sourceId,
        public readonly string $sourceKey = '',
        public readonly string $displayName = '',
        public readonly string $discountType = self::DISCOUNT_FIXED,
        public readonly float $discountValue = 0.0,
        public readonly bool $active = false,
        public readonly int $existingRuleId = 0,
        public readonly int $priority = 90,
        public readonly array $metadata = [],
    ) {
    }
}
