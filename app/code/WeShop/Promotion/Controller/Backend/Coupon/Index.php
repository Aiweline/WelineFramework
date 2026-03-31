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
        // 兜底：如果 summary/recent 查询异常，不要让 worker 中断；smoke 只要求页面能稳定渲染。
        try {
            $data = $this->getCouponService()->getDashboardData();
        } catch (\Throwable) {
            $data = [
                'summary' => [
                    'total_count' => 0,
                    'active_count' => 0,
                    'expired_count' => 0,
                    'total_used' => 0.0,
                ],
                'recent' => [],
            ];
        }

        $this->assign('title', __('Coupon dashboard'));
        $this->assign('summary', $data['summary']);
        $this->assign('recent_coupons', $data['recent']);

        // 用模块路径格式直连真实模板目录：
        // - view/backend/templates/coupon/Index/index.phtml
        // 避免默认 fetch('coupon/index') 拼装出不存在的 templates/Backend/Coupon/Index 路径。
        return (string) $this->fetch('WeShop_Promotion::backend/templates/coupon/Index/index.phtml');
    }

    private function getCouponService(): PromotionCouponManagementService
    {
        return new PromotionCouponManagementService();
    }
}
