<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\Schema\ColumnDefinition;
use Weline\Framework\Database\Schema\SchemaDiffEngine;
use Weline\Framework\Database\Schema\SchemaDiffOp;
use Weline\Framework\Database\Schema\SchemaMigrationExecutor;
use Weline\Framework\Database\Schema\TableSchema;

/** 仅使用本机 Unix socket，在独立测试库真实执行 SchemaDiff 生成的 MySQL DDL。 */
final class MysqlSchemaModifyPrimaryKeyTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $database = '';
    private Connector $connector;

    protected function setUp(): void
    {
        $socket = getenv('WELINE_TEST_MYSQL_SOCKET') ?: '/tmp/mysql.sock';
        if (!in_array('mysql', PDO::getAvailableDrivers(), true) || !file_exists($socket)) {
            self::markTestSkipped('需要本机 MySQL Unix socket；此测试不会连接远端数据库。');
        }
        $this->pdo = new PDO('mysql:unix_socket=' . $socket . ';charset=utf8mb4',
            getenv('WELINE_TEST_MYSQL_USER') ?: 'root', getenv('WELINE_TEST_MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->database = 'weline_schema_pk_test_' . bin2hex(random_bytes(6));
        $this->pdo->exec('CREATE DATABASE `' . $this->database . '`');
        $this->pdo->exec('USE `' . $this->database . '`');
        $this->connector = new Connector(new ConfigProvider(['type' => 'mysql', 'database' => $this->database, 'prefix' => '']));
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->database !== '') {
            $this->pdo->exec('DROP DATABASE `' . $this->database . '`');
        }
        $this->pdo = null;
    }

    public function testCommentOnlyPrimaryColumnDiffAndRollbackPreservePrimaryAndData(): void
    {
        $this->pdo->exec("CREATE TABLE message (message_row_id BIGINT NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '旧注释', body VARCHAR(30) NOT NULL)");
        $this->pdo->exec("INSERT INTO message (body) VALUES ('first')");
        $old = new ColumnDefinition('message_row_id', 'bigint', null, false, true, true, null, '旧注释');
        $new = new ColumnDefinition('message_row_id', 'bigint', null, false, true, true, null, '新注释');
        $body = new ColumnDefinition('body', 'varchar', 30, false);
        $ops = (new SchemaDiffEngine())->diff(
            new TableSchema('message', '', [$new, $body], [], [], null),
            new TableSchema('message', '', [$old, $body], [], [], null),
            'mysql',
        );
        self::assertCount(1, $ops);
        self::assertSame(SchemaDiffOp::KIND_MODIFY_COLUMN, $ops[0]->kind);
        $executor = (new \ReflectionClass(SchemaMigrationExecutor::class))->newInstanceWithoutConstructor();
        $forward = (new \ReflectionMethod($executor, 'buildDdl'))->invoke($executor, $this->connector, $ops[0]);
        $rollback = (new \ReflectionMethod($executor, 'buildRollbackDdl'))->invoke($executor, $this->connector, $ops[0]);
        // 直接执行生成结果，重复PRIMARY KEY会由真实MySQL报1068，而非仅搜索字符串。
        $this->pdo->exec($forward);
        self::assertSame('新注释', $this->column('message', 'message_row_id')['Comment']);
        self::assertSame('PRI', $this->column('message', 'message_row_id')['Key']);
        self::assertSame('NO', $this->column('message', 'message_row_id')['Null']);
        $this->pdo->exec("INSERT INTO message (body) VALUES ('second')");
        self::assertSame(['1', '2'], array_map('strval', $this->pdo->query('SELECT message_row_id FROM message ORDER BY message_row_id')->fetchAll(PDO::FETCH_COLUMN)));
        $this->pdo->exec($rollback);
        self::assertSame('旧注释', $this->column('message', 'message_row_id')['Comment']);
        self::assertSame('PRI', $this->column('message', 'message_row_id')['Key']);
    }

    public function testAddingPrimaryColumnStillCreatesPrimaryAndAutoIncrement(): void
    {
        $this->pdo->exec('CREATE TABLE fresh (body VARCHAR(30))');
        $col = new ColumnDefinition('id', 'bigint', null, false, true, true);
        $this->pdo->exec($this->connector->buildAlterAddColumnSql('fresh', get_object_vars($col)));
        $this->pdo->exec("INSERT INTO fresh (body) VALUES ('first')");
        self::assertSame('PRI', $this->column('fresh', 'id')['Key']);
        self::assertSame('1', (string)$this->pdo->query('SELECT id FROM fresh')->fetchColumn());
    }

    public function testPromotingExistingNonPrimaryColumnStillCreatesPrimary(): void
    {
        $this->pdo->exec('CREATE TABLE promoted (id BIGINT NOT NULL)');
        $this->pdo->exec('INSERT INTO promoted VALUES (7)');
        $old = new ColumnDefinition('id', 'bigint', null, false);
        $new = new ColumnDefinition('id', 'bigint', null, false, true);
        $this->pdo->exec($this->connector->buildAlterModifyColumnSql('promoted', get_object_vars($new), get_object_vars($old)));
        self::assertSame('PRI', $this->column('promoted', 'id')['Key']);
        self::assertSame('7', (string)$this->pdo->query('SELECT id FROM promoted')->fetchColumn());
    }

    public function testCompositePrimaryMemberModifyPreservesWholeConstraint(): void
    {
        $this->pdo->exec("CREATE TABLE composite_key (tenant INT NOT NULL, id INT NOT NULL, PRIMARY KEY (tenant,id))");
        $old = new ColumnDefinition('id', 'int', null, false, true);
        $new = new ColumnDefinition('id', 'bigint', null, false, true, false, null, '加宽成员');
        $this->pdo->exec($this->connector->buildAlterModifyColumnSql('composite_key', get_object_vars($new), get_object_vars($old)));
        $rows = $this->pdo->query("SHOW INDEX FROM composite_key WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame(['tenant', 'id'], array_column($rows, 'Column_name'));
        self::assertSame('bigint', $this->column('composite_key', 'id')['Type']);
    }

    private function column(string $table, string $column): array
    {
        foreach ($this->pdo->query('SHOW FULL COLUMNS FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['Field'] === $column) { return $row; }
        }
        self::fail('测试列不存在');
    }
}
