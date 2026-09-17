<?php

declare(strict_types=1);

namespace Weline\Marketing\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\Message;
use Weline\Framework\Manager\ObjectManager;
use Weline\Marketing\Service\WinbackDashboardAggregator;

#[Acl('Weline_Marketing::commerce:marketing:winback_dashboard', '挽回看板', 'chart', '挽回发送与券核销只读汇总', 'Weline_Backend::marketing_group')]
final class WinbackDashboard extends BackendController
{
    #[Acl('Weline_Marketing::commerce:marketing:winback_dashboard_index', '挽回看板', 'chart', '查看挽回运营汇总')]
    public function index(): string
    {
        try {
            /** @var WinbackDashboardAggregator $agg */
            $agg = ObjectManager::getInstance(WinbackDashboardAggregator::class);
            $rows = $agg->summarize();
            $this->assign('rows', $rows);
            $this->assign('load_error', '');
        } catch (\Throwable $e) {
            Message::error(__('加载看板失败：%{1}', $e->getMessage()));
            $this->assign('rows', []);
            $this->assign('load_error', $e->getMessage());
        }

        return $this->fetch();
    }
}
