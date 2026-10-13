<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\PrimaryKeyPromotionDefinition;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

final class SchemaDiffEngineTest extends TestCase
{
    public function testUniqueOnlyTableEmitsPromotePrimaryKeyBeforeColumnModify(): void
    {
        $declared = new TableSchema(
            tableName: 'role_access',
            comment: '',
            columns: [
                new ColumnDefinition('role_id', 'int', null, false, true, false),
                new ColumnDefinition('source_id', 'varchar', 255, false, true, false),
                new ColumnDefinition('note', 'varchar', 64, true, false, false, null, '', false),
            ],
            indexes: [
                new IndexDefinition('uk_role_source', ['role_id', 'source_id'], 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: null
        );
        $actual = new TableSchema(
            tableName: 'role_access',
            comment: '',
            columns: [
                new ColumnDefinition('role_id', 'int', null, false, false, false),
                new ColumnDefinition('source_id', 'varchar', 255, false, false, false),
                new ColumnDefinition('note', 'varchar', 32, true, false, false),
            ],
            indexes: [
                new IndexDefinition('uk_role_source', ['role_id', 'source_id'], 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: null
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual, 'mysql');
        $kinds = array_map(static fn (SchemaDiffOp $op): string => $op->kind, $ops);

        self::assertContains(SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY, $kinds);
        self::assertContains(SchemaDiffOp::KIND_MODIFY_COLUMN, $kinds);
        self::assertNotContains(SchemaDiffOp::KIND_DROP_INDEX, $kinds);

        $promote = null;
        foreach ($ops as $op) {
            if ($op->kind === SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY) {
                $promote = $op;
                break;
            }
        }
        self::assertInstanceOf(SchemaDiffOp::class, $promote);
        self::assertInstanceOf(PrimaryKeyPromotionDefinition::class, $promote->payload);
        self::assertSame(['role_id', 'source_id'], $promote->payload->columns);
        self::assertSame(['uk_role_source'], $promote->payload->replaceUniqueIndexNames);
    }

    public function testMismatchedUniqueAndDeclaredPrimaryKeyFailsClosed(): void
    {
        $declared = new TableSchema(
            tableName: 'meta_local',
            comment: '',
            columns: [
                new ColumnDefinition('meta_id', 'int', null, false, true, false),
                new ColumnDefinition('meta_identify', 'varchar', 255, false, false, false),
                new ColumnDefinition('locale_code', 'varchar', 20, false, true, false),
            ],
            indexes: [],
            foreignKeys: [],
            modelClass: null
        );
        $actual = new TableSchema(
            tableName: 'meta_local',
            comment: '',
            columns: [
                new ColumnDefinition('meta_id', 'int', null, false, false, false),
                new ColumnDefinition('meta_identify', 'varchar', 255, false, false, false),
                new ColumnDefinition('locale_code', 'varchar', 20, false, false, false),
            ],
            indexes: [
                new IndexDefinition('uk_identify_locale', ['meta_identify', 'locale_code'], 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: null
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared primary key has no exact or subset UNIQUE match to promote');
        (new SchemaDiffEngine())->diff($declared, $actual, 'mysql');
    }

    public function testSubsetUniqueExpandsToDeclaredPrimaryKey(): void
    {
        $declared = new TableSchema(
            tableName: 'meta_local',
            comment: '',
            columns: [
                new ColumnDefinition('meta_identify', 'varchar', 255, false, true, false),
                new ColumnDefinition('locale_code', 'varchar', 20, false, true, false),
                new ColumnDefinition('config_key', 'varchar', 255, false, true, false),
                new ColumnDefinition('scope', 'varchar', 100, false, true, false, 'default'),
                new ColumnDefinition('version_id', 'int', null, false, true, false, 0),
            ],
            indexes: [
                new IndexDefinition(
                    'uk_meta_local_identify_locale_key_scope_version',
                    ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'],
                    'UNIQUE',
                ),
            ],
            foreignKeys: [],
            modelClass: null
        );
        $actual = new TableSchema(
            tableName: 'meta_local',
            comment: '',
            columns: [
                new ColumnDefinition('meta_identify', 'varchar', 255, false, false, false),
                new ColumnDefinition('locale_code', 'varchar', 20, false, false, false),
                new ColumnDefinition('config_key', 'varchar', 255, false, false, false),
                new ColumnDefinition('scope', 'varchar', 100, false, false, false, 'default'),
                new ColumnDefinition('version_id', 'int', null, false, false, false, 0),
            ],
            indexes: [
                new IndexDefinition(
                    'uk_meta_identify_locale_key',
                    ['meta_identify', 'locale_code', 'config_key'],
                    'UNIQUE',
                ),
            ],
            foreignKeys: [],
            modelClass: null
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual, 'mysql');
        $promote = null;
        foreach ($ops as $op) {
            if ($op->kind === SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY) {
                $promote = $op;
                break;
            }
        }
        self::assertInstanceOf(SchemaDiffOp::class, $promote);
        self::assertInstanceOf(PrimaryKeyPromotionDefinition::class, $promote->payload);
        self::assertSame(
            ['meta_identify', 'locale_code', 'config_key', 'scope', 'version_id'],
            $promote->payload->columns,
        );
        self::assertSame(['uk_meta_identify_locale_key'], $promote->payload->replaceUniqueIndexNames);
        self::assertNotContains(
            SchemaDiffOp::KIND_DROP_INDEX,
            array_map(static fn (SchemaDiffOp $op): string => $op->kind, $ops),
        );
    }

    public function testAlignedPhysicalAndDeclaredPrimaryKeyDoesNotRefuse(): void
    {
        $declared = new TableSchema(
            tableName: 'w_acl',
            comment: '',
            columns: [
                new ColumnDefinition('acl_id', 'int', null, false, true, true),
                new ColumnDefinition('source_id', 'varchar', 127, false, false, false, null, '', true),
            ],
            indexes: [
                new IndexDefinition('idx_source_id', ['source_id'], 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: null
        );
        $actual = new TableSchema(
            tableName: 'w_acl',
            comment: '',
            columns: [
                new ColumnDefinition('acl_id', 'int', null, false, true, true),
                new ColumnDefinition('source_id', 'varchar', 127, false, false, false),
            ],
            indexes: [
                new IndexDefinition('idx_source_id', ['source_id'], 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: null
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual, 'pgsql');
        $kinds = array_map(static fn (SchemaDiffOp $op): string => $op->kind, $ops);

        self::assertNotContains(SchemaDiffOp::KIND_PROMOTE_PRIMARY_KEY, $kinds);
        // Aligned PK must not throw refuse silent rewrite (covered by reaching here).
        self::assertIsArray($kinds);
    }

    public function testMismatchedPhysicalPrimaryKeyStillRefusesSilentRewrite(): void
    {
        $declared = new TableSchema(
            tableName: 'w_acl',
            comment: '',
            columns: [
                new ColumnDefinition('acl_id', 'int', null, false, false, true),
                new ColumnDefinition('source_id', 'varchar', 127, false, true, false, null, '', true),
            ],
            indexes: [
                new IndexDefinition('idx_source_id', ['source_id'], 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: null
        );
        $actual = new TableSchema(
            tableName: 'w_acl',
            comment: '',
            columns: [
                new ColumnDefinition('acl_id', 'int', null, false, true, true),
                new ColumnDefinition('source_id', 'varchar', 127, false, false, false),
            ],
            indexes: [
                new IndexDefinition('idx_source_id', ['source_id'], 'UNIQUE'),
            ],
            foreignKeys: [],
            modelClass: null
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('refuse silent rewrite');
        (new SchemaDiffEngine())->diff($declared, $actual, 'pgsql');
    }

    public function testSqliteEquivalentColumnMetadataDoesNotTriggerModify(): void
    {
        $declared = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [
                new ColumnDefinition('id', 'int', 0, false, true, true),
                new ColumnDefinition('status', 'varchar', 20, true, false, false, 'active', 'Status', true),
                new ColumnDefinition('price', 'decimal', '10,6', true, false, false, 0),
            ],
            indexes: [],
            foreignKeys: [],
            modelClass: null
        );
        $actual = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [
                new ColumnDefinition('id', 'integer', null, false, true, true),
                new ColumnDefinition('status', 'varchar', 20, true, false, false, 'active'),
                new ColumnDefinition('price', 'decimal', '10,6', true, false, false, '0'),
            ],
            indexes: [],
            foreignKeys: [],
            modelClass: null
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual);
        $modifyOps = array_filter(
            $ops,
            static fn (SchemaDiffOp $op): bool => $op->kind === SchemaDiffOp::KIND_MODIFY_COLUMN
        );

        self::assertSame([], array_values($modifyOps));
    }
}
