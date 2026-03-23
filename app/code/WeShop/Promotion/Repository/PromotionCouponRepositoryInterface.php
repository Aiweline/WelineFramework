<?php

declare(strict_types=1);

namespace WeShop\Promotion\Repository;

use WeShop\Promotion\Model\Coupon;

interface PromotionCouponRepositoryInterface
{
    public function createCoupon(): Coupon;

    public function findCouponById(int $couponId): ?Coupon;

    /**
     * @return array{total_count:int,active_count:int,expired_count:int,total_used:float}
     */
    public function getCouponSummary(): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRecentCoupons(int $limit): array;
}
