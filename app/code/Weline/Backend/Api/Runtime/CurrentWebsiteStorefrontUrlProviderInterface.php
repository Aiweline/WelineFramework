<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Framework\Http\Request;

/** Optional current-website storefront URL for backend chrome (e.g. topbar). */
interface CurrentWebsiteStorefrontUrlProviderInterface
{
    public function resolve(Request $request): string;
}
