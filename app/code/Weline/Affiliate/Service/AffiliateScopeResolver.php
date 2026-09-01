<?php

declare(strict_types=1);

namespace Weline\Affiliate\Service;

use Weline\Cart\Api\CartScopeResolverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Runtime\ScopeIdentity;

/** 解析当前 website / store / channel 访问范围（前台优先走 CartScopeResolver 可信 scope）。 */
final class AffiliateScopeResolver
{
    /** @return array{website_id:int,store_code:string,channel_code:string,scope:ScopeIdentity} */
    public function resolve(): array
    {
        $scope = $this->resolveTrustedScope();
        if (!$scope instanceof ScopeIdentity || $scope->isGlobal()) {
            $scope = RequestContext::scopeIdentity();
        }
        if (!$scope instanceof ScopeIdentity || $scope->isGlobal()) {
            $websiteId = max(0, (int) (\w_env('website.id', 0) ?: 0));
            $scope = ScopeIdentity::website(
                $websiteId,
                trim((string) (\w_env('website.code', 'default') ?: 'default')) ?: 'default',
            );
        }

        return [
            'website_id' => max(0, (int) ($scope->websiteId ?? 0)),
            'store_code' => trim((string) ($scope->storeCode ?? '')),
            'channel_code' => trim((string) ($scope->channelCode ?? '')),
            'scope' => $scope,
        ];
    }

    private function resolveTrustedScope(): ?ScopeIdentity
    {
        try {
            $resolver = ObjectManager::getInstance(RuntimeProviderResolver::class)
                ->resolve(CartScopeResolverInterface::class);
            if ($resolver instanceof CartScopeResolverInterface) {
                $scope = $resolver->fromParams([]);
                if ($scope instanceof ScopeIdentity && !$scope->isGlobal()) {
                    return $scope;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}
