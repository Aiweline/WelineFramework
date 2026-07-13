<?php

declare(strict_types=1);

namespace Weline\Database\test;

use PDO;
use PHPUnit\Framework\TestCase;
use Weline\Framework\Database\Connection\Adapter\Mysql\Connector as MysqlConnector;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Connector as PgsqlConnector;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Connector as SqliteConnector;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\DbManager\ConfigProvider;

/**
 * Executes the same destructive schema round-trip against every supported
 * adapter. CI selects one concrete service with WELINE_DB_MATRIX_DRIVER.
 */
final class DatabaseAdapterRollbackMatrixTest extends TestCase
{
    private ?ConnectorInterface $connector = null;
    private ?string $sqlitePath = null;

    protected function tearDown(): void
    {
        if ($this->connector !== null) {
            try {
                $this->connector->dropTableIfExists('child');
                $this->connector->dropTableIfExists('parent');
            } catch (\Throwable) {
            }
            $this->connector->close();
            $this->connector = null;
        }
        if ($this->sqlitePath !== null && is_file($this->sqlitePath)) {
            @unlink($this->sqlitePath);
        }
        $this->sqlitePath = null;
        parent::tearDown();
    }

    public function testConfiguredAdapterCompletesDestructiveRollbackRoundTrip(): void
    {
        $driver = strtolower(trim((string)(getenv('WELINE_DB_MATRIX_DRIVER') ?: 'sqlite')));
        $this->connector = $this->createConnector($driver);
        $this->createTables($driver, $this->connector);

        self::assertTrue($this->connector->tableExist('parent'));
        self::assertTrue($this->connector->tableExist('child'));
        $existing = $this->connector->getExistingTables(['parent', 'child', 'missing']);
        sort($existing);
        self::assertSame(['child', 'parent'], $existing);

        $this->connector->getQuery()->clearQuery()->table('parent')->insert(['name' => 'root'])->fetch();
        $this->connector->getQuery()->clearQuery()->table('child')->insert([
            'parent_id' => 1,
            'payload' => 'preserved',
        ])->fetch();
        // SchemaDiff supplies the model's already-formatted physical table to
        // DDL builders; metadata/query APIs continue to use the logical name.
        $ddlTable = $this->connector->formatTableName('child');

        $column = [
            'name' => 'rollback_value',
            'type' => 'varchar',
            'length' => 64,
            'nullable' => true,
            'primaryKey' => false,
            'autoIncrement' => false,
            'default' => null,
            'comment' => '',
            'unique' => false,
        ];
        $this->executeDdl($this->connector, $this->connector->buildAlterAddColumnSql($ddlTable, $column));
        $this->connector->getQuery()->clearQuery()->table('child')->where('id', 1)->update([
            'rollback_value' => 'backed-up-value',
        ])->fetch();

        $existingColumn = $this->findColumn($this->connector, 'child', 'rollback_value');
        $modifiedColumn = $column;
        $modifiedColumn['length'] = 128;
        $this->executeDdl(
            $this->connector,
            $this->connector->buildAlterModifyColumnSql($ddlTable, $modifiedColumn, $existingColumn),
        );
        self::assertSame(
            'backed-up-value',
            $this->connector->getQuery()->clearQuery()->table('child')->fields(['rollback_value'])
                ->where('id', 1)->limit(1)->select()->fetch()[0]['rollback_value'] ?? null,
        );

        $this->executeDdl($this->connector, $this->connector->buildAddIndexSql($ddlTable, [
            'name' => 'idx_rollback_value',
            'columns' => ['rollback_value'],
            'type' => 'INDEX',
        ]));
        $this->executeDdl($this->connector, $this->connector->buildAddForeignKeySql($ddlTable, [
            'name' => 'fk_matrix_parent',
            'columns' => ['parent_id'],
            'referencesTable' => 'parent',
            'referencesColumns' => ['id'],
            'onDeleteCascade' => true,
            'onUpdateCascade' => false,
        ]));

        self::assertTrue($this->connector->hasField('child', 'rollback_value'));
        self::assertTrue($this->connector->hasIndex('child', 'idx_rollback_value'));
        self::assertContains(
            'fk_matrix_parent',
            array_column($this->connector->getTableForeignKeys('child'), 'name'),
        );

        $structureDdl = $this->connector->getCreateTableSql('child');
        self::assertNotSame('', trim($structureDdl));
        self::assertStringContainsStringIgnoringCase('CREATE TABLE', $structureDdl);
        self::assertStringContainsString('idx_rollback_value', $structureDdl);
        self::assertStringContainsString('fk_matrix_parent', $structureDdl);
        $this->connector->dropTableIfExists('child');
        self::assertFalse($this->connector->tableExist('child'));
        $this->executeDdl($this->connector, $structureDdl);
        self::assertTrue($this->connector->tableExist('child'));
        self::assertTrue($this->connector->hasField('child', 'rollback_value'));
        self::assertTrue($this->connector->hasIndex('child', 'idx_rollback_value'));
        self::assertContains(
            'fk_matrix_parent',
            array_column($this->connector->getTableForeignKeys('child'), 'name'),
        );
        $this->connector->getQuery()->clearQuery()->table('child')->insert([
            // Data restoration writes the original primary key explicitly.
            // MySQL correctly preserves AUTO_INCREMENT=2 in SHOW CREATE TABLE,
            // so relying on a new generated value here would not model restore.
            'id' => 1,
            'parent_id' => 1,
            'payload' => 'preserved',
            'rollback_value' => 'backed-up-value',
        ])->fetch();

        $this->executeDdl(
            $this->connector,
            $this->connector->buildDropForeignKeySql($ddlTable, 'fk_matrix_parent'),
        );
        $this->executeDdl(
            $this->connector,
            $this->connector->buildDropIndexSql($ddlTable, 'idx_rollback_value'),
        );
        $this->executeDdl(
            $this->connector,
            $this->connector->buildAlterDropColumnSql($ddlTable, 'rollback_value'),
        );

        self::assertFalse($this->connector->hasField('child', 'rollback_value'));
        self::assertFalse($this->connector->hasIndex('child', 'idx_rollback_value'));
        self::assertNotContains(
            'fk_matrix_parent',
            array_column($this->connector->getTableForeignKeys('child'), 'name'),
        );
        $row = $this->connector->getQuery()->clearQuery()->table('child')->where('id', 1)
            ->limit(1)->select()->fetch()[0] ?? [];
        self::assertSame('preserved', $row['payload'] ?? null);
        self::assertSame(1, (int)($row['parent_id'] ?? 0));
    }

