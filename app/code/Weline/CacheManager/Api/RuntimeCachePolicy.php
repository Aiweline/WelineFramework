<?php

declare(strict_types=1);

namespace Weline\CacheManager\Api;

/** Public scalar-only facade for runtime cache TTL policy reads. */
final class RuntimeCachePolicy
{
    public function __construct(
        private readonly \Weline\CacheManager\Service\RuntimeCachePolicy $policy,
    ) {
    }

    public function ttl(string $path, int $default, int $min = 1, int $max = 86400): int
    {
        return $this->policy->ttl($path, $default, $min, $max);
    }
}
