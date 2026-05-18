<?php

declare(strict_types=1);

namespace WeShop\Promotion\Service;

use WeShop\Customer\Session\CustomerSession;

class CartCouponSessionService
{
    private const SESSION_KEY = 'weshop_promotion_applied_coupon';

    public function __construct(
        private readonly CouponService $couponService,
        private readonly CustomerSession $customerSession
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function applyCoupon(string $code, int $customerId, float $orderTotal): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new \InvalidArgumentException((string) __('Coupon code is required.'));
        }

        $result = $this->couponService->applyCoupon($code, $customerId, max(0.0, $orderTotal));
        $discount = max(0.0, (float) ($result['discount'] ?? 0));
        $this->customerSession->set(self::SESSION_KEY, [
            'code' => $code,
            'customer_id' => max(0, $customerId),
            'discount' => $discount,
            'applied_at' => time(),
        ]);
        $this->saveSession();

        return $result + [
            'discount' => $discount,
            'coupon_code' => $code,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAppliedCoupon(): ?array
    {
        $coupon = $this->customerSession->get(self::SESSION_KEY);
        if (!is_array($coupon)) {
            return null;
        }

        $code = trim((string) ($coupon['code'] ?? ''));
        if ($code === '') {
            $this->clearAppliedCoupon();
            return null;
        }

        $coupon['code'] = $code;
        $coupon['customer_id'] = max(0, (int) ($coupon['customer_id'] ?? 0));

        return $coupon;
    }

    public function clearAppliedCoupon(): void
    {
        $this->customerSession->delete(self::SESSION_KEY);
        $this->saveSession();
    }

    /**
     * @param array<int, mixed> $items
     * @param array<string, mixed> $totals
     * @return array<string, mixed>
     */
    public function collectTotals(int $customerId, array $items, array $totals): array
    {
        if ($items === []) {
            return $totals;
        }

        $coupon = $this->getAppliedCoupon();
        if ($coupon === null) {
            return $totals;
        }

        $couponCustomerId = (int) ($coupon['customer_id'] ?? 0);
        if ($couponCustomerId > 0 && $customerId > 0 && $couponCustomerId !== $customerId) {
            return $totals;
        }

        $subtotal = max(0.0, (float) ($totals['subtotal'] ?? 0));
        if ($subtotal <= 0.0) {
            return $totals;
        }

        try {
            $result = $this->couponService->applyCoupon((string) $coupon['code'], $customerId, $subtotal);
        } catch (\Throwable) {
            $this->clearAppliedCoupon();
            return $totals;
        }

        $discount = max(0.0, (float) ($result['discount'] ?? 0));
        if ($discount <= 0.0) {
            return $totals;
        }

        $totals['discount'] = max(0.0, (float) ($totals['discount'] ?? 0) + $discount);
        $totals['coupon_code'] = (string) $coupon['code'];
        $totals['coupon_discount'] = $discount;

        return $totals;
    }

    private function saveSession(): void
    {
        $session = $this->customerSession->getSession();
        if (method_exists($session, 'save')) {
            $session->save();
        }
    }
}
