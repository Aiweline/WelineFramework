<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\TableSchema;

final class SchemaDiffEngineTest extends TestCase
{
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
