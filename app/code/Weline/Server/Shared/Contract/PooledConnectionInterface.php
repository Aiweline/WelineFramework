<?php

declare(strict_types=1);

namespace Weline\Server\Shared\Contract;

interface PooledConnectionInterface
{
    public function connect(): bool;

    public function isConnected(): bool;

    public function send(string $payload): bool;

    public function read(): ?array;

    public function ping(): bool;

    public function close(): void;
}
