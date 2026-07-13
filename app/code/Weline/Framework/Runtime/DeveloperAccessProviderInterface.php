<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\Request;

interface DeveloperAccessProviderInterface
{
    public function shouldInjectBootstrap(): bool;

    public function canAccessPanel(?Request $request = null): bool;

    public function canAccessApi(?Request $request = null): bool;
}
