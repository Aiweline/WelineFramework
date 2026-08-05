<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Shard;

use Weline\Framework\Database\Schema\SchemaDiffOp;

/**
 * 单次分片 DDL provision 结果。失败时进入 maintenance/failed，不删表。
 */
final class ShardProvisionResult
{
    public const STATUS_PROVISIONING = 'provisioning';
    public const STATUS_READY = 'ready';
    public const STATUS_MAINTENANCE = 'maintenance';
    public const STATUS_FAILED = 'failed';

    /**
     * @param list<string> $tableNames
     * @param list<SchemaDiffOp> $ops
     * @param array<string, string> $tableFingerprints tableName => fingerprint
     */
    public function __construct(
        public readonly string $familyCode,
        public readonly string $shardKey,
        public readonly string $status,
        public readonly string $fingerprint,
        public readonly array $tableNames = [],
        public readonly array $tableFingerprints = [],
        public readonly array $ops = [],
        public readonly ?string $errorMessage = null,
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isWritable(): bool
    {
        return $this->isReady();
    }
}
