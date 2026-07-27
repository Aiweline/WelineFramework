<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/**
 * Optional owning-module hook for installing the complete storefront scope
 * before FPC and routing. Framework never derives Store/Channel itself.
 */
interface StorefrontScopeInstallerInterface
{
    public function installNavigationScope(string $fullUri): StorefrontNavigationScope;
}
