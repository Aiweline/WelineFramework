<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Theme\Model\ThemeLayout;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllThemeModelContractsPass().
 */
final class ThemeModelContractsTest extends TestCase
{
    public function testAllThemeModelContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'ThemeLayoutSchema::testLayoutLookupIndexKeepsStoreScopeAndLocaleIdentity' => function () {
            $this->assert_ThemeLayoutSchema_testLayoutLookupIndexKeepsStoreScopeAndLocaleIdentity();
        },
        ];
    }

private function assert_ThemeLayoutSchema_testLayoutLookupIndexKeepsStoreScopeAndLocaleIdentity(): void
{
    $schema = (new SchemaParser())->parse(ThemeLayout::class);
    self::assertNotNull($schema);
    foreach ($schema->indexes as $index) {
        if (!$index instanceof IndexDefinition || $index->name !== 'idx_theme_layout_identity') {
            continue;
        }
        self::assertSame([ThemeLayout::schema_fields_THEME_ID, ThemeLayout::schema_fields_PAGE_TYPE, ThemeLayout::schema_fields_LAYOUT_OPTION, ThemeLayout::schema_fields_SCOPE, ThemeLayout::schema_fields_LOCALE_CODE, ThemeLayout::schema_fields_TARGET_TYPE, ThemeLayout::schema_fields_TARGET_ID, ThemeLayout::schema_fields_STATUS], $index->columns);
        return;
    }
    self::fail('Theme layout identity lookup index is missing.');
}
}
