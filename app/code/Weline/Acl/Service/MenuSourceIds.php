<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Resource\MenuSourceProviderInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

final class MenuSourceIds
{
    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    /** @return list<string> */
    public function all(): array
    {
        $provider = $this->runtimeProviderResolver->resolve(MenuSourceProviderInterface::class);
        return $provider instanceof MenuSourceProviderInterface
            ? $provider->sourceIds()
            : [];
    }
}
