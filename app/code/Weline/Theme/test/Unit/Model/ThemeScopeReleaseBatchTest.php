<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Model\ThemeScopeReleaseBatch;

final class ThemeScopeReleaseBatchTest extends TestCase
{
    public function testBatchReceiptSchemaExposesCommittedAndCacheDegradedStates(): void
    {
        self::assertSame('theme_scope_release_batch', ThemeScopeReleaseBatch::schema_table);
        self::assertSame('batch_id', ThemeScopeReleaseBatch::schema_primary_key);
        self::assertSame('published', ThemeScopeReleaseBatch::STATE_PUBLISHED);
        self::assertSame('published_cache_degraded', ThemeScopeReleaseBatch::STATE_PUBLISHED_CACHE_DEGRADED);
        self::assertSame('receipt_json', ThemeScopeReleaseBatch::schema_fields_RECEIPT_JSON);
        self::assertSame('source_batch_id', ThemeScopeReleaseBatch::schema_fields_SOURCE_BATCH_ID);
    }

    public function testReceiptJsonIsReadBackAsAnArray(): void
    {
        $batch = new ThemeScopeReleaseBatch();
        $batch->setData(ThemeScopeReleaseBatch::schema_fields_RECEIPT_JSON, '{"resources":[{"resource_type":"layout"}]}');

        self::assertSame(
            ['resources' => [['resource_type' => 'layout']]],
            $batch->receipt(),
        );
    }
}
