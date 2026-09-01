<?php

declare(strict_types=1);

namespace Weline\Marketing\Api\Coupon;

/**
 * Extension point: managed coupon campaigns that mint per-redeemer random codes.
 */
interface RandomCouponCampaignProviderInterface
{
    public function upsert(RandomCouponCampaignRequest $request): RandomCouponCampaignResult;

    /**
     * @param array<string, mixed> $context
     * @return array{coupon_code:string,coupon_id:int}
     */
    public function issueRandomCoupon(int $ruleId, array $context = []): array;
}
