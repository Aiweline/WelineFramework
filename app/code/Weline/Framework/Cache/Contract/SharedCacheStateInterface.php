<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

interface SharedCacheStateInterface
{
    /**
     * Generic namespaced state operations used by runtime coordination and
     * module-owned ephemeral caches. Implementations must keep these methods
     * atomic at the namespace/key boundary.
     */
    public function get(string $namespace, string $key): mixed;

    public function set(string $namespace, string $key, mixed $value, int $ttl = 0): bool;

    public function delete(string $namespace, string $key): bool;

    public function exists(string $namespace, string $key): bool;

    public function incr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int;

    public function cas(
        string $namespace,
        string $key,
        mixed $expected,
        mixed $value,
        int $ttl = 0,
    ): bool;

    public function clearNamespace(string $namespace): bool;

    public function getCache(string $poolIdentity, string $key): mixed;

    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool;

    public function deleteCache(string $poolIdentity, string $key): bool;

    public function hasCache(string $poolIdentity, string $key): bool;

    public function clearCache(string $poolIdentity): bool;

    public function compareAndSetCache(
        string $poolIdentity,
        string $key,
        mixed $expected,
        mixed $value,
        int $ttl = 0,
    ): bool;

    public function disconnect(): void;
}
