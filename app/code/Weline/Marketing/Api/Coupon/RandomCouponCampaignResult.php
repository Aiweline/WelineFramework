<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Coupon;

final class RandomCouponCampaignResult
{
    public function __construct(
        public readonly int $ruleId,
        public readonly bool $enabled = false,
    ) {
    }
}
