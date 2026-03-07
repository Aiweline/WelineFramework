<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Contract;

/**
 * Unified strong-consistency memory service contract.
 *
 * Namespace (ns) isolates domain data (e.g. sess/cache/cfg).
 */
interface MemoryServiceInterface
{
    public function get(string $ns, string $key): mixed;

    public function set(string $ns, string $key, mixed $value, int $ttl = 0): bool;

    public function delete(string $ns, string $key): bool;

    public function exists(string $ns, string $key): bool;

    public function touch(string $ns, string $key, int $ttl): bool;

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    public function mget(string $ns, array $keys): array;

    /**
     * @param array<string, mixed> $kv
     */
    public function mset(string $ns, array $kv, int $ttl = 0): bool;

    public function clearNamespace(string $ns): bool;
}
