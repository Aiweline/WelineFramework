<?php

declare(strict_types=1);

namespace Weline\Server\Api\Runtime;

use Weline\Framework\Runtime\RuntimeRoutingPolicyInterface;
use Weline\Server\Service\Runtime\RoutingPolicyRegistry;

final class RuntimeRoutingPolicy implements RuntimeRoutingPolicyInterface
{
    public function shouldHijackCacheFile(): bool
    {
        return RoutingPolicyRegistry::shouldHijackCacheFile();
    }

    public function shouldHijackSessionFile(): bool
    {
        return RoutingPolicyRegistry::shouldHijackSessionFile();
    }
}
