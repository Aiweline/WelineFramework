<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Acl\Model\RoleAccess;
use Weline\Backend\Model\BackendUserConfig;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaParser;
use Weline\Framework\Database\Schema\TableSchema;
use Weline\I18n\Model\Countries\Locale\Name as CountriesLocaleName;
use Weline\I18n\Model\Locale\Name as LocaleName;
use Weline\I18n\Model\Locals;
use Weline\Meta\Model\MetaLocal;
use Weline\SystemConfig\Model\SystemConfig;
use Weline\Widget\Model\Widget\LocalDescription;

/**
 * Contract: production tables that only have UNIQUE (no PRIMARY) must emit
 * KIND_PROMOTE_PRIMARY_KEY when declared schema_primary_keys matches that UNIQUE set.
 *
 * Physical UNIQUE shapes were read from changanhanfu MySQL (2026-10-12).
 */
final class SchemaNoPrimaryPromoteContractTest extends TestCase
{
    /**
     * @return list<array{0: class-string, 1: string, 2: list<string>}>
     */
    public static function productionUniqueOnlyTables(): array
    {
        return [
            [BackendUserConfig::class, 'idx_user_key', ['user_id', 'key']],
            [CountriesLocaleName::class, 'uk_country_display_locale', ['country_code', 'display_locale_code']],
            [LocaleName::class, 'uk_locale_display_locale', ['locale_code', 'display_locale_code']],
            [Locals::class, 'idx_code_target', ['code', 'target_code']],
            [RoleAccess::class, 'uk_role_source', ['role_id', 'source_id']],
            // Physical UNIQUE column order differs from historical schema_primary_keys order.
            [SystemConfig::class, 'idx_system_config_identity', ['module', 'area', 'key', 'scope', 'locale']],
            [LocalDescription::class, 'pk_widget_locale', ['widget_identify', 'locale_code']],
        ];
    }

    public function testMetaLocalExpandsLegacyThreeColUniqueToFiveColPrimary(): void
    {
        $parser = new SchemaParser();
        $declared = $parser->parse(MetaLocal::class);
        self::assertInstanceOf(TableSchema::class, $declared);

        $declaredPks = [];
        foreach ($declared->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }
        self::assertSame(
            ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'],
            $declaredPks,
        );

        $actualColumns = [];
        foreach ($declared->columns as $column) {
            $actualColumns[] = new \Weline\Framework\Database\Schema\ColumnDefinition(
                name: $column->name,
                type: $column->type,
                length: $column->length,
                nullable: $column->nullable,
                primaryKey: false,
                autoIncrement: false,
                default: $column->default,
                comment: $column->comment,
                unique: $column->unique,
            );
        }
        $actual = new TableSchema(
            tableName: $declared->tableName,
            comment: $declared->comment,
            columns: $actualColumns,
            indexes: [
                new IndexDefinition(
                    'uk_meta_identify_locale_key',
                    ['meta_identify', 'locale_code', 'config_key'],
                    'UNIQUE',
                ),
            ],
            foreignKeys: [],
            modelClass: MetaLocal::class,
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual, 'mysql');
        $promote = array_values(array_filter(
            $ops,
            static fn (SchemaDiffOp $op): bool => $op->kind === SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY,
        ));
        self::assertCount(1, $promote);
        self::assertSame(['uk_meta_identify_locale_key'], $promote[0]->payload->replaceUniqueIndexNames);
        self::assertSame(
            ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'],
            $promote[0]->payload->columns,
        );
    }

    /**
     * @dataProvider productionUniqueOnlyTables
     * @param class-string $modelClass
     * @param list<string> $uniqueColumns
     */
    public function testDeclaredSchemaPromotesMatchingPhysicalUnique(
        string $modelClass,
        string $uniqueName,
        array $uniqueColumns,
    ): void {
        $parser = new SchemaParser();
        $declared = $parser->parse($modelClass);
        self::assertInstanceOf(TableSchema::class, $declared);

        $declaredPks = [];
        foreach ($declared->columns as $column) {
            if ($column->primaryKey) {
                $declaredPks[] = $column->name;
            }
        }
        sort($declaredPks);
        $expected = $uniqueColumns;
        sort($expected);
        self::assertSame($expected, $declaredPks, $modelClass . ' primaryKey flags must match physical UNIQUE set');

        $actualColumns = [];
        foreach ($declared->columns as $column) {
            $actualColumns[] = new \Weline\Framework\Database\Schema\ColumnDefinition(
                name: $column->name,
                type: $column->type,
                length: $column->length,
                nullable: $column->nullable,
                primaryKey: false,
                autoIncrement: false,
                default: $column->default,
                comment: $column->comment,
                unique: $column->unique,
            );
        }
        $actual = new TableSchema(
            tableName: $declared->tableName,
            comment: $declared->comment,
            columns: $actualColumns,
            indexes: [
                new IndexDefinition($uniqueName, $uniqueColumns, 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: $modelClass,
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual, 'mysql');
        $promote = array_values(array_filter(
            $ops,
            static fn (SchemaDiffOp $op): bool => $op->kind === SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY,
        ));
        self::assertCount(1, $promote, $modelClass . ' must emit exactly one promote_primary_key op');
        self::assertSame([$uniqueName], $promote[0]->payload->replaceUniqueIndexNames);
    }

    public function testUniqueColumnOrderMismatchStillPromotes(): void
    {
        $parser = new SchemaParser();
        $declared = $parser->parse(SystemConfig::class);
        self::assertNotNull($declared);

        $actualColumns = [];
        foreach ($declared->columns as $column) {
            $actualColumns[] = new \Weline\Framework\Database\Schema\ColumnDefinition(
                name: $column->name,
                type: $column->type,
                length: $column->length,
                nullable: $column->nullable,
                primaryKey: false,
                autoIncrement: false,
                default: $column->default,
                comment: $column->comment,
                unique: false,
            );
        }
        // Same set, reversed relative to a common historical declaration order.
        $actual = new TableSchema(
            tableName: $declared->tableName,
            comment: '',
            columns: $actualColumns,
            indexes: [
                new IndexDefinition(
                    'idx_system_config_identity',
                    ['key', 'module', 'area', 'scope', 'locale'],
                    'UNIQUE',
                ),
            ],
            foreignKeys: [],
            modelClass: SystemConfig::class,
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual, 'mysql');
        $kinds = array_map(static fn (SchemaDiffOp $op): string => $op->kind, $ops);
        self::assertContains(SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY, $kinds);
    }
}
