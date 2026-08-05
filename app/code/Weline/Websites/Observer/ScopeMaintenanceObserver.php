<?php

declare(strict_types=1);

namespace Weline\Websites\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Websites\Service\MaintenancePreviewTokenService;
use Weline\Websites\Service\ScopeMaintenanceGate;

/**
 * Scope 维护门禁（TASK-P1D-004-MAINTENANCE）：维护中无有效只读 preview → 拒绝。
 */
final class ScopeMaintenanceObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        try {
            $scope = RequestContext::scopeIdentity();
            if (!$scope instanceof ScopeIdentity) {
                return;
            }
            /** @var ScopeMaintenanceGate $gate */
            $gate = ObjectManager::getInstance(ScopeMaintenanceGate::class);
            $maintenanceScope = $gate->maintenanceScope($scope);
            if (!$maintenanceScope instanceof ScopeIdentity) {
                return;
            }
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $token = \trim((string)$request->getParam('maintenance_preview_token', ''));
            if ($token === '') {
                $token = \trim((string)($_SERVER['HTTP_X_WELINE_MAINTENANCE_PREVIEW'] ?? ''));
            }
            /** @var MaintenancePreviewTokenService $tokens */
            $tokens = ObjectManager::getInstance(MaintenancePreviewTokenService::class);
            $ok = $token !== '' && $tokens->verify($token, $maintenanceScope);
            if ($ok) {
                // 只读预览：标记请求上下文，写路径由业务侧 assertWritable 拒绝
                RequestContext::set('scope.maintenance_preview', true);

                return;
            }
            throw new NoRouterException(503, 'scope_maintenance_blocked');
        } catch (NoRouterException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                w_log_warning('[ScopeMaintenanceObserver] ' . $e->getMessage(), [], 'websites');
            }
            // Store/keyring failures must not silently bypass maintenance.
            throw new NoRouterException(503, 'scope_maintenance_unavailable');
        }
    }
}
