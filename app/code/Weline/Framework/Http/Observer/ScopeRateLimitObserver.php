<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\ScopeRateLimiter;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 完整 Scope HTTP 限流（TASK-P1D-004-RATE）。默认关闭；开启后超限 fail-closed。
 */
final class ScopeRateLimitObserver implements ObserverInterface
{
    private const APPLIED_CONTEXT_KEY = 'scope.rate_limit.applied';

    public function execute(Event &$event): void
    {
        try {
            $enabled = (bool)Env::get('security.scope_rate_limit.enabled', false);
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                w_log_warning('[ScopeRateLimitObserver] ' . $e->getMessage(), [], 'security');
            }
            throw new NoRouterException(503, 'scope_rate_limit_unavailable');
        }
        if (!$enabled || RequestContext::get(self::APPLIED_CONTEXT_KEY, false) === true) {
            return;
        }
        try {
            $limit = (int)Env::get('security.scope_rate_limit.limit', 120);
            $window = (int)Env::get('security.scope_rate_limit.window', 60);
            $bucket = \trim((string)Env::get('security.scope_rate_limit.bucket', 'http'));
            $scope = $event->getData('scope_identity');
            if (!$scope instanceof ScopeIdentity) {
                $scope = RequestContext::scopeIdentity();
            }
            if (!$scope instanceof ScopeIdentity) {
                $websiteId = RequestContext::getWelineWebsiteId();
                $websiteCode = RequestContext::getWelineWebsiteCode() ?: 'default';
                $scope = ScopeIdentity::website(\max(0, $websiteId), $websiteCode !== '' ? $websiteCode : 'default');
            }
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $subject = $request->getServerBag()->getClientIp();
            /** @var ScopeRateLimiter $limiter */
            $limiter = ObjectManager::getInstance(ScopeRateLimiter::class);
            if (!$limiter->allow($scope, $bucket, $limit, $window, $subject)) {
                throw new NoRouterException(429, 'scope_rate_limited');
            }
            RequestContext::set(self::APPLIED_CONTEXT_KEY, true);
        } catch (NoRouterException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (\function_exists('w_log_warning')) {
                w_log_warning('[ScopeRateLimitObserver] ' . $e->getMessage(), [], 'security');
            }
            throw new NoRouterException(503, 'scope_rate_limit_unavailable');
        }
    }
}
