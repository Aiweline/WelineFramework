<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\IndexDefinition;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

final class SchemaDiffEngineTest extends TestCase
{
    public function testDestructiveOperationsDropDependenciesBeforeColumn(): void
    {
        $priority = (new \ReflectionClass(SchemaMigrationExecutor::class))->getConstant('KIND_PRIORITY');

        self::assertIsArray($priority);
        self::assertLessThan(
            $priority[SchemaDiffOp::KIND_DROP_COLUMN],
            $priority[SchemaDiffOp::KIND_DROP_FOREIGN_KEY],
        );
        self::assertLessThan(
            $priority[SchemaDiffOp::KIND_DROP_COLUMN],
            $priority[SchemaDiffOp::KIND_DROP_INDEX],
        );
        self::assertLessThan(
            $priority[SchemaDiffOp::KIND_ADD_INDEX],
            $priority[SchemaDiffOp::KIND_DROP_INDEX],
        );
        self::assertLessThan(
            $priority[SchemaDiffOp::KIND_ADD_FOREIGN_KEY],
            $priority[SchemaDiffOp::KIND_DROP_FOREIGN_KEY],
        );
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

    public function testSqliteBigintAutoIncrementPrimaryKeyUsesIntegerAffinityWithoutDiff(): void
    {
        $declared = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [new ColumnDefinition('id', 'bigint', null, false, true, true)],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );
        $actual = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [new ColumnDefinition('id', 'integer', null, false, true, true)],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );

        $ops = (new SchemaDiffEngine())->diff($declared, $actual, 'sqlite');

        self::assertSame([], $ops);
        self::assertNotSame([], (new SchemaDiffEngine())->diff($declared, $actual, 'mysql'));
    }

    public function testMysqlTextFamilyNeverNarrowsWiderPhysicalColumn(): void
    {
        $table = static fn (string $type): TableSchema => new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [new ColumnDefinition('payload', $type, null, true, false, false, null, 'Payload')],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );
        $engine = new SchemaDiffEngine();

        self::assertSame([], $engine->diff($table('text'), $table('longtext'), 'mysql'));
        self::assertSame([], $engine->diff($table('blob'), $table('mediumblob'), 'mysql'));
        $widen = $engine->diff($table('longtext'), $table('text'), 'mysql');
        self::assertCount(1, $widen);
        self::assertSame(SchemaDiffOp::KIND_MODIFY_COLUMN, $widen[0]->kind);
        self::assertNotSame([], $engine->diff($table('varchar'), $table('longtext'), 'mysql'));
    }

    public function testSqliteTableCommentDoesNotCreatePermanentDiff(): void
    {
        $declared = new TableSchema(
            tableName: 'demo',
            comment: 'Logical model comment',
            columns: [new ColumnDefinition('id', 'int', null, false, true, true)],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );
        $actual = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [new ColumnDefinition('id', 'integer', null, false, true, true)],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );

        self::assertSame([], (new SchemaDiffEngine())->diff($declared, $actual, 'sqlite'));
        self::assertSame(
            SchemaDiffOp::KIND_MODIFY_TABLE_COMMENT,
            (new SchemaDiffEngine())->diff($declared, $actual, 'mysql')[0]->kind,
        );
    }

    public function testUniqueIndexMirrorDoesNotCreateColumnModifyLoop(): void
    {
        $declared = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [new ColumnDefinition('operation_id', 'varchar', 64, false, false, false, null, '', false)],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );
        $actual = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [new ColumnDefinition('operation_id', 'varchar', 64, false, false, false, null, '', true)],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );

        self::assertSame([], (new SchemaDiffEngine())->diff($declared, $actual, 'sqlite'));
    }

    public function testIndexDependingOnAddedColumnIsEmittedInTheSameDiff(): void
    {
        $declared = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [
                new ColumnDefinition('id', 'int', null, false, true, true),
                new ColumnDefinition('client_request_id', 'varchar', 64, false),
            ],
            indexes: [
                new IndexDefinition(
                    'idx_demo_client_request_unique',
                    ['id', 'client_request_id'],
                    'UNIQUE',
                ),
            ],
            foreignKeys: [],
            modelClass: null,
        );
        $actual = new TableSchema(
            tableName: 'demo',
            comment: '',
            columns: [new ColumnDefinition('id', 'int', null, false, true, true)],
            indexes: [],
            foreignKeys: [],
            modelClass: null,
        );

        $operations = (new SchemaDiffEngine())->diff($declared, $actual);

        self::assertSame(
            [SchemaDiffOp::KIND_ADD_COLUMN, SchemaDiffOp::KIND_ADD_INDEX],
            array_map(static fn (SchemaDiffOp $operation): string => $operation->kind, $operations),
        );
        self::assertSame('idx_demo_client_request_unique', $operations[1]->payload->name);
    }

    public function testSameNameIndexDefinitionDriftEmitsDropThenAdd(): void
    {
        $declared = new TableSchema(
            tableName: 'w_shipping_service_regions',
            comment: '',
            columns: [
                new ColumnDefinition('service_id', 'int', null, false),
                new ColumnDefinition('region_type', 'varchar', 16, false),
                new ColumnDefinition('country_code', 'varchar', 2, false),
                new ColumnDefinition('region_id', 'int', null, true),
                new ColumnDefinition('region_code', 'varchar', 96, true),
            ],
            indexes: [
                new IndexDefinition(
                    'uk_service_lane_region',
                    ['service_id', 'region_type', 'country_code', 'region_id', 'region_code'],
                    'UNIQUE',
                ),
            ],
            foreignKeys: [],
            modelClass: null,
        );
        $actual = new TableSchema(
            tableName: 'w_shipping_service_regions',
            comment: '',
            columns: [
                new ColumnDefinition('service_id', 'int', null, false),
                new ColumnDefinition('region_type', 'varchar', 16, false),
                new ColumnDefinition('country_code', 'varchar', 2, false),
                new ColumnDefinition('region_id', 'int', null, true),
                new ColumnDefinition('region_code', 'varchar', 96, true),
            ],
            indexes: [
                new IndexDefinition(
                    'uk_service_lane_region',
                    ['service_id', 'region_type', 'country_code', 'COALESCE(region_id, 0)', "COALESCE(region_code, ''::character varying)"],
                    'UNIQUE',
                ),
            ],
            foreignKeys: [],
            modelClass: null,
        );

        $operations = (new SchemaDiffEngine())->diff($declared, $actual, 'pgsql');

        self::assertSame(
            [SchemaDiffOp::KIND_DROP_INDEX, SchemaDiffOp::KIND_ADD_INDEX],
            array_map(static fn (SchemaDiffOp $operation): string => $operation->kind, $operations),
        );
        self::assertSame('uk_service_lane_region', $operations[0]->payload->name);
        self::assertSame(
            ['service_id', 'region_type', 'country_code', 'region_id', 'region_code'],
            $operations[1]->payload->columns,
        );
    }
}
