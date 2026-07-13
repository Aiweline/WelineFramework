<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Database\Compiler\MysqlCompiler;
use Weline\Framework\Database\Compiler\PgsqlCompiler;
use Weline\Framework\Database\Compiler\SqliteCompiler;
use Weline\Framework\Database\Connection\Adapter\Mysql\Query as MysqlQuery;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Query as PgsqlQuery;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector as SqliteConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Query as SqliteQuery;
use Weline\Framework\Database\Connection\Api\Sql\QueryAst;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;

final class FakePdoStatement extends \PDOStatement
{
    public function __construct()
    {
    }
}

final class FakePdo extends PDO
{
    public function __construct()
    {
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return new FakePdoStatement();
    }

    public function exec(string $statement): int|false
    {
        return 0;
    }

    public function errorInfo(): array
    {
        return ['00000', null, null];
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        return "'" . addslashes($string) . "'";
    }
}

final class CompilerTestQuery extends QueryAst
{
    public function getLink(): PDO
    {
        return new FakePdo();
    }

    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        return null;
    }

    public function rebuildAst(string $action = 'select'): void
    {
        $this->buildAst($action);
    }

    protected function prepareSql(string $action): void
    {
    }
}

final class MysqlAdapterTestQuery extends MysqlQuery
{
    private ?PDO $pdo = null;

    public function getLink(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new FakePdo();
        }

        return $this->pdo;
    }

    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        return null;
    }
}

final class PgsqlAdapterTestQuery extends PgsqlQuery
{
    private ?PDO $pdo = null;

    public function getLink(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new FakePdo();
        }

        return $this->pdo;
    }

    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        return null;
    }
}

final class SqliteAdapterTestQuery extends SqliteQuery
{
    private ?PDO $pdo = null;

    public function getLink(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new FakePdo();
        }

        return $this->pdo;
    }

    public function getColumnDefinition(string $tableName, string $fieldName): ?array
    {
        return null;
    }
}

final class DatabaseAstCompilerRegressionTest extends TestCase
{
    public function testQueryAstKeepsSubqueryStateWhenAliasChanges(): void
    {
        $subquery = new CompilerTestQuery();
        $subquery->table('orders')->fields('orders.id');

        $query = new CompilerTestQuery();
        $query->fromSubquery($subquery, 'derived');
        $query->alias('renamed');
        $query->rebuildAst('select');

        $ast = $query->getAst();
        $this->assertSame('', $ast['from']['table']);
        $this->assertTrue($ast['from']['is_subquery']);
        $this->assertSame('renamed', $ast['from']['alias']);
        $this->assertSame('renamed.*', $ast['select']['fields']);
    }

    public function testQueryAstAliasUpdatesWildcardForPreviousAlias(): void
    {
        $query = new CompilerTestQuery();
        $query->table('users')->alias('u');
        $query->alias('users_alias');
        $query->rebuildAst('select');

        $this->assertSame('users_alias.*', $query->fields);
        $this->assertSame('users_alias.*', $query->getAst()['select']['fields']);
    }

    public function testMysqlCompilerCompilesNestedSubqueriesAndRenamesBindings(): void
    {
        $fromSubquery = new CompilerTestQuery();
        $fromSubquery->table('orders')
            ->fields('orders.user_id')
            ->where('orders.state', 'open');

        $whereSubquery = new CompilerTestQuery();
        $whereSubquery->table('vip_users')
            ->fields('vip_users.id')
            ->where('vip_users.level', 'gold');

        $query = new CompilerTestQuery();
        $query->fromSubquery($fromSubquery, 'derived')
            ->fields('derived.user_id')
            ->where('derived.user_id', $whereSubquery, 'IN')
            ->where('derived.region', 'cn');
        $query->rebuildAst('select');

        $compiled = (new MysqlCompiler())->compile($query->getAst(), [
            'table_alias' => $query->table_alias,
        ]);

        $sql = strtoupper($compiled->sql);
        $this->assertStringContainsString('FROM (SELECT', $sql);
        $this->assertStringContainsString(' IN (SELECT ', $sql);
        $this->assertCount(3, $compiled->bindings);
        $this->assertCount(3, array_unique(array_keys($compiled->bindings)));
        $this->assertContains('cn', $compiled->bindings);
        $this->assertMatchesRegularExpression('/\:sq_from_subquery_1_/', $compiled->sql);
        $this->assertMatchesRegularExpression('/\:sq_where_0_subquery_2_/', $compiled->sql);
    }

    public function testFindInSetUsesDialectSpecificSql(): void
    {
        $query = new CompilerTestQuery();
        $query->table('users')->where('users.tags', 'vip', 'find_in_set');
        $query->rebuildAst('select');
        $ast = $query->getAst();

        $mysqlSql = (new MysqlCompiler())->compile($ast)->sql;
        $pgsqlSql = (new PgsqlCompiler())->compile($ast)->sql;
        $sqliteSql = (new SqliteCompiler())->compile($ast)->sql;

        $this->assertStringContainsString('FIND_IN_SET', strtoupper($mysqlSql));
        $this->assertStringContainsString('POSITION', strtoupper($pgsqlSql));
        $this->assertStringContainsString('INSTR', strtoupper($sqliteSql));
        $this->assertStringNotContainsString(' USERS.TAGS FIND_IN_SET ', strtoupper($mysqlSql));
        $this->assertStringNotContainsString(' USERS.TAGS FIND_IN_SET ', strtoupper($pgsqlSql));
        $this->assertStringNotContainsString(' USERS.TAGS FIND_IN_SET ', strtoupper($sqliteSql));
    }

