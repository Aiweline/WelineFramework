<?php

declare(strict_types=1);

namespace Weline\Customer\Api\View;

/** Customer-owned account hook context; unsupported sections return no projection. */
interface AccountSidebarProjectionProviderInterface
{
    public function forSections(string ...$supportedSections): ?AccountSidebarProjection;
}
