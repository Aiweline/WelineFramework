<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

interface SharedCacheStateFactoryInterface
{
    /**
     * Create an isolated client for a module-owned shared-cache namespace.
     *
     * @param array<string, mixed> $options
     */
    public function create(array $options = []): ?SharedCacheStateInterface;
}
