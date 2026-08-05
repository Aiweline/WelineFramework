<?php

declare(strict_types=1);

namespace Weline\Framework\Service\Query\Store;

/**
 * Per-record transaction boundary for Worker credentials.
 *
 * The raw credential and optional scope are opaque secrets. Implementations
 * must not persist them as plaintext index values.
 */
interface FrontendWorkerCredentialTransactionInterface
{
    /** Transaction-authoritative Unix timestamp. */
    public function now(): int;

    /** @return array<string, mixed>|null */
    public function find(
        string $type,
        string $credential,
        ?string $scope,
        int $now,
    ): ?array;

    /** @param array<string, mixed> $payload */
    public function insert(
        string $type,
        string $credential,
        ?string $scope,
        array $payload,
        int $createdAt,
        int $expiresAt,
    ): void;

    /**
     * Replace an active credential payload and expiry in place.
     *
     * Used to slide backend Worker session attestations while the browser keeps
     * the same worker_session_token across runtime_rotate ticket refreshes.
     *
     * @param array<string, mixed> $payload
     */
    public function replaceActive(
        string $type,
        string $credential,
        ?string $scope,
        array $payload,
        int $expiresAt,
    ): void;

    /** Transition an active one-time credential to a retained consumed tombstone. */
    public function consume(string $type, string $credential, ?string $scope): bool;

    /** Count every unexpired row retained for capacity, including consumed tombstones. */
    public function countRetained(string $type, ?string $scope, int $now): int;

    /**
     * Count the unexpired rows in the deterministic capacity shard for a
     * scoped credential. Implementations may lock and validate the owning
     * Session and delete a bounded batch of expired shard rows before the
     * count; they must serialize these operations with inserts into the same
     * shard.
     */
    public function countRetainedInCapacityBucket(string $type, ?string $scope, int $now): int;

    /** Sum encoded payload bytes for every unexpired retained row. */
    public function retainedBytes(string $type, ?string $scope, int $now): int;

    public function deleteExpired(int $now, ?string $type = null, ?string $scope = null): void;
}
