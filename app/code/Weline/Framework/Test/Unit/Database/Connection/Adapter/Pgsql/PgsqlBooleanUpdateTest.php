<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database\Connection\Adapter\Pgsql;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Compiler\PgsqlCompiler;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query;

final class PgsqlBooleanUpdateQuery extends Query
{
    protected function prepareSql(string $action): void
    {
        unset($action);
    }

    public function getLink(): PDO
    {
        throw new \RuntimeException('Database link is not needed for this unit test.');
    }

    public function buildUpdateForTest(string $table, string $wheres): string
    {
        return $this->buildUpdateForPgsql($table, $wheres);
    }
}

final class PgsqlBooleanUpdateTest extends TestCase
{
    public function testSingleRowUpdateBindsBooleanFalseAsPostgresFalse(): void
    {
        $query = new PgsqlBooleanUpdateQuery();
        $query->updates = [['is_anonymous' => false]];

        $query->buildUpdateForTest('w_weline_review_product', 'WHERE "review_id" = 5');

        self::assertSame('0', $query->bound_values[':' . md5('is_anonymous')] ?? null);
    }

    public function testSingleRowUpdateBindsBooleanTrueAsPostgresTrue(): void
    {
        $query = new PgsqlBooleanUpdateQuery();
        $query->updates = [['is_anonymous' => true]];

        $query->buildUpdateForTest('w_weline_review_product', 'WHERE "review_id" = 5');

        self::assertSame('1', $query->bound_values[':' . md5('is_anonymous')] ?? null);
    }

    public function testCompilerBindsBooleanFalseAsPostgresFalse(): void
    {
        $compiled = (new PgsqlCompiler())->compile(
            [
                'action' => 'update',
                'from' => ['table' => 'w_weline_review_product', 'alias' => 'main_table'],
                'where' => [['review_id', '=', 5]],
                'update' => ['single' => ['is_anonymous' => false], 'batch' => []],
                'dec_inc_updates' => [],
                'extra' => '',
            ],
            ['identity_field' => 'review_id', 'table_alias' => 'main_table'],
        );

        self::assertSame('0', $compiled->bindings[':up_' . md5('is_anonymous')] ?? null);
    }

    public function testCompilerBindsBooleanTrueAsPostgresTrue(): void
    {
        $compiled = (new PgsqlCompiler())->compile(
            [
                'action' => 'update',
                'from' => ['table' => 'w_weline_review_product', 'alias' => 'main_table'],
                'where' => [['review_id', '=', 5]],
                'update' => ['single' => ['is_anonymous' => true], 'batch' => []],
                'dec_inc_updates' => [],
                'extra' => '',
            ],
            ['identity_field' => 'review_id', 'table_alias' => 'main_table'],
        );

        self::assertSame('1', $compiled->bindings[':up_' . md5('is_anonymous')] ?? null);
    }
}
