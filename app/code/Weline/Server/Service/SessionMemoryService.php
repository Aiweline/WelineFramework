<?php

declare(strict_types=1);

namespace Weline\Server\Service;

use Weline\Server\Shared\Service\SharedMemoryService;

/**
 * Session domain service backed by unified shared memory service.
 */
class SessionMemoryService
{
    public function __construct(
        private readonly SharedMemoryService $memoryService
    ) {
    }

    public function read(string $sessionId): array
    {
        $data = $this->memoryService->get('sess', $sessionId);
        return \is_array($data) ? $data : [];
    }

    public function write(string $sessionId, array $data, int $ttl): bool
    {
        return $this->memoryService->set('sess', $sessionId, $data, $ttl);
    }

    public function destroy(string $sessionId): bool
    {
        return $this->memoryService->delete('sess', $sessionId);
    }

    public function exists(string $sessionId): bool
    {
        return $this->memoryService->exists('sess', $sessionId);
    }

    public function touch(string $sessionId, int $ttl): bool
    {
        return $this->memoryService->touch('sess', $sessionId, $ttl);
    }

    public function increment(string $sessionId, string $key, int $delta = 1, int $ttl = 0): ?int
    {
        $compositeKey = $sessionId . ':counter:' . $key;
        return $this->memoryService->incr('sess', $compositeKey, $delta, $ttl);
    }
}
