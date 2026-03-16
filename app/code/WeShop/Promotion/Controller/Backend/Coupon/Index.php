<?php

declare(strict_types=1);

namespace WeShop\Promotion\Controller\Backend\Coupon;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Promotion\Model\Coupon;

/**
 * 优惠券管理后台控制器
 */
class Index extends BackendController
{
    public function index()
    {
        /** @var Coupon $couponModel */
        $couponModel = ObjectManager::getInstance(Coupon::class);
        
        // 获取优惠券统计数据
        $totalCount = $couponModel->clear()->count();
        $activeCountWithEndDate = $couponModel->clear()
            ->where('is_active', 1)
            ->where('end_date', date('Y-m-d H:i:s'), '>=')
            ->count();
        $activeCountNoEndDate = $couponModel->clear()
            ->where('is_active', 1)
            ->where('end_date', '')
            ->count();
        $activeCount = $activeCountWithEndDate + $activeCountNoEndDate;
        $totalUsed = $couponModel->clear()->sum('used_count');
        $expiredCount = $couponModel->clear()
            ->where('end_date', '', '!=')
            ->where('end_date', date('Y-m-d H:i:s'), '<')
            ->count();
        
        $this->assign('title', __('优惠券管理'));
        $this->assign('total_count', $totalCount);
        $this->assign('active_count', $activeCount);
        $this->assign('total_used', $totalUsed ?? 0);
        $this->assign('expired_count', $expiredCount);
        
        return $this->fetch('coupon/index');
    }
}
