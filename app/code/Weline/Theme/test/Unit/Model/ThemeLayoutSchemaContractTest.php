<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Theme\Model\ThemeLayout;

final class ThemeLayoutSchemaContractTest extends TestCase
{
    public function testLayoutLookupIndexKeepsStoreScopeAndLocaleIdentity(): void
    {
        $schema = (new SchemaParser())->parse(ThemeLayout::class);
        self::assertNotNull($schema);

        foreach ($schema->indexes as $index) {
            if (!$index instanceof IndexDefinition || $index->name !== 'idx_theme_layout_identity') {
                continue;
            }

            self::assertSame([
                ThemeLayout::schema_fields_THEME_ID,
                ThemeLayout::schema_fields_PAGE_TYPE,
                ThemeLayout::schema_fields_LAYOUT_OPTION,
                ThemeLayout::schema_fields_SCOPE,
                ThemeLayout::schema_fields_LOCALE_CODE,
                ThemeLayout::schema_fields_TARGET_TYPE,
                ThemeLayout::schema_fields_TARGET_ID,
                ThemeLayout::schema_fields_STATUS,
            ], $index->columns);

            return;
        }

        self::fail('Theme layout identity lookup index is missing.');
    }
}
