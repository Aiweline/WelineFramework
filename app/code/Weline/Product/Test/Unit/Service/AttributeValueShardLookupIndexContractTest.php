<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\Product\Service\ProductShardSchemaCatalog;

final class AttributeValueShardLookupIndexContractTest extends TestCase
{
    public function testSchemaVersionBumpedForLookupIndexes(): void
    {
        self::assertSame('4.10.0', ProductShardSchemaCatalog::SCHEMA_VERSION);
    }

    /**
     * AttributeValueRepository::loadRows()/purgeEntity() filter by entity without store_id;
     * the unique key leads with store_id (single value per shard in practice) and cannot serve them.
     */
    public function testEntityLookupIndexLeadsWithEntityColumns(): void
    {
        $columns = $this->indexColumns('idx_entity_attr');

        self::assertSame(['entity_type', 'entity_id', 'attribute_code'], $columns);
    }

    /**
     * findEntityIdsByAttributeValue() narrows by attribute before comparing value_text.
     */
    public function testAttributeReverseLookupIndexExcludesUnboundedText(): void
    {
        $columns = $this->indexColumns('idx_attr_store');

        self::assertSame(['entity_type', 'attribute_code', 'store_id'], $columns);
    }

    public function testNoIndexCoversUnboundedTextColumns(): void
    {
        foreach ($this->attributeValueSchema()->indexes as $index) {
            foreach (['value_text', 'value_string', 'value_json'] as $textColumn) {
                self::assertNotContains($textColumn, $index->columns, $index->name);
            }
        }
    }

    public function testUniqueKeyUnchanged(): void
    {
        self::assertSame(
            ['store_id', 'entity_type', 'entity_id', 'attribute_code', 'locale'],
            $this->indexColumns('uk_attr_store_locale'),
        );
    }

    /** @return list<string> */
    private function indexColumns(string $name): array
    {
        foreach ($this->attributeValueSchema()->indexes as $index) {
            if ($index->name === $name) {
                return $index->columns;
            }
        }
        self::fail('attribute_value index missing: ' . $name);
    }

    private function attributeValueSchema(): TableSchema
    {
        foreach ((new ProductShardSchemaCatalog())->schemasForShard('0') as $schema) {
            if (str_ends_with($schema->tableName, '_attribute_value')) {
                return $schema;
            }
        }
        self::fail('attribute_value schema missing');
    }
}
