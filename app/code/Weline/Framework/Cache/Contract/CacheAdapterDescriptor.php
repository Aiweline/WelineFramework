<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Immutable, executable-free cache adapter registration data.
 */
final readonly class CacheAdapterDescriptor
{
    /**
     * @param class-string<CacheAdapterCreatorInterface> $creatorClass
     */
    public function __construct(
        public string $driver,
        public string $creatorClass,
    ) {
        if (\preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $driver) !== 1) {
            throw new \InvalidArgumentException("Invalid cache driver name: {$driver}");
        }
        if ($creatorClass === '') {
            throw new \InvalidArgumentException("Cache adapter creator for {$driver} cannot be empty.");
        }
    }
}
