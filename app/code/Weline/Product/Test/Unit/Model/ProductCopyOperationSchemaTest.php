<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Product\Model\ProductCopyOperation;

final class ProductCopyOperationSchemaTest extends TestCase
{
    public function testDurableDraftReceiptAndAuditSchema(): void
    {
        $schema = (new SchemaParser())->parse(ProductCopyOperation::class);
        self::assertNotNull($schema);
        self::assertStringEndsWith('product_copy_operation"', $schema->tableName);

        $columns = array_map(static fn ($column): string => $column->name, $schema->columns);
        foreach ([
            'draft_uuid',
            'state',
            'entry',
            'target_website_id',
            'target_store_id',
            'draft_json',
            'request_hash',
            'claim_token',
            'result_json',
            'error_code',
        ] as $column) {
            self::assertContains($column, $columns);
        }

        $indexes = array_map(static fn ($index): string => $index->name, $schema->indexes);
        self::assertContains('uk_product_copy_draft_uuid', $indexes);
        self::assertContains('idx_product_copy_target_state', $indexes);
    }
}
