<?php

declare(strict_types=1);

namespace Weline\Cart\Api;

use Weline\Framework\Runtime\ScopeIdentity;

/**
 * Published server-owned Cart Scope boundary for declared module consumers.
 */
interface CartScopeResolverInterface
{
    /**
     * @param array<string, mixed> $params
     */
    public function fromParams(array $params): ScopeIdentity;
}