    private function createConnector(string $driver): ConnectorInterface
    {
        $pdoDriver = in_array($driver, ['mysql', 'mariadb'], true) ? 'mysql' : $driver;
        self::assertContains(
            $pdoDriver,
            PDO::getAvailableDrivers(),
            "PDO driver {$pdoDriver} is required for the explicit {$driver} matrix job.",
        );

        $prefix = 'mx_' . preg_replace('/[^a-z0-9]+/', '_', $driver) . '_' . substr(hash('sha256', uniqid('', true)), 0, 8) . '_';
        if ($driver === 'sqlite') {
            $this->sqlitePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . '.sqlite';
            return new SqliteConnector(new ConfigProvider([
                'type' => 'sqlite',
                'path' => $this->sqlitePath,
                'database' => '',
                'prefix' => $prefix,
                'persistent' => false,
            ]));
        }

        $config = new ConfigProvider([
            'type' => $pdoDriver,
            'hostname' => (string)(getenv('WELINE_DB_MATRIX_HOST') ?: '127.0.0.1'),
            'hostport' => (int)(getenv('WELINE_DB_MATRIX_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306)),
            'database' => (string)(getenv('WELINE_DB_MATRIX_DATABASE') ?: 'weline'),
            'username' => (string)(getenv('WELINE_DB_MATRIX_USERNAME') ?: 'weline'),
            'password' => (string)(getenv('WELINE_DB_MATRIX_PASSWORD') ?: ''),
            'charset' => $driver === 'pgsql' ? 'UTF8' : 'utf8mb4',
            'collate' => 'utf8mb4_unicode_ci',
            'prefix' => $prefix,
            'persistent' => false,
        ]);

        return $driver === 'pgsql' ? new PgsqlConnector($config) : new MysqlConnector($config);
    }

    private function createTables(string $driver, ConnectorInterface $connector): void
    {
        $parent = $connector->formatTableName('parent');
        $child = $connector->formatTableName('child');
        if ($driver === 'pgsql') {
            $connector->query(
                "CREATE TABLE {$parent} (id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, name VARCHAR(64) NOT NULL)"
            )->fetch();
            $connector->query(
                "CREATE TABLE {$child} (id INTEGER GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, parent_id INTEGER NOT NULL, payload VARCHAR(64) NOT NULL)"
            )->fetch();
            return;
        }
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $connector->query(
                "CREATE TABLE {$parent} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(64) NOT NULL) ENGINE=InnoDB"
            )->fetch();
            $connector->query(
                "CREATE TABLE {$child} (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, parent_id INT NOT NULL, payload VARCHAR(64) NOT NULL) ENGINE=InnoDB"
            )->fetch();
            return;
        }
        $connector->query(
            "CREATE TABLE {$parent} (id INTEGER PRIMARY KEY AUTOINCREMENT, name VARCHAR(64) NOT NULL)"
        )->fetch();
        $connector->query(
            "CREATE TABLE {$child} (id INTEGER PRIMARY KEY AUTOINCREMENT, parent_id INTEGER NOT NULL, payload VARCHAR(64) NOT NULL)"
        )->fetch();
    }

    /** @return array<string, mixed> */
    private function findColumn(ConnectorInterface $connector, string $table, string $column): array
    {
        foreach ($connector->getTableColumns($table) as $definition) {
            if ((string)($definition['name'] ?? '') === $column) {
                return $definition;
            }
        }
        self::fail("Column {$table}.{$column} was not found.");
    }

    private function executeDdl(ConnectorInterface $connector, string $ddl): void
    {
        $sqliteRebuild = str_contains($ddl, '/* WELINE_SQLITE_REBUILD */');
        $ddl = str_replace('/* WELINE_SQLITE_REBUILD */', '', $ddl);
        try {
            if ($sqliteRebuild) {
                $connector->query('PRAGMA foreign_keys=OFF')->fetch();
                $connector->beginTransaction();
            }
            $statements = str_contains($ddl, "\n-- WELINE_DDL_STATEMENT\n")
                ? explode("\n-- WELINE_DDL_STATEMENT\n", $ddl)
                : (str_contains($ddl, ";\n") ? explode(";\n", $ddl) : [$ddl]);
            foreach ($statements as $statement) {
                if (trim($statement) !== '') {
                    $connector->query($statement)->fetch();
                }
            }
            if ($sqliteRebuild) {
                $connector->commit();
            }
        } catch (\Throwable $e) {
            if ($sqliteRebuild) {
                $connector->rollBack();
            }
            throw $e;
        } finally {
            if ($sqliteRebuild) {
                $connector->query('PRAGMA foreign_keys=ON')->fetch();
            }
        }
    }
}
