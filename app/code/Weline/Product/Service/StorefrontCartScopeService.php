<?php

declare(strict_types=1);

namespace Weline\Product\Service;

use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Exposes the current trusted storefront Scope to browser cart mutations.
 */
final class StorefrontCartScopeService
{
    /**
     * @return array<string, int|string|null>
     */
    public function current(): array
    {
        return $this->forScope(RequestContext::scopeIdentity());
    }

    /**
     * @return array<string, int|string|null>
     */
    public function forScope(?ScopeIdentity $scope): array
    {
        if (!$scope instanceof ScopeIdentity || $scope->isGlobal()) {
            return [];
        }

        return $scope->toArray();
    }
}
