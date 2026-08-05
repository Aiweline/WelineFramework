<?php

declare(strict_types=1);

namespace Weline\Search\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Index;
use Weline\Framework\Database\Schema\Attribute\Table;
use Weline\Framework\Database\Schema\Shard\ShardProvisionResult;
use Weline\Search\Api\SearchShardRegistryInterface;
use Weline\Search\Service\SearchShardSchemaCatalog;

/**
 * Search website shard registry：全局状态源，非分片表。
 */
#[Table(comment: 'Search website shard registry')]
#[Index(name: 'uk_search_shard_website', columns: ['website_id'], type: 'UNIQUE')]
#[Index(name: 'uk_search_shard_key', columns: ['shard_key'], type: 'UNIQUE')]
#[Index(name: 'idx_search_shard_status', columns: ['status'])]
class SearchShardRegistry extends Model implements SearchShardRegistryInterface
{
    public const schema_table = 'search_shard_registry';
    public const schema_primary_key = 'registry_id';

    public const STATUS_UNPROVISIONED = 'unprovisioned';
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_READY = ShardProvisionResult::STATUS_READY;
    public const STATUS_MAINTENANCE = ShardProvisionResult::STATUS_MAINTENANCE;
    public const STATUS_FAILED = ShardProvisionResult::STATUS_FAILED;

    /** @var array<string,list<string>> */
    private const ALLOWED_TRANSITIONS = [
        self::STATUS_UNPROVISIONED => [self::STATUS_PROVISIONING],
        self::STATUS_PROVISIONING => [
            self::STATUS_READY,
            self::STATUS_MAINTENANCE,
            self::STATUS_FAILED,
        ],
        self::STATUS_READY => [self::STATUS_PROVISIONING],
        self::STATUS_MAINTENANCE => [self::STATUS_PROVISIONING],
        self::STATUS_FAILED => [self::STATUS_PROVISIONING],
    ];

    #[Col('int', 11, primaryKey: true, autoIncrement: true, nullable: false, comment: 'Registry ID')]
    public const schema_fields_ID = 'registry_id';

    #[Col('int', 11, nullable: false, comment: 'Website ID (>=0)')]
    public const schema_fields_WEBSITE_ID = 'website_id';

    #[Col('varchar', 32, nullable: false, comment: 'Canonical decimal shard key')]
    public const schema_fields_SHARD_KEY = 'shard_key';

    #[Col('varchar', 32, nullable: false, default: self::STATUS_UNPROVISIONED, comment: 'Shard status')]
    public const schema_fields_STATUS = 'status';

    #[Col('varchar', 64, nullable: false, default: '', comment: 'Schema fingerprint')]
    public const schema_fields_FINGERPRINT = 'fingerprint';

    #[Col('varchar', 32, nullable: false, default: SearchShardSchemaCatalog::SCHEMA_VERSION, comment: 'Schema version')]
    public const schema_fields_SCHEMA_VERSION = 'schema_version';

    #[Col('text', nullable: true, comment: 'Last error')]
    public const schema_fields_ERROR_MESSAGE = 'error_message';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Created at')]
    public const schema_fields_CREATED_AT = 'created_at';

    #[Col('datetime', nullable: false, default: 'CURRENT_TIMESTAMP', comment: 'Updated at')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function getIdFieldName(): string
    {
        return self::schema_fields_ID;
    }

    public function ensureWebsite(int $websiteId): array
    {
        $shardKey = SearchShardKey::fromWebsiteId($websiteId);
        $existing = $this->newModel()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        if ($existing->getId()) {
            return $existing->getData();
        }

        try {
            $this->newModel()->setData([
                self::schema_fields_WEBSITE_ID => $websiteId,
                self::schema_fields_SHARD_KEY => $shardKey,
                self::schema_fields_STATUS => self::STATUS_UNPROVISIONED,
                self::schema_fields_FINGERPRINT => '',
                self::schema_fields_SCHEMA_VERSION => SearchShardSchemaCatalog::SCHEMA_VERSION,
                self::schema_fields_ERROR_MESSAGE => null,
            ])->save();
        } catch (\Throwable $insertError) {
            $concurrent = $this->newModel()
                ->where(self::schema_fields_WEBSITE_ID, $websiteId)
                ->find()
                ->fetch();
            if ($concurrent->getId()) {
                return $concurrent->getData();
            }
            throw $insertError;
        }

        $created = $this->newModel()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->find()
            ->fetch();
        if (!$created->getId()) {
            throw new \RuntimeException((string)__(
                'Search shard registry 创建后无法读取：website_id=%{1}',
                [$websiteId],
            ));
        }

        return $created->getData();
    }

    public function compareAndSet(int $websiteId, array $fromStatuses, string $toStatus): bool
    {
        $this->assertAllowedTransitions($fromStatuses, $toStatus);
        $row = $this->ensureWebsite($websiteId);
        $current = (string)($row[self::schema_fields_STATUS] ?? '');
        if (!\in_array($current, $fromStatuses, true)) {
            return false;
        }

        $this->newModel()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, $current)
            ->update([
                self::schema_fields_STATUS => $toStatus,
                self::schema_fields_ERROR_MESSAGE => null,
                self::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])
            ->fetch();

