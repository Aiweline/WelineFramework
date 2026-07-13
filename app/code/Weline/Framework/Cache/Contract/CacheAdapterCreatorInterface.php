<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Cold-path creator for one module-provided cache adapter.
 *
 * Implementations must be stateless and must not be retained by the adapter.
 */
interface CacheAdapterCreatorInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function create(string $identity, array $config = []): CacheAdapterInterface;
}
