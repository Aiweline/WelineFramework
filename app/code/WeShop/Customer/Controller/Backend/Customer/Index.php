<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Backend\Customer;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use WeShop\Customer\Model\Customer;

/**
 * 客户管理后台控制器
 */
class Index extends BackendController
{
    public function index()
    {
        /** @var Customer $customerModel */
        $customerModel = ObjectManager::getInstance(Customer::class);
        
        // 获取客户统计数据
        $totalCount = $customerModel->clear()->count();
        $activeCount = $customerModel->clear()->where('is_active', 1)->count();
        $inactiveCount = $customerModel->clear()->where('is_active', 0)->count();
        
        // 今日新增
        $today = date('Y-m-d');
        $todayCount = $customerModel->clear()
            ->where('DATE(created_at)', $today)
            ->count();
        
        $this->assign('title', __('客户管理'));
        $this->assign('total_count', $totalCount);
        $this->assign('active_count', $activeCount);
        $this->assign('inactive_count', $inactiveCount);
        $this->assign('today_count', $todayCount);
        
        return $this->fetch('customer/index');
    }
}
