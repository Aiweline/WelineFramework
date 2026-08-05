<?php

declare(strict_types=1);

namespace Weline\Search\Extends\Module\Weline_Framework\Schema;

use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderInterface;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Search\Api\SearchShardRegistryInterface;
use Weline\Search\Model\SearchShardKey;
use Weline\Search\Service\SearchShardRegistryStore;
use Weline\Search\Service\SearchShardSchemaCatalog;

/**
 * Search website shard family provider (familyCode=search.website).
 *
 * Unique path (hard rule):
 * Search/extends/module/Weline_Framework/Schema/SearchShardSchemaProvider.php
 */
final class SearchShardSchemaProvider implements ShardSchemaFamilyProviderInterface
{
    public function __construct(
        private readonly SearchShardRegistryInterface $registry,
        private readonly SearchShardSchemaCatalog $catalog,
    ) {
    }

    public static function forTesting(?SearchShardRegistryStore $registry = null): self
    {
        return new self(
            $registry ?? SearchShardRegistryStore::forTesting([0]),
            new SearchShardSchemaCatalog(),
        );
    }

    public function getFamilyCode(): string
    {
        return SearchShardKey::FAMILY_CODE;
    }

    public function getSchemaVersion(): string
    {
        return SearchShardSchemaCatalog::SCHEMA_VERSION;
    }

    public function getSchemaCheckpointTableSchemas(): array
    {
        return $this->catalog->schemasForShard(SearchShardKey::fromWebsiteId(0));
    }

    public function getRegisteredShardKeys(): array
    {
        return $this->registry->getRegisteredShardKeys();
    }

    public function getTableSchemasForShard(string $shardKey): array
    {
        return $this->catalog->schemasForShard($shardKey);
    }

    /**
     * @return list<TableSchema>
     */
    public function getTableSchemas(): array
    {
        $all = [];
        $seen = [];
        foreach ($this->getRegisteredShardKeys() as $shardKey) {
            foreach ($this->getTableSchemasForShard($shardKey) as $schema) {
                $name = $schema->tableName;
                if (isset($seen[$name])) {
                    throw new \RuntimeException(__('Search shard 重复声明表 %{1}', [$name]));
                }
                $seen[$name] = true;
                $all[] = $schema;
            }
        }

        return $all;
    }
}
