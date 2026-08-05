<?php

declare(strict_types=1);

namespace Weline\Search\Api;

interface SearchShardRegistryInterface
{
    /** @return array<string,mixed> */
    public function ensureWebsite(int $websiteId): array;

    /** @param list<string> $fromStatuses */
    public function compareAndSet(int $websiteId, array $fromStatuses, string $toStatus): bool;

    public function markReady(int $websiteId, string $fingerprint, string $schemaVersion): void;

    public function markMaintenance(int $websiteId, string $errorMessage): void;

    public function markFailed(int $websiteId, string $errorMessage): void;

    public function getStatus(int $websiteId): string;

    public function isReady(int $websiteId): bool;

    public function getFingerprint(int $websiteId): string;

    public function getSchemaVersion(int $websiteId): string;

    public function assertReady(int $websiteId): void;

    /** @return list<string> */
    public function getRegisteredShardKeys(): array;
}
