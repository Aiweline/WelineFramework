<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Payment\Block\Backend\Dashboard as DashboardBlock;

#[Acl('Weline_Payment::payment_dashboard', '支付统计驾驶舱', 'mdi-view-dashboard-outline', '支付统计驾驶舱', 'Weline_Backend::payment_group')]
class Dashboard extends BackendController
{
    /**
     * 支付统计驾驶舱
     */
    #[Acl('Weline_Payment::payment_dashboard_index', '查看支付统计驾驶舱', 'mdi-view-dashboard-outline', '查看支付统计驾驶舱')]
    public function index(): string
    {
        /** @var DashboardBlock $dashboardBlock */
        $dashboardBlock = ObjectManager::make(DashboardBlock::class);

        $this->assign('dashboard_block', $dashboardBlock);
        $this->assign('dashboard', $dashboardBlock->getDashboardData());
        $this->assign('title', __('支付统计驾驶舱'));

        return $this->fetch();
    }
}
