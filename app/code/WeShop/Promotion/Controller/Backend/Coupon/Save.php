<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Coupon;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Promotion\Service\PromotionCouponManagementService;

/**
 * Coupon save backend controller.
 */
class Save extends BackendController
{
    public function index(): string
    {
        try {
            $couponId = (int)($this->request->getParam('coupon_id') ?? 0);
            $payload = $this->getCouponPayload();

            $coupon = $this->getCouponService()->saveCoupon($payload, $couponId);

            return $this->fetchJson([
                'success' => true,
                'message' => __('Coupon saved successfully.'),
                'coupon_id' => $coupon->getId(),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->fetchJson(['success' => false, 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return $this->fetchJson(['success' => false, 'message' => __('Failed to save coupon.')]);
        }
    }

    private function getCouponPayload(): array
    {
        $type = $this->request->getParam('discount_type') ?? 'fixed';
        $value = $this->request->getParam('discount_value');

        return [
            'code' => (string)$this->request->getParam('code'),
            'name' => (string)$this->request->getParam('name'),
            'discount_type' => (string)$type,
            'discount_value' => is_numeric($value) ? (float)$value : 0.0,
            'min_amount' => (float)($this->request->getParam('min_amount') ?? 0.0),
            'max_discount' => (float)($this->request->getParam('max_discount') ?? 0.0),
            'start_date' => (string)($this->request->getParam('start_date') ?? ''),
            'end_date' => (string)($this->request->getParam('end_date') ?? ''),
            'is_active' => (int)($this->request->getParam('is_active') ?? 1),
        ];
    }

    private function getCouponService(): PromotionCouponManagementService
    {
        return new PromotionCouponManagementService();
    }
}
