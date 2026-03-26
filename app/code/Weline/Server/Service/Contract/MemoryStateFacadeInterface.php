<?php

declare(strict_types=1);

namespace Weline\Server\Service\Contract;

interface MemoryStateFacadeInterface
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

    public function incr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int;

    public function decr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int;

    public function append(string $ns, string $key, mixed $value, int $ttl = 0): bool;

    public function cas(string $ns, string $key, mixed $expected, mixed $newValue, int $ttl = 0): bool;

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function list(array $options = []): array;

    /**
     * @return array<string, mixed>
     */
    public function getAll(string $ns): array;

    public function gc(int $maxLifetime): int;

    public function persist(): bool;

    public function ping(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array;

    public function getCache(string $poolIdentity, string $key): mixed;

    public function setCache(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool;

    public function deleteCache(string $poolIdentity, string $key): bool;

    public function hasCache(string $poolIdentity, string $key): bool;

    public function clearCache(string $poolIdentity): bool;

    /**
     * @return array<string, mixed>
     */
    public function getRuntime(): array;

    public function disconnect(): void;
}