        return $this->getStatus($websiteId) === $toStatus;
    }

    public function markReady(int $websiteId, string $fingerprint, string $schemaVersion): void
    {
        $fingerprint = \trim($fingerprint);
        $schemaVersion = \trim($schemaVersion);
        if ($fingerprint === '' || $schemaVersion === '') {
            throw new \InvalidArgumentException((string)__(
                'Search shard ready 指纹与 schema version 不能为空',
            ));
        }
        $this->transitionFromProvisioning($websiteId, self::STATUS_READY, [
            self::schema_fields_FINGERPRINT => $fingerprint,
            self::schema_fields_SCHEMA_VERSION => $schemaVersion,
            self::schema_fields_ERROR_MESSAGE => null,
        ]);
        $row = $this->ensureWebsite($websiteId);
        if ((string)($row[self::schema_fields_FINGERPRINT] ?? '') !== $fingerprint
            || (string)($row[self::schema_fields_SCHEMA_VERSION] ?? '') !== $schemaVersion
        ) {
            throw new \RuntimeException((string)__(
                'Search shard ready 指纹写入冲突：website_id=%{1}',
                [$websiteId],
            ));
        }
    }

    public function markMaintenance(int $websiteId, string $errorMessage): void
    {
        $this->transitionFromProvisioning($websiteId, self::STATUS_MAINTENANCE, [
            self::schema_fields_ERROR_MESSAGE => \mb_substr($errorMessage, 0, 2000),
        ]);
    }

    public function markFailed(int $websiteId, string $errorMessage): void
    {
        $this->transitionFromProvisioning($websiteId, self::STATUS_FAILED, [
            self::schema_fields_ERROR_MESSAGE => \mb_substr($errorMessage, 0, 2000),
        ]);
    }

    public function getStatus(int $websiteId): string
    {
        return (string)($this->ensureWebsite($websiteId)[self::schema_fields_STATUS]
            ?? self::STATUS_UNPROVISIONED);
    }

    public function isReady(int $websiteId): bool
    {
        return $this->getStatus($websiteId) === self::STATUS_READY;
    }

    public function getFingerprint(int $websiteId): string
    {
        return (string)($this->ensureWebsite($websiteId)[self::schema_fields_FINGERPRINT] ?? '');
    }

    public function getSchemaVersion(int $websiteId): string
    {
        return (string)($this->ensureWebsite($websiteId)[self::schema_fields_SCHEMA_VERSION] ?? '');
    }

    public function assertReady(int $websiteId): void
    {
        SearchShardKey::fromWebsiteId($websiteId);
        if (!$this->isReady($websiteId)) {
            throw new \RuntimeException((string)__(
                'Search shard 未 ready：website_id=%{1} status=%{2}',
                [$websiteId, $this->getStatus($websiteId)],
            ));
        }
    }

    public function getRegisteredShardKeys(): array
    {
        try {
            $rows = $this->newModel()
                ->fields(self::schema_fields_SHARD_KEY)
                ->order(self::schema_fields_WEBSITE_ID)
                ->select()
                ->fetchArray();
        } catch (\Throwable) {
            return [SearchShardKey::fromWebsiteId(0)];
        }
        $keys = [];
        foreach ($rows as $row) {
            $key = \trim((string)($row[self::schema_fields_SHARD_KEY] ?? ''));
            try {
                SearchShardKey::parse($key);
                $keys[] = $key;
            } catch (\Throwable) {
                continue;
            }
        }

        return $keys === []
            ? [SearchShardKey::fromWebsiteId(0)]
            : \array_values(\array_unique($keys));
    }

    /** @param list<string> $fromStatuses */
    private function assertAllowedTransitions(array $fromStatuses, string $toStatus): void
    {
        if ($fromStatuses === []) {
            throw new \InvalidArgumentException((string)__(
                'Search shard 状态来源不能为空',
            ));
        }
        foreach ($fromStatuses as $fromStatus) {
            if (!\in_array($toStatus, self::ALLOWED_TRANSITIONS[$fromStatus] ?? [], true)) {
                throw new \InvalidArgumentException((string)__(
                    '非法 Search shard 状态迁移：%{1}→%{2}',
                    [$fromStatus, $toStatus],
                ));
            }
        }
    }

    /** @param array<string,mixed> $updates */
    private function transitionFromProvisioning(
        int $websiteId,
        string $terminalStatus,
        array $updates,
    ): void {
        $this->assertAllowedTransitions([self::STATUS_PROVISIONING], $terminalStatus);
        $this->newModel()
            ->where(self::schema_fields_WEBSITE_ID, $websiteId)
            ->where(self::schema_fields_STATUS, self::STATUS_PROVISIONING)
            ->update($updates + [
                self::schema_fields_STATUS => $terminalStatus,
                self::schema_fields_UPDATED_AT => \gmdate('Y-m-d H:i:s'),
            ])
            ->fetch();
        if ($this->getStatus($websiteId) !== $terminalStatus) {
            throw new \RuntimeException((string)__(
                'Search shard 终态写入冲突：website_id=%{1} expected=%{2}',
                [$websiteId, $terminalStatus],
            ));
        }
    }

    private function newModel(): self
    {
        $model = clone $this;

        return $model->clearData()->clearQuery();
    }
}
