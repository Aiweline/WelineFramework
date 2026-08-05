<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Model\Shard;

use PHPUnit\Framework\TestCase;
use Weline\Product\Model\ProductShardKey;
use Weline\Product\Model\Shard\AttributeValue;
use Weline\Product\Model\Shard\Media;
use Weline\Product\Model\Shard\Product;
use Weline\Product\Service\ProductShardSchemaCatalog;

/**
 * Shard model table binding + schema v3.1 catalog entities.
 */
final class WebsiteShardModelTest extends TestCase
{
    public function testProductTableBindsToWebsiteShard(): void
    {
        $model = new Product();
        $model->forWebsite(0);
        self::assertSame(0, $model->websiteId());
        self::assertSame('product', Product::entityCode());
        $logical = ProductShardKey::tableName(ProductShardKey::fromWebsiteId(0), 'product');
        self::assertSame('product_ws_0_product', $logical);
    }

    public function testNegativeWebsiteRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Media())->forWebsite(-1);
    }

    public function testSchemaVersionThreeEntities(): void
    {
        self::assertSame('3.1.1', ProductShardSchemaCatalog::SCHEMA_VERSION);
        self::assertContains('attribute_value', ProductShardSchemaCatalog::ENTITIES);
        self::assertContains('store_offer', ProductShardSchemaCatalog::ENTITIES);
        $catalog = new ProductShardSchemaCatalog();
        $schemas = $catalog->schemasForShard('0');
        self::assertCount(count(ProductShardSchemaCatalog::ENTITIES), $schemas);
        $attr = null;
        foreach ($schemas as $schema) {
            if (str_ends_with($schema->tableName, '_attribute_value')) {
                $attr = $schema;
                break;
            }
        }
        self::assertNotNull($attr);
        $cols = array_map(static fn ($c) => $c->name, $attr->columns);
        self::assertContains('store_id', $cols);
        self::assertContains('cleared', $cols);
        self::assertSame(AttributeValue::WEBSITE_STORE_ID, 0);

        $category = null;
        foreach ($schemas as $schema) {
            if (str_ends_with($schema->tableName, '_category')) {
                $category = $schema;
                break;
            }
        }
        self::assertNotNull($category);
        self::assertContains(
            'global_category_uuid',
            array_map(static fn ($column): string => $column->name, $category->columns),
        );
        self::assertContains(
            'uk_global_category_uuid',
            array_map(static fn ($index): string => $index->name, $category->indexes),
        );

        foreach (['product', 'offer', 'media'] as $entity) {
            $schema = null;
            foreach ($schemas as $candidate) {
                if (str_ends_with($candidate->tableName, '_' . $entity)) {
                    $schema = $candidate;
                    break;
                }
            }
            self::assertNotNull($schema);
            self::assertContains(
                'cas_token',
                array_map(static fn ($column): string => $column->name, $schema->columns),
            );
        }
    }
}
