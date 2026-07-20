<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;

final class SqliteAlterTableTest extends TestCase
{
    private ?string $dbPath = null;
    private ?Connector $connector = null;

    protected function tearDown(): void
    {
        if ($this->connector !== null) {
            $this->connector->close();
            $this->connector = null;
        }
        if ($this->dbPath !== null && is_file($this->dbPath)) {
            @unlink($this->dbPath);
        }
        $this->dbPath = null;

        parent::tearDown();
    }

    public function testSqliteAlterAddsMultipleColumnsIndividually(): void
    {
        $this->connector = $this->createConnector();

        $this->connector->query('CREATE TABLE demo (id integer primary key autoincrement)')->fetch();

        $alter = $this->connector->alterTable()->forTable('demo', 'id', '');
        $alter->addColumn('balance', '', TableInterface::column_type_DECIMAL, '12,4', 'NOT NULL DEFAULT 0.0000', '');
        $alter->addColumn('currency', '', TableInterface::column_type_VARCHAR, '10', "NOT NULL DEFAULT 'CNY'", '');
        $alter->alter();

        $columns = array_column($this->connector->query("PRAGMA table_info('demo')")->fetch(), 'name');

        self::assertSame(['id', 'balance', 'currency'], $columns);
    }

    public function testSqliteFetchIteratorStreamsRowsInBatches(): void
    {
        $this->connector = $this->createConnector();

        $this->connector->query('CREATE TABLE demo (id integer primary key autoincrement, name varchar(32))')->fetch();
        $this->connector->query("INSERT INTO demo (name) VALUES ('first')")->fetch();
        $this->connector->query("INSERT INTO demo (name) VALUES ('second')")->fetch();
        $this->connector->query("INSERT INTO demo (name) VALUES ('third')")->fetch();

        $rows = [];
        foreach ($this->connector->query('SELECT id, name FROM demo ORDER BY id')->fetchIterator('', 2) as $batch) {
            $rows = array_merge($rows, $batch);
        }

        self::assertSame(['first', 'second', 'third'], array_column($rows, 'name'));
    }

    public function testSqliteBigintAutoIncrementModifyRebuildsAsIntegerAndPreservesData(): void
    {
        $this->connector = $this->createConnector();
        $this->connector->query(
            'CREATE TABLE demo (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(32) NOT NULL)'
        )->fetch();
        $this->connector->query('CREATE INDEX idx_demo_name ON demo (name)')->fetch();
        $this->connector->query("INSERT INTO demo (name) VALUES ('kept')")->fetch();

        $ddl = $this->connector->buildAlterModifyColumnSql('demo', [
            'name' => 'id',
            'type' => 'bigint',
            'length' => null,
            'nullable' => false,
            'primaryKey' => true,
            'autoIncrement' => true,
            'default' => null,
            'unique' => true,
        ]);

        self::assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $ddl);
        self::assertStringNotContainsString('BIGINT PRIMARY KEY AUTOINCREMENT', $ddl);
        $this->executeSqliteRebuild($ddl);

        $column = $this->connector->getTableColumns('demo')[0] ?? [];
        self::assertSame('integer', $column['type'] ?? null);
        self::assertTrue((bool)($column['primary_key'] ?? false));
        self::assertTrue((bool)($column['auto_increment'] ?? false));
        self::assertSame('kept', $this->connector->query('SELECT name FROM demo WHERE id = 1')->fetch()[0]['name'] ?? null);
        self::assertTrue($this->connector->hasIndex('demo', 'idx_demo_name'));
    }

    private function executeSqliteRebuild(string $ddl): void
    {
        $ddl = str_replace('/* WELINE_SQLITE_REBUILD */', '', $ddl);
        $this->connector->query('PRAGMA foreign_keys=OFF')->fetch();
        $this->connector->beginTransaction();
        try {
            foreach (explode("\n-- WELINE_DDL_STATEMENT\n", $ddl) as $statement) {
                if (trim($statement) !== '') {
                    $this->connector->query($statement)->fetch();
                }
            }
            $this->connector->commit();
        } catch (\Throwable $e) {
            $this->connector->rollBack();
            throw $e;
        } finally {
            $this->connector->query('PRAGMA foreign_keys=ON')->fetch();
        }
    }

    private function createConnector(): Connector
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is not available.');
        }

        if (!defined('IS_WIN')) {
            define('IS_WIN', PHP_OS_FAMILY === 'Windows');
        }
        if (!defined('PHP_CS')) {
            define('PHP_CS', false);
        }

        $this->dbPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'weline_sqlite_alter_' . uniqid('', true) . '.sqlite';
        return new Connector(new ConfigProvider([
            'type' => 'sqlite',
            'database' => '',
            'path' => $this->dbPath,
            'persistent' => false,
        ]));
    }
}
