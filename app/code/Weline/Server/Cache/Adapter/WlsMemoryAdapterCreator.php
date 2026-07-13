<?php

declare(strict_types=1);

namespace Weline\Server\Cache\Adapter;

use Weline\Framework\Cache\Contract\CacheAdapterCreatorInterface;
use Weline\Framework\Cache\Contract\CacheAdapterInterface;

/**
 * Cold-path creator registered by the Server cache adapter provider.
 */
final class WlsMemoryAdapterCreator implements CacheAdapterCreatorInterface
{
    public function create(string $identity, array $config = []): CacheAdapterInterface
    {
        return new WlsMemoryAdapter($identity, $config);
    }
}
