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
        $rawTarget = \trim((string)$this->request->getParam('target_scope', $this->request->getParam('scope', '')));
        $usable = $rawTarget === 'global'
            || ($rawTarget !== '' && \preg_match('/^[a-z0-9_-]+(?:\.[a-z0-9_-]+){2}$/D', \strtolower($rawTarget)) === 1);
        if (!$usable) {
            return $this->redirect('*/backend/dashboard/index', [
                'target_scope' => 'default.default.default',
            ]);
        }
        try {
            $target = ObjectManager::getInstance(PaymentObjectScopeService::class)->fromExplicitTarget([
                'target_scope' => $rawTarget,
            ]);
            $grant = ObjectManager::getInstance(BackendObjectAuthorizationGuardInterface::class)
                ->requireForQuery(ObjectAction::VIEW, $target);
        } catch (FrontendQueryException $exception) {
            $this->request->getResponse()->setCode(403);

            return $exception->getMessage();
        }
        /** @var DashboardBlock $dashboardBlock */
        $dashboardBlock = ObjectManager::make(DashboardBlock::class);

        $this->assign('dashboard_block', $dashboardBlock);
        $this->assign('dashboard', $dashboardBlock->getDashboardData($target));
        $legacy = \trim($target->isGlobal() ? 'global' : $target->toLegacyScopeString());
        $this->assign('target_scope', $legacy !== '' ? $legacy : 'default.default.default');
        $this->assign('expected_grant_version', $grant->matchedGrantVersion);
        $this->assign('title', __('支付诊断'));

        return $this->fetch();
    }
}
