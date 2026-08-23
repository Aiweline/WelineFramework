<?php

declare(strict_types=1);

namespace Weline\Cms\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Cms\Model\Page;
use Weline\Cms\Model\PageLocale;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;

final class PageLocaleSchemaContractTest extends TestCase
{
    public function testLocaleModelDeclaresColumnsAndCompositeUniqueIndex(): void
    {
        self::assertTrue(class_exists(PageLocale::class));

        $schema = (new SchemaParser())->parse(PageLocale::class);
        self::assertNotNull($schema);
        self::assertSame('weline_cms_page_locale', PageLocale::schema_table);
        self::assertSame('page_locale_id', PageLocale::schema_primary_key);

        $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
        foreach ([
            PageLocale::schema_fields_ID,
            PageLocale::schema_fields_PAGE_ID,
            PageLocale::schema_fields_STORE_ID,
            PageLocale::schema_fields_STORE_CODE,
            PageLocale::schema_fields_LOCALE_CODE,
            PageLocale::schema_fields_TITLE,
            PageLocale::schema_fields_ORIGIN,
            PageLocale::schema_fields_SOURCE_HASH,
            PageLocale::schema_fields_VARIANT_STATUS,
            PageLocale::schema_fields_TRANSLATION_STATE,
            PageLocale::schema_fields_VALIDATION_STATE,
            PageLocale::schema_fields_PUBLISHED_AT,
            PageLocale::schema_fields_VARIANT_REVISION,
            PageLocale::schema_fields_CREATED_AT,
            PageLocale::schema_fields_UPDATED_AT,
        ] as $field) {
            self::assertContains($field, $columnNames);
        }

        self::assertTrue($this->hasUniqueIndex(
            $schema->indexes,
            'uk_cms_page_locale_store_code',
            [
                PageLocale::schema_fields_PAGE_ID,
                PageLocale::schema_fields_STORE_ID,
                PageLocale::schema_fields_LOCALE_CODE,
            ],
        ));

        $module = require BP . 'app/code/Weline/Cms/etc/module.php';
        self::assertTrue(version_compare((string)$module['version'], '1.1.1', '>='));
    }

    public function testPageKeepsLegacyTitleAndAddsSourceLocale(): void
    {
        $schema = (new SchemaParser())->parse(Page::class);
        self::assertNotNull($schema);

        $columnNames = array_column(array_map('get_object_vars', $schema->columns), 'name');
        self::assertContains(Page::schema_fields_TITLE, $columnNames);
        self::assertContains(Page::schema_fields_SOURCE_LOCALE, $columnNames);
    }

    /** @param list<IndexDefinition> $indexes @param list<string> $columns */
    private function hasUniqueIndex(array $indexes, string $name, array $columns): bool
    {
        foreach ($indexes as $index) {
            if ($index->name === $name && $index->type === 'UNIQUE' && $index->columns === $columns) {
                return true;
            }
        }

        return false;
    }
}
