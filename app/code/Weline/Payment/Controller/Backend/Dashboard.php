<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Payment\Controller\Backend;

use Weline\Acl\Api\Authorization\BackendObjectAuthorizationGuardInterface;
use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrontendQueryException;
use Weline\Payment\Block\Backend\Dashboard as DashboardBlock;
use Weline\Payment\Service\PaymentObjectScopeService;

#[Acl('Weline_Payment::payment_dashboard', '支付统计驾驶舱', 'grid', '支付统计驾驶舱', 'Weline_Backend::payment_group')]
class Dashboard extends BackendController
{
    /**
     * 支付统计驾驶舱
     */
    #[Acl('Weline_Payment::payment_dashboard_index', '查看支付统计驾驶舱', 'grid', '查看支付统计驾驶舱')]
    public function index(): string
    {
        try {
            $target = ObjectManager::getInstance(PaymentObjectScopeService::class)->fromExplicitTarget([
                'target_scope' => (string)$this->request->getParam('target_scope', ''),
            ]);
            $grant = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class)
                ->requireForQuery(ObjectAction::VIEW, $target);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        } catch (\Throwable) {
            $this->request->getResponse()->setCode(403);

            return (string)__('操作授权条件不满足');
        }
        /** @var DashboardBlock $dashboardBlock */
        $dashboardBlock = ObjectManager::make(DashboardBlock::class);

        $this->assign('dashboard_block', $dashboardBlock);
        $this->assign('dashboard', $dashboardBlock->getDashboardData($target));
        $this->assign('target_scope', $target->isGlobal() ? 'global' : $target->toLegacyScopeString());
        $this->assign('expected_grant_version', $grant->matchedGrantVersion);
        $this->assign('title', __('支付诊断'));

        return $this->fetch();
    }
}
