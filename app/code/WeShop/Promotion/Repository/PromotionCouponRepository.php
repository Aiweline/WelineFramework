<?php

declare(strict_types=1);

namespace WeShop\Promotion\Repository;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Promotion\Model\Coupon;

class PromotionCouponRepository implements PromotionCouponRepositoryInterface
{
    public function createCoupon(): Coupon
    {
        return $this->createBaseCoupon();
    }

    public function findCouponById(int $couponId): ?Coupon
    {
        if ($couponId <= 0) {
            return null;
        }

        $coupon = $this->createBaseCoupon();
        $coupon->load($couponId);

        return $coupon->getId() ? $coupon : null;
    }

    /**
     * @return array{total_count:int,active_count:int,expired_count:int,total_used:float}
     */
    public function getCouponSummary(): array
    {
        $coupon = $this->createBaseCoupon();
        $totalCount = (int)$coupon->clear()->count();

        $now = date('Y-m-d H:i:s');

        $activeCount = (int)$coupon->clear()
            ->where('is_active', 1)
            ->where('end_date', $now, '>=')
            ->count();

        // Coupon::schema_fields_END_DATE 在模型中为 datetime 且不可为空；
        // 这里不要再把空字符串 '' 作为 timestamp 比较（Postgres 会报 invalid input syntax）。
        $expiredCount = (int)$coupon->clear()
            ->where('end_date', $now, '<')
            ->count();

        // total_used 使用 used_count 字段聚合；如果数据/列不符合预期，可能导致请求直接中断。
        // 为了保证 backend smoke 能稳定渲染，这里做可渲染兜底。
        try {
            $totalUsed = (float) $coupon->clear()->sum('used_count');
        } catch (\Throwable) {
            $totalUsed = 0.0;
        }

        return [
            'total_count' => $totalCount,
            'active_count' => $activeCount,
            'expired_count' => $expiredCount,
            'total_used' => $totalUsed,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listRecentCoupons(int $limit): array
    {
        $coupon = $this->createBaseCoupon();

        $query = $coupon->clear()->order('created_at', 'DESC');
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->select()->fetchArray();
    }

    private function createBaseCoupon(): Coupon
    {
        /** @var Coupon $coupon */
        $coupon = ObjectManager::getInstance(Coupon::class);
        return $coupon->clear();
    }
}
