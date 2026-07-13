<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Storage;

interface SharedSessionStateInterface
{
    public function read(string $sessionId): array;

    public function write(string $sessionId, array $data, int $ttl): bool;

    public function destroy(string $sessionId): bool;

    public function exists(string $sessionId): bool;

    public function touch(string $sessionId, int $ttl): bool;

    public function list(array $options = []): array;

    public function gc(int $maxLifetime): int;

    public function ping(): bool;

    public function getStats(): array;

    public function disconnect(): void;
}
