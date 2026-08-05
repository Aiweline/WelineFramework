<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Schema\Shard;

use Weline\Framework\Database\Schema\SchemaProviderInterface;
use Weline\Framework\Database\Schema\TableSchema;

/**
 * Website/Store 等物理分片的声明式 Schema 族。
 *
 * 无参 {@see getTableSchemas()} 展开全部已注册分片，供 setup:upgrade 枚举；
 * 单站 provision 调用 {@see getTableSchemasForShard()}，不得在首次业务请求里 DDL。
 */
interface ShardSchemaFamilyProviderInterface extends SchemaProviderInterface
{
    public function getFamilyCode(): string;

    /**
     * Immutable schema generation for checkpoint versioning (shard:{family}).
     * Bump when declared TableSchema for any shard key changes.
     */
    public function getSchemaVersion(): string;

    /**
     * Stable representative schemas used for the immutable family checkpoint.
     *
     * The result must describe one canonical shard and must not depend on the
     * currently registered shard keys. Adding a shard expands DDL through
     * {@see getTableSchemas()} but does not create a new schema generation.
     *
     * @return list<TableSchema>
     */
    public function getSchemaCheckpointTableSchemas(): array;

    /**
     * @return list<string>
     */
    public function getRegisteredShardKeys(): array;

    /**
     * @return list<TableSchema>
     */
    public function getTableSchemasForShard(string $shardKey): array;

    /**
     * @return list<TableSchema>
     */
    public function getTableSchemas(): array;
}
