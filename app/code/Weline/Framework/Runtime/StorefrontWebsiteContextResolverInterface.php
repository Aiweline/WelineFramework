<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

/** Optional storefront Website discovery contract with no Scope side effects. */
interface StorefrontWebsiteContextResolverInterface
{
    public function resolveWebsiteContext(string $fullUri): ?StorefrontWebsiteContext;
}
