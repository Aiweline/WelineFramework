<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Contract;

interface AtomicMemoryServiceInterface
{
    public function incr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int;

    public function decr(string $ns, string $key, int $delta = 1, int $ttl = 0): ?int;

    public function append(string $ns, string $key, mixed $value, int $ttl = 0): bool;

    public function cas(string $ns, string $key, mixed $expected, mixed $newValue, int $ttl = 0): bool;
}
