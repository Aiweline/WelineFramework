<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\ColumnDefinition;
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
}
