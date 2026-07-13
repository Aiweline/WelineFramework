<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Minimal atomic append-buffer contract for module-owned runtime batches.
 */
interface SharedBufferStateInterface
{
    public function get(string $namespace, string $key): mixed;

    public function set(string $namespace, string $key, mixed $value, int $ttl = 0): bool;

    public function delete(string $namespace, string $key): bool;

    public function incr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int;

    public function decr(string $namespace, string $key, int $delta = 1, int $ttl = 0): ?int;

    public function append(string $namespace, string $key, mixed $value, int $ttl = 0): bool;

    public function cas(
        string $namespace,
        string $key,
        mixed $expected,
        mixed $value,
        int $ttl = 0,
    ): bool;

    /** @return array<string, mixed> */
    public function getAll(string $namespace): array;

    public function ping(): bool;
}
