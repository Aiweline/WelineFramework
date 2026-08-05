<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Shard;

/**
 * 分片 DDL provision 契约；{@see ShardSchemaProvisioner} 为默认实现。
 */
interface ShardSchemaProvisionerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function provision(string $familyCode, string $shardKey, array $context = []): ShardProvisionResult;
}
