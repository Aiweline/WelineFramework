<?php

declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Service\SuperAdminObjectScopeGrantService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;

/**
 * 系统升级完成后，为超级管理员（role_id=1）补齐对象 Scope 授权。
 *
 * 路由 RBAC 已在 {@see SetupUpgradeGrantSuperAdmin} 中增量授予；对象 Scope ACL 独立判定，
 * All Sites 只读 + Global 写 + 全部 Website 写（覆盖同站 store/channel）。
 */
final class SetupUpgradeGrantSuperAdminObjectScope implements ObserverInterface
{
    public function __construct(
        private readonly SuperAdminObjectScopeGrantService $grantService,
        private readonly Printing $printing,
    ) {
    }

    public function execute(Event &$event): void
    {
        $isPartialUpgrade = $event->getData('is_partial_upgrade') ?? false;
        $routeOnly = $event->getData('route_only') ?? false;
        $modelOnly = $event->getData('model_only') ?? false;
        if ($isPartialUpgrade || $routeOnly || $modelOnly) {
            return;
        }

        try {
            $inserted = $this->grantService->ensureBaselineGrants();
            if ($inserted === 0) {
                if (\defined('DEV') && DEV) {
                    $this->printing->note(__('超级管理员已拥有对象 Scope 授权，无需追加。'));
                }

                return;
            }

            $this->printing->success(
                __('已为超级管理员（role_id=1）追加 %{1} 条对象 Scope 授权。', [$inserted]),
            );
        } catch (\Throwable $exception) {
            if (\defined('DEV') && DEV) {
                $this->printing->warning(
                    __('为超级管理员授予对象 Scope 授权时出错：%{1}', [$exception->getMessage()]),
                );
            }
        }
    }
}
