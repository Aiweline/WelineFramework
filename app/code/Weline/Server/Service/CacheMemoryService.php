<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Shared\Service\SharedMemoryService;

/**
 * Cache domain service backed by unified shared memory service.
 */
class CacheMemoryService
{
    public function __construct(
        private readonly SharedMemoryService $memoryService
    ) {
    }

    public function get(string $poolIdentity, string $key): mixed
    {
        return $this->memoryService->get($this->ns($poolIdentity), $key);
    }

    public function set(string $poolIdentity, string $key, mixed $value, int $ttl = 0): bool
    {
        return $this->memoryService->set($this->ns($poolIdentity), $key, $value, $ttl);
    }

    public function delete(string $poolIdentity, string $key): bool
    {
        return $this->memoryService->delete($this->ns($poolIdentity), $key);
    }

    public function exists(string $poolIdentity, string $key): bool
    {
        return $this->memoryService->exists($this->ns($poolIdentity), $key);
    }

    public function clear(string $poolIdentity): bool
    {
        return $this->memoryService->clearNamespace($this->ns($poolIdentity));
    }

    public function incr(string $poolIdentity, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        return $this->memoryService->incr($this->ns($poolIdentity), $key, $delta, $ttl);
    }

    public function cas(string $poolIdentity, string $key, mixed $expected, mixed $value, int $ttl = 0): bool
    {
        return $this->memoryService->cas($this->ns($poolIdentity), $key, $expected, $value, $ttl);
    }

    private function ns(string $poolIdentity): string
    {
        return 'cache:' . $poolIdentity;
    }
}
