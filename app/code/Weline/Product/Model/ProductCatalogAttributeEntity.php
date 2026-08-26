<?php

declare(strict_types=1);

namespace Weline\Product\Model;

use Weline\Eav\Api\Attribute\EntityAttributeStoreInterface;
use Weline\Eav\Api\Entity\EntityDefinitionInterface;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface as SqlTableInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Runtime\RuntimeProviderResolver;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;

/**
 * EAV entity definition for storefront product catalog metadata (attribute sets).
 *
 * Values are keyed by durable catalog product_id from the Website shard.
 */
final class ProductCatalogAttributeEntity extends Model implements EntityDefinitionInterface
{
    public const entity_code = 'product';
    public const entity_name = '商品';
    public const eav_entity_id_field_type = SqlTableInterface::column_type_INTEGER;
    public const eav_entity_id_field_length = 11;

    public const schema_table = 'product_catalog_eav_entity';
    public const schema_primary_key = 'product_id';
    public const schema_fields_ID = 'product_id';

    public string $table = self::schema_table;
    public array $_unit_primary_keys = [self::schema_fields_ID];
    public array $_index_sort_keys = [self::schema_fields_ID];

    private ?EntityAttributeStoreInterface $attributeStore = null;

    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
        array $data = [],
    ) {
        parent::__construct($data);
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if (!$setup->tableExist(self::schema_table)) {
            $setup->createTable(self::schema_table)
                ->addColumn(
                    self::schema_fields_ID,
                    SqlTableInterface::column_type_INTEGER,
                    self::eav_entity_id_field_length,
                    'primary key auto_increment',
                    '商品 ID',
                )
                ->create();
        }
        $this->syncAttributeSequence();
        $this->store()->provisionValueTables($this, $setup);
    }

    public function syncAttributeSequence(): void
    {
        $this->store()->syncAttributeSequence();
    }

    public function getEntityCode(): string
    {
        return self::entity_code;
    }

    public function getEntityName(): string
    {
        return self::entity_name;
    }

    public function getEntityFieldIdType(): string
    {
        return self::eav_entity_id_field_type;
    }

    public function getEntityFieldIdLength(): int
    {
        return self::eav_entity_id_field_length;
    }

    private function store(): EntityAttributeStoreInterface
    {
        if ($this->attributeStore instanceof EntityAttributeStoreInterface) {
            return $this->attributeStore;
        }

        $store = $this->runtimeProviders->resolve(EntityAttributeStoreInterface::class);
        if (!$store instanceof EntityAttributeStoreInterface) {
            throw new \RuntimeException('product_catalog_eav_provider_unavailable');
        }

        return $this->attributeStore = $store;
    }
}
