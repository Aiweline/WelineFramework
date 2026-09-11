<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\PlatformAppStore\Service\ModuleAdminService;

#[Acl('Weline_PlatformAppStore::earnings', '收益管理', 'mdi mdi-cash-multiple', '平台收益概览', 'Weline_PlatformAppStore::platform')]
class Earnings extends BackendController
{
    #[Acl('Weline_PlatformAppStore::earnings_view', '查看收益', 'list', '查看平台收益概览')]
    public function index(): string
    {
        /** @var ModuleAdminService $admin */
        $admin = ObjectManager::getInstance(ModuleAdminService::class);
        $developers = $admin->listDevelopers(100);

        $totalBalance = 0.0;
        $totalEarnings = 0.0;
        foreach ($developers as $row) {
            $totalBalance += (float)($row['balance'] ?? 0);
            $totalEarnings += (float)($row['total_earnings'] ?? 0);
        }

        $this->assign('developers', $developers);
        $this->assign('total_balance', $totalBalance);
        $this->assign('total_earnings', $totalEarnings);
        $this->assign('page_title', __('收益管理'));
        $this->assign('title', __('收益管理'));

        return $this->fetch();
    }
}
