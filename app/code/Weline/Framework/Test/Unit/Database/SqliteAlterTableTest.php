<?php

declare(strict_types=1);

namespace Weline\Framework\Database\Test\Unit;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
\defined('DS') || \define('DS', \DIRECTORY_SEPARATOR);
\defined('APP_PATH') || \define('APP_PATH', BP . 'app' . DS);
\defined('APP_ETC_PATH') || \define('APP_ETC_PATH', APP_PATH . 'etc' . DS);
\defined('APP_CODE_PATH') || \define('APP_CODE_PATH', APP_PATH . 'code' . DS);
\defined('VENDOR_PATH') || \define('VENDOR_PATH', BP . 'vendor' . DS);
\defined('PUB') || \define('PUB', BP . 'pub' . DS);
\defined('DEV') || \define('DEV', false);
\defined('DEBUG') || \define('DEBUG', false);
\defined('SANDBOX') || \define('SANDBOX', false);
require_once APP_CODE_PATH . 'Weline/Framework/Common/functions.php';

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

    public function testSqliteModifySecondPrimaryKeyCollapsesToCompositeTableKey(): void
    {
        $this->connector = $this->createConnector();
        $this->connector->query(
            'CREATE TABLE eav_attribute_local_description('
            . '`local_code` varchar(20) NOT NULL,'
            . '`name` varchar(255),'
            . '`id` integer primary key AUTOINCREMENT,'
            . '`create_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . '`update_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' UNIQUE (`local_code`,`id`)'
            . ')'
        )->fetch();
        $this->connector->query(
            "INSERT INTO eav_attribute_local_description (local_code, name) VALUES ('bn_IN', 'kept')"
        )->fetch();

        $ddl = $this->connector->buildAlterModifyColumnSql('eav_attribute_local_description', [
            'name' => 'local_code',
            'type' => 'varchar',
            'length' => 20,
            'nullable' => false,
            'primaryKey' => true,
            'autoIncrement' => false,
            'default' => null,
            'unique' => false,
        ]);

        self::assertStringContainsString('PRIMARY KEY ("id", "local_code")', $ddl);
        self::assertDoesNotMatchRegularExpression(
            '/`local_code`[^,\n]*PRIMARY\s+KEY/i',
            $ddl
        );
        self::assertDoesNotMatchRegularExpression(
            '/`id`[^,\n]*PRIMARY\s+KEY/i',
            $ddl
        );

        $this->executeSqliteRebuild($ddl);

        $rows = $this->connector->query(
            "SELECT local_code, name FROM eav_attribute_local_description WHERE local_code = 'bn_IN'"
        )->fetch();
        self::assertSame('kept', $rows[0]['name'] ?? null);
        $createSql = (string)($this->connector->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='eav_attribute_local_description'"
        )->fetch()[0]['sql'] ?? '');
        self::assertMatchesRegularExpression('/PRIMARY\s+KEY\s*\(\s*"?id"?\s*,\s*"?local_code"?\s*\)/i', $createSql);
    }

    public function testSqliteModifyNotNullWithDefaultCoalescesNullRows(): void
    {
        $this->connector = $this->createConnector();
        $this->connector->query(
            'CREATE TABLE w_pixel (pixel_id INTEGER PRIMARY KEY AUTOINCREMENT, page_type VARCHAR(64))'
        )->fetch();
        $this->connector->query('INSERT INTO w_pixel (page_type) VALUES (NULL)')->fetch();
        $this->connector->query("INSERT INTO w_pixel (page_type) VALUES ('home')")->fetch();

        $ddl = $this->connector->buildAlterModifyColumnSql('w_pixel', [
            'name' => 'page_type',
            'type' => 'varchar',
            'length' => 64,
            'nullable' => false,
            'primaryKey' => false,
            'autoIncrement' => false,
            'default' => '',
            'unique' => false,
        ]);

        self::assertStringContainsString("COALESCE(\"page_type\", '')", $ddl);
        $this->executeSqliteRebuild($ddl);

        $rows = $this->connector->query('SELECT page_type FROM w_pixel ORDER BY pixel_id')->fetch();
        self::assertSame('', $rows[0]['page_type'] ?? null);
        self::assertSame('home', $rows[1]['page_type'] ?? null);
    }

    public function testSqlitePrimaryKeyTransferRebuildsAndPreservesRows(): void
    {
        $this->connector = $this->createConnector();
        $this->connector->query(
            'CREATE TABLE demo (legacy_id INTEGER PRIMARY KEY AUTOINCREMENT, value VARCHAR(32) NOT NULL)'
        )->fetch();
        $this->connector->query("INSERT INTO demo (value) VALUES ('kept')")->fetch();

        $demoteDdl = $this->connector->buildAlterModifyColumnSql('demo', [
            'name' => 'legacy_id',
            'type' => 'integer',
            'length' => null,
            'nullable' => false,
            'primaryKey' => false,
            'autoIncrement' => false,
            'default' => null,
            'unique' => false,
        ]);
        $this->executeSqliteRebuild($demoteDdl);

        $dropDdl = $this->connector->buildAlterDropProjectedColumnSql('demo', 'row_id');
        $addDdl = $this->connector->buildAlterAddColumnSql('demo', [
            'name' => 'row_id',
            'type' => 'bigint',
            'length' => 20,
            'nullable' => false,
            'primaryKey' => true,
            'autoIncrement' => true,
            'default' => null,
            'unique' => false,
        ]);
        self::assertStringContainsString('INTEGER PRIMARY KEY AUTOINCREMENT', $addDdl);
        $this->executeSqliteRebuild($addDdl);

        $columns = $this->connector->getTableColumns('demo');
        $byName = [];
        foreach ($columns as $column) {
            $byName[$column['name']] = $column;
        }
        self::assertFalse((bool)($byName['legacy_id']['primary_key'] ?? false));
        self::assertTrue((bool)($byName['row_id']['primary_key'] ?? false));
        self::assertTrue((bool)($byName['row_id']['auto_increment'] ?? false));
        self::assertSame(
            ['legacy_id' => 1, 'row_id' => 1, 'value' => 'kept'],
            $this->connector->query('SELECT legacy_id, row_id, value FROM demo')->fetch()[0] ?? []
        );

        $this->executeSqliteRebuild($dropDdl);
        self::assertSame(
            ['legacy_id', 'value'],
            array_column($this->connector->getTableColumns('demo'), 'name')
        );
        self::assertSame('kept', $this->connector->query('SELECT value FROM demo')->fetch()[0]['value'] ?? null);
    }

    public function testSqliteCreateTableFromSchemaUsesCompositePrimaryKeyWithoutAutoincrement(): void
    {
        $this->connector = $this->createConnector();
        $this->connector->createTableFromSchema('demo_local', [
            'comment' => 'local',
            'columns' => [
                [
                    'name' => 'id',
                    'type' => 'int',
                    'nullable' => false,
                    'primaryKey' => true,
                    'autoIncrement' => true,
                    'unique' => false,
                    'comment' => '',
                    'default' => null,
                ],
                [
                    'name' => 'local_code',
                    'type' => 'varchar',
                    'length' => 20,
                    'nullable' => false,
                    'primaryKey' => true,
                    'autoIncrement' => false,
                    'unique' => false,
                    'comment' => '',
                    'default' => null,
                ],
            ],
        ]);

        $createSql = (string)($this->connector->query(
            "SELECT sql FROM sqlite_master WHERE type='table' AND name='demo_local'"
        )->fetch()[0]['sql'] ?? '');
        self::assertMatchesRegularExpression('/PRIMARY\s+KEY\s*\(\s*"?id"?\s*,\s*"?local_code"?\s*\)/i', $createSql);
        self::assertDoesNotMatchRegularExpression('/AUTOINCREMENT/i', $createSql);
    }

    public function testSqliteCreateTableDropsUnattachedLogicalDatabaseQualifier(): void
    {
        $this->connector = $this->createConnector('weline');
        $this->connector->createTableFromSchema('"weline"."demo_qualified"', [
            'comment' => 'qualified model table',
            'columns' => [
                [
                    'name' => 'id',
                    'type' => 'int',
                    'nullable' => false,
                    'primaryKey' => true,
                    'autoIncrement' => true,
                    'unique' => false,
                    'comment' => '',
                    'default' => null,
                ],
            ],
        ]);

        self::assertSame(
            1,
            (int) $this->connector->query(
                "SELECT COUNT(*) AS total FROM sqlite_master WHERE type='table' AND name='demo_qualified'"
            )->fetch()[0]['total']
        );
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

    private function createConnector(string $database = ''): Connector
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
            'database' => $database,
            'path' => $this->dbPath,
            'persistent' => false,
        ]));
    }
}
