<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Control-plane provider for immutable cache adapter descriptors.
 */
interface CacheAdapterProviderInterface
{
    /**
     * @return list<CacheAdapterDescriptor>
     */
    public function descriptors(): array;
}
