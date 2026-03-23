<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Coupon;

use Weline\Framework\App\Controller\BackendController;
use WeShop\Promotion\Service\PromotionCouponManagementService;

/**
 * Coupon dashboard backend controller.
 */
class Index extends BackendController
{
    public function index()
    {
        $data = $this->getCouponService()->getDashboardData();

        $this->assign('title', __('Coupon dashboard'));
        $this->assign('summary', $data['summary']);
        $this->assign('recent_coupons', $data['recent']);

        return $this->fetch('coupon/index');
    }

    private function getCouponService(): PromotionCouponManagementService
    {
        return new PromotionCouponManagementService();
    }
}
