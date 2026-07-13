<?php

declare(strict_types=1);

namespace Weline\Server\Api\Cache;

use Weline\Framework\Cache\Contract\CacheAdapterDescriptor;
use Weline\Framework\Cache\Contract\CacheAdapterProviderInterface;
use Weline\Server\Cache\Adapter\WlsMemoryAdapterCreator;

/**
 * Publishes Server-owned cache adapters as immutable control-plane data.
 */
final class WlsMemoryAdapterProvider implements CacheAdapterProviderInterface
{
    public function descriptors(): array
    {
        return [
            new CacheAdapterDescriptor('wls_memory', WlsMemoryAdapterCreator::class),
        ];
    }
}
