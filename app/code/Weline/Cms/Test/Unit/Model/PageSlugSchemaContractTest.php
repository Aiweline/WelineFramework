<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Framework\Database\Schema\SchemaParser;

final class PageSlugSchemaContractTest extends TestCase
{
    public function testPageDeclaresSlugStateFieldsWithoutReplacingLegacySlug(): void
    {
        $schema = (new SchemaParser())->parse(Page::class);
        self::assertNotNull($schema);
        $columns = [];
        foreach ($schema->columns as $column) {
            $columns[$column->name] = $column;
        }

        self::assertArrayHasKey(Page::schema_fields_SLUG, $columns);
        self::assertArrayHasKey(Page::schema_fields_SLUG_MODE, $columns);
        self::assertArrayHasKey(Page::schema_fields_SLUG_SOURCE_HASH, $columns);
        self::assertSame(16, $columns[Page::schema_fields_SLUG_MODE]->length);
        self::assertSame('', $columns[Page::schema_fields_SLUG_MODE]->default);
        self::assertSame(64, $columns[Page::schema_fields_SLUG_SOURCE_HASH]->length);
    }
}
