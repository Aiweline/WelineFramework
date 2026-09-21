<?php

declare(strict_types=1);

namespace Weline\Framework\Cache\Contract;

/**
 * Pure process-local bag (not shared L2). Capacity uses heat; pressure uses pin.
 */
interface ProcessMemoryStoreInterface
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, ?string $bucket = null, ?int $bytes = null): void;

    public function delete(string $key): bool;

    public function clear(?string $bucket = null): int;

    public function pin(string $key): void;

    public function unpin(string $key): void;

    public function pinBucket(string $bucket): void;

    public function unpinBucket(string $bucket): void;

    public function getItemCount(): int;

    public function estimateBytes(): int;

    /**
     * @return list<string> Evicted keys
     */
    public function evictByPolicy(string $tier, ?int $targetBytes = null, ?int $targetCount = null): array;

    /**
     * @return list<string>
     */
    public function keysInBucket(string $bucket): array;
}
