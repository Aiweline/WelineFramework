<?php

declare(strict_types=1);

namespace Weline\Search\Service;

use Weline\Framework\Database\Schema\Shard\ShardProvisionResult;
use Weline\Search\Api\SearchShardRegistryInterface;
use Weline\Search\Model\SearchShardKey;

/**
 * In-memory / service registry for Search website shards (TEST-P3C-01 harness).
 * Production ORM model mirrors the same fields; this store is the testable seam.
 */
final class SearchShardRegistryStore implements SearchShardRegistryInterface
{
    public const STATUS_UNPROVISIONED = 'unprovisioned';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_READY = ShardProvisionResult::STATUS_READY;
    public const STATUS_MAINTENANCE = ShardProvisionResult::STATUS_MAINTENANCE;
    public const STATUS_FAILED = ShardProvisionResult::STATUS_FAILED;

    /**
     * @var array<int, array{
     *   website_id:int,
     *   shard_key:string,
     *   status:string,
     *   fingerprint:string,
     *   schema_version:string,
     *   error_message:?string
     * }>
     */
    private array $rows = [];

    public static function forTesting(array $websiteIds = [0]): self
    {
        $store = new self();
        foreach ($websiteIds as $websiteId) {
            $store->ensureWebsite((int) $websiteId);
        }

        return $store;
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureWebsite(int $websiteId): array
    {
        $shardKey = SearchShardKey::fromWebsiteId($websiteId);
        if (isset($this->rows[$websiteId])) {
            return $this->rows[$websiteId];
        }
        $this->rows[$websiteId] = [
            'website_id' => $websiteId,
            'shard_key' => $shardKey,
            'status' => self::STATUS_UNPROVISIONED,
            'fingerprint' => '',
            'schema_version' => SearchShardSchemaCatalog::SCHEMA_VERSION,
            'error_message' => null,
        ];

        return $this->rows[$websiteId];
    }

    public function markReady(int $websiteId, string $fingerprint, string $schemaVersion = SearchShardSchemaCatalog::SCHEMA_VERSION): void
    {
        $row = $this->ensureWebsite($websiteId);
        $row['status'] = self::STATUS_READY;
        $row['fingerprint'] = $fingerprint;
        $row['schema_version'] = $schemaVersion;
        $row['error_message'] = null;
        $this->rows[$websiteId] = $row;
    }

    public function markFailed(int $websiteId, string $errorMessage): void
    {
        $row = $this->ensureWebsite($websiteId);
        $row['status'] = self::STATUS_FAILED;
        $row['error_message'] = mb_substr($errorMessage, 0, 2000);
        $this->rows[$websiteId] = $row;
    }

    public function compareAndSet(int $websiteId, array $fromStatuses, string $toStatus): bool
    {
        $row = $this->ensureWebsite($websiteId);
        if (!in_array((string)$row['status'], $fromStatuses, true)) {
            return false;
        }
        $row['status'] = $toStatus;
        $this->rows[$websiteId] = $row;

        return true;
    }

    public function markMaintenance(int $websiteId, string $errorMessage): void
    {
        $row = $this->ensureWebsite($websiteId);
        $row['status'] = self::STATUS_MAINTENANCE;
        $row['error_message'] = mb_substr($errorMessage, 0, 2000);
        $this->rows[$websiteId] = $row;
    }

    public function getStatus(int $websiteId): string
    {
        return (string) ($this->ensureWebsite($websiteId)['status'] ?? self::STATUS_UNPROVISIONED);
    }

    public function isReady(int $websiteId): bool
    {
        return $this->getStatus($websiteId) === self::STATUS_READY;
    }

    public function getFingerprint(int $websiteId): string
    {
        return (string) ($this->ensureWebsite($websiteId)['fingerprint'] ?? '');
    }

    public function getSchemaVersion(int $websiteId): string
    {
        return (string)($this->ensureWebsite($websiteId)['schema_version'] ?? '');
    }

    public function assertReady(int $websiteId): void
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数：%{1}', [$websiteId]));
        }
        if (!$this->isReady($websiteId)) {
            throw new \RuntimeException(__(
                'Search shard 未 ready：website_id=%{1} status=%{2}',
                [$websiteId, $this->getStatus($websiteId)],
            ));
        }
    }

    /**
     * @return list<string>
     */
    public function getRegisteredShardKeys(): array
    {
        if ($this->rows === []) {
            return [SearchShardKey::fromWebsiteId(0)];
        }
        $keys = [];
        foreach ($this->rows as $row) {
            $keys[] = (string) $row['shard_key'];
        }
        sort($keys, SORT_STRING);

        return array_values(array_unique($keys));
    }
}
