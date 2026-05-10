<?php

declare(strict_types=1);

namespace WeShop\Promotion\Service;

use Weline\Framework\Manager\ObjectManager;
use WeShop\Promotion\Model\Coupon;

/**
 * 优惠券服务
 */
class CouponService
{
    /**
     * 应用优惠券
     * 
     * @param string $couponCode 优惠券代码
     * @param int $customerId 客户ID
     * @param float $orderTotal 订单总额
     * @return array ['discount' => float, 'coupon' => Coupon]
     */
    public function applyCoupon(string $couponCode, int $customerId, float $orderTotal): array
    {
        /** @var Coupon $coupon */
        $coupon = ObjectManager::getInstance(Coupon::class);
        $coupon->load($couponCode, 'code');
        
        if (!$coupon->getId()) {
            throw new \Exception(__('优惠券不存在'));
        }
        
        // 验证优惠券
        if (!$this->validateCoupon($coupon, $customerId, $orderTotal)) {
            throw new \Exception(__('优惠券不可用'));
        }
        
        // 计算折扣
        $discount = $this->calculateDiscount($coupon, $orderTotal);
        
        return [
            'discount' => $discount,
            'coupon' => $coupon,
        ];
    }
    
    /**
     * 验证优惠券
     * 
     * @param Coupon $coupon 优惠券
     * @param int $customerId 客户ID
     * @param float $orderTotal 订单总额
     * @return bool
     */
    protected function validateCoupon(Coupon $coupon, int $customerId, float $orderTotal): bool
    {
        // 检查是否启用
        if (!$coupon->getData(Coupon::schema_fields_IS_ACTIVE)) {
            return false;
        }

        // 检查有效期
        $now = date('Y-m-d H:i:s');
        if ($coupon->getData(Coupon::schema_fields_START_DATE) > $now || $coupon->getData(Coupon::schema_fields_END_DATE) < $now) {
            return false;
        }

        // 检查最低消费
        if ($coupon->getData(Coupon::schema_fields_MIN_AMOUNT) > $orderTotal) {
            return false;
        }
        
        return true;
    }
    
    /**
     * 计算折扣金额
     * 
     * @param Coupon $coupon 优惠券
     * @param float $orderTotal 订单总额
     * @return float
     */
    protected function calculateDiscount(Coupon $coupon, float $orderTotal): float
    {
        $discountType = $coupon->getData(Coupon::schema_fields_DISCOUNT_TYPE);
        $discountValue = (float)$coupon->getData(Coupon::schema_fields_DISCOUNT_VALUE);

        if ($discountType === 'percent') {
            // 百分比折扣
            $discount = $orderTotal * ($discountValue / 100);
        } else {
            // 固定金额折扣
            $discount = $discountValue;
        }

        // 检查最大折扣金额
        $maxDiscount = (float)$coupon->getData(Coupon::schema_fields_MAX_DISCOUNT);
        if ($maxDiscount > 0 && $discount > $maxDiscount) {
            $discount = $maxDiscount;
        }
        
        // 折扣不能超过订单总额
        if ($discount > $orderTotal) {
            $discount = $orderTotal;
        }
        
        return $discount;
    }
}
