<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Repository;

use PHPUnit\Framework\TestCase;

final class CategoryDisplaySelectionRepositoryTest extends TestCase
{
    public function testRepositoryExposesScopeReplaceAndUpsertContracts(): void
    {
        $source = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Repository/CategoryDisplaySelectionRepository.php',
        );
        self::assertStringContainsString('function listForScope', $source);
        self::assertStringContainsString('function replaceScope', $source);
        self::assertStringContainsString('function upsert', $source);
        self::assertStringContainsString('schema_fields_ENABLED', $source);
        self::assertStringContainsString('schema_fields_POSITION', $source);
        self::assertStringContainsString('展示选择需要 store_id 或 channel_id', $source);
    }

    public function testShardModelAndSchemaDeclareDisplaySelectionEntity(): void
    {
        $model = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/Shard/CategoryDisplaySelection.php',
        );
        $schema = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/ProductShardSchemaCatalog.php',
        );
        $key = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Model/ProductShardKey.php',
        );
        self::assertStringContainsString("return 'category_display_selection';", $model);
        self::assertStringContainsString("'category_display_selection'", $key);
        self::assertStringContainsString("'category_display_selection' => new TableSchema", $schema);
        self::assertStringContainsString('uk_store_channel_category', $schema);
        self::assertStringContainsString("SCHEMA_VERSION = '4.9.0'", $schema);
    }
}
