<?php

declare(strict_types=1);

namespace Weline\Backend\Api\Runtime;

use Weline\Framework\Http\Request;

/** Optional frontend start-page contribution consumed by Backend runtime. */
interface FrontendStartPageRouteProviderInterface
{
    public function resolve(Request $request): string;
}
