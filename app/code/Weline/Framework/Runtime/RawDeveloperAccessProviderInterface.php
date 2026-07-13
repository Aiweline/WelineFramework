<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

interface RawDeveloperAccessProviderInterface
{
    public function canAccessRawHttp(string $rawRequest): bool;
}
