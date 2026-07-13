<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\Request;

interface StartPageRouteProviderInterface
{
    public function resolveConfiguredRoute(Request $request): string;
}