    public function testMysqlCompilerExpandsExistUpdateAllFields(): void
    {
        $query = new CompilerTestQuery();
        $query->table('users')->insert([
            'id' => 1,
            'email' => 'alice@example.com',
            'name' => 'Alice',
        ], ['email']);
        $query->rebuildAst('insert');

        $compiled = (new MysqlCompiler())->compile($query->getAst(), [
            'identity_field' => $query->identity_field,
            'table_alias' => $query->table_alias,
            'exist_update_sql' => $query->exist_update_sql,
            'insert_update_fields' => $query->insert_update_fields,
            'insert_update_where_fields' => $query->insert_update_where_fields,
        ]);

        $this->assertStringContainsString('`users`', $compiled->sql);
        $this->assertStringContainsString('ON DUPLICATE KEY UPDATE', $compiled->sql);
        $this->assertStringContainsString('`name`=VALUES(`name`)', $compiled->sql);
        $this->assertStringNotContainsString(QueryInterface::EXIST_UPDATE_ALL_FIELDS, $compiled->sql);
    }

    public function testPgsqlCompilerCompilesBatchInsertWithoutLosingRows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 128; $i++) {
            $rows[] = [
                'id' => $i,
                'email' => "user{$i}@example.com",
                'name' => "User {$i}",
            ];
        }

        $query = new CompilerTestQuery();
        $query->table('users')->insert($rows);
        $query->rebuildAst('insert');

        $compiled = (new PgsqlCompiler())->compile($query->getAst(), [
            'identity_field' => $query->identity_field,
            'table_alias' => $query->table_alias,
            'exist_update_sql' => $query->exist_update_sql,
            'insert_update_fields' => $query->insert_update_fields,
            'insert_update_where_fields' => $query->insert_update_where_fields,
        ]);

        $lastEmailPlaceholder = ':' . md5('insert_email_field_128');

        $this->assertStringContainsString('"users"', $compiled->sql);
        $this->assertStringContainsString('VALUES', strtoupper($compiled->sql));
        $this->assertStringContainsString($lastEmailPlaceholder, $compiled->sql);
        $this->assertArrayHasKey($lastEmailPlaceholder, $compiled->bindings);
        $this->assertSame('user128@example.com', $compiled->bindings[$lastEmailPlaceholder]);
        $this->assertCount(count($rows) * 3, $compiled->bindings);
    }

    public function testPgsqlCompilerQuotesGroupByFieldsForAggregateSelects(): void
    {
        $query = new CompilerTestQuery();
        $query->table('eav_product_select_option')
            ->fields(['value', 'COUNT(DISTINCT entity_id) as count'])
            ->where('attribute_id', 8)
            ->group('value');
        $query->rebuildAst('select');

        $compiled = (new PgsqlCompiler())->compile($query->getAst(), [
            'table_alias' => $query->table_alias,
        ]);

        $this->assertStringContainsString('GROUP BY "value"', $compiled->sql);
        $this->assertStringContainsString('"value", COUNT(DISTINCT entity_id) AS "count"', $compiled->sql);
    }

    /**
     * @dataProvider adapterQueryProvider
     */
    public function testAdapterPrepareSqlSupportsFromSubquery(string $queryClass): void
    {
        /** @var MysqlAdapterTestQuery|PgsqlAdapterTestQuery|SqliteAdapterTestQuery $subquery */
        $subquery = new $queryClass();
        $subquery->table('orders')->fields('orders.id')->where('orders.state', 'open');

        /** @var MysqlAdapterTestQuery|PgsqlAdapterTestQuery|SqliteAdapterTestQuery $query */
        $query = new $queryClass();
        $query->fromSubquery($subquery, 'derived')->select();

        $sql = strtoupper($query->sql);
        $this->assertStringContainsString('FROM (SELECT', $sql);
        $this->assertStringContainsString('DERIVED', $sql);
    }

    public function testSqliteTableCommentIsAnExplicitNoOp(): void
    {
        $reflection = new ReflectionClass(SqliteConnector::class);
        /** @var SqliteConnector $connector */
        $connector = $reflection->newInstanceWithoutConstructor();

        $this->assertSame('', $connector->buildAlterTableCommentSql('users', 'comment'));
    }

    public function testSqliteConnectorIsEnabledAndSelfReferencesLikePgsql(): void
    {
        $connector = new SqliteConnector(new ConfigProvider([
            'type' => 'sqlite',
            'path' => ':memory:',
            'prefix' => '',
        ]));

        $this->assertSame($connector, $connector->getConnector());
        $this->assertSame($connector, $connector->getConnection());
        $this->assertSame($connector, $connector->getQuery());
    }

    public function testSqliteConnectorExecutesBasicPdoFlowWhenDriverAvailable(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_sqlite is not enabled.');
        }

        $connector = new SqliteConnector(new ConfigProvider([
            'type' => 'sqlite',
            'path' => ':memory:',
            'prefix' => '',
        ]));

        $connector->query('CREATE TABLE weline_sqlite_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)')->fetch();
        $connector->query("INSERT INTO weline_sqlite_probe (name) VALUES ('ok')")->fetch();

        $this->assertSame(
            [['name' => 'ok']],
            $connector->query('SELECT name FROM weline_sqlite_probe ORDER BY id DESC LIMIT 1')->fetch()
        );

        $connector->close();
    }

    public static function adapterQueryProvider(): array
    {
        return [
            'mysql' => [MysqlAdapterTestQuery::class],
            'pgsql' => [PgsqlAdapterTestQuery::class],
            'sqlite' => [SqliteAdapterTestQuery::class],
        ];
    }

}
