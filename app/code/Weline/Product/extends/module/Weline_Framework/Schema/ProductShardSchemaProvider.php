<?php

declare(strict_types=1);

namespace Weline\Product\Extends\Module\Weline_Framework\Schema;

use Weline\Framework\Database\Schema\Shard\ShardSchemaFamilyProviderInterface;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\ProductShardRegistry;
use Weline\Product\Service\ProductShardSchemaCatalog;

/**
 * Product website shard family provider (familyCode=product.website).
 */
final class ProductShardSchemaProvider implements ShardSchemaFamilyProviderInterface
{
    public function __construct(
        private readonly ProductShardRegistry $registry,
        private readonly ProductShardSchemaCatalog $catalog,
    ) {
    }

    public function getFamilyCode(): string
    {
        return ProductShardKey::FAMILY_CODE;
    }

    public function getSchemaVersion(): string
    {
        return ProductShardSchemaCatalog::SCHEMA_VERSION;
    }

    public function getSchemaCheckpointTableSchemas(): array
    {
        return $this->catalog->schemasForShard(ProductShardKey::fromWebsiteId(0));
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
                    throw new \RuntimeException(__(
                        'Product shard 重复声明表 %{1}',
                        [$name],
                    ));
                }
                $seen[$name] = true;
                $all[] = $schema;
            }
        }
        return $all;
    }
}
