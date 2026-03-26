<?php

declare(strict_types=1);

namespace Weline\Server\Service\Contract;

interface SessionStateFacadeInterface
{
    public function read(string $sessionId): array;

    public function write(string $sessionId, array $data, int $ttl): bool;

    public function destroy(string $sessionId): bool;

    public function exists(string $sessionId): bool;

    public function touch(string $sessionId, int $ttl): bool;

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function list(array $options = []): array;

    public function gc(int $maxLifetime): int;

    public function persist(): bool;

    public function ping(): bool;

    /**
     * @return array<string, mixed>
     */
    public function getStats(): array;

    /**
     * @return array<string, mixed>
     */
    public function getRuntime(): array;

    public function disconnect(): void;
}
