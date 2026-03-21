<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2021/6/21
 * 时间：11:45
 * 描述：此文件源码由Aiweline（秋枫雁飞）开发，请勿随意修改源码！
 */

namespace Weline\Framework\Database\Connection\Adapter\Sqlite;

use PDO;
use PDOException;
use Weline\Framework\Database\Connection\Adapter\Sqlite\Dialect\SqliteIdentifierFormatter;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Compiler\Dialect\SqliteDialect;
use Weline\Framework\Database\Connection\ConnectionInterface as DbConnectionInterface;
use Weline\Framework\Database\Connection\PdoConnection;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultTableNameStrategy;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Manager\ObjectManager;

final class Connector extends Query implements ConnectorInterface
{
    public function __construct(
        private readonly ConfigProvider $configProvider
    ) {
        // FIXME 停止使用，适配不完全，仅提供Pgsql适配器，后续版本可能移除
        throw new \Exception('SQLite 数据库连接适配器已停止使用，请使用 Pgsql。');
        $identifierFormatter = new SqliteIdentifierFormatter();
        $tableStrategy = new DefaultTableNameStrategy(
            $identifierFormatter,
            $this->configProvider->getPrefix() ?: ''
        );
        parent::__construct(
            $identifierFormatter,
            $tableStrategy
        );
        $this->db_name = $this->configProvider->getDatabase();
    }

    /** Connector 自身即持有连接，作为 Query 使用时直接返回，避免依赖 SqlTrait 的 $this->connection */
    public function getConnectionInterface(): DbConnectionInterface
    {
        return $this->getWrappedConnection();
    }

    protected ?PDO $link = null;
    protected ?DbConnectionInterface $wrappedConnection = null;
    protected ?Query $query = null;
    protected bool $fromPool = false; // 标记连接是否来自连接池

    private ?SqliteDialect $dialect = null;

    private function getDialect(): SqliteDialect
    {
        return $this->dialect ??= new SqliteDialect();
    }

    static function processName(string $name): string
    {
        return str_replace(['`', '"'], '', $name);
    }

    public function create(): static
    {
        if ($this->link !== null) {
            return $this;
        }

        $db_type = $this->configProvider->getDbType();
        
        // 从连接池获取连接
        $this->link = ConnectionPool::getConnection(
            $this->configProvider,
            function () use ($db_type) {
                $dsn = "{$db_type}:{$this->configProvider->getData('path')}";
                try {
                    $options = $this->configProvider->getOptions();
                    // SQLite 也支持持久连接，但需要确保错误模式设置
                    if (!isset($options[PDO::ATTR_ERRMODE])) {
                        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
                    }
                    $connection = new PDO($dsn, null, null, $options);
                    # PRAGMA case_sensitive_like = ON;  -- 开启大小写敏感的LIKE查询
                    $connection->exec('PRAGMA case_sensitive_like = OFF; -- 关闭大小写敏感的LIKE查询（默认）');
                    return $connection;
                } catch (PDOException $e) {
                    throw new LinkException($e->getMessage());
                }
            }
        );
        $this->fromPool = true;
        try {
            $version = (string)$this->link->query('SELECT sqlite_version()')->fetchColumn();
            $this->getDialect()->validateVersion($version);
        } catch (\Throwable $e) {
            w_log_warning(__('SQLite 版本校验未通过（连接已建立，升级可继续）：%{1}', [$e->getMessage()]), [], 'database_version.log');
        }
        $this->wrappedConnection = new PdoConnection($this->link, 'sqlite');
        return $this;
    }

    public function getWrappedConnection(): DbConnectionInterface
    {
        $this->create();
        if ($this->wrappedConnection === null) {
            $this->wrappedConnection = new PdoConnection($this->link, 'sqlite');
        }
        return $this->wrappedConnection;
    }

    public function close(): void
    {
        if ($this->link !== null) {
            if ($this->fromPool) {
                ConnectionPool::releaseConnection($this->link, $this->configProvider);
            }
            $this->link = null;
            $this->wrappedConnection = null;
            $this->fromPool = false;
        }
    }

    /**
     * 析构函数：确保连接在使用后被归还到连接池
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @deprecated 请使用 getWrappedConnection() 获取连接并调用其方法，后续版本可能移除
     */
    public function getLink(): PDO
    {
        $this->create();
        return $this->link;
    }

    /**
     * 使用 SQLite 原生 REINDEX 重建表索引（@since SQLite 3.45+）
     */
    public function reindex(string $table): bool
    {
        $table = self::processName($table);
        if (str_contains($table, '.')) {
            $parts = explode('.', $table, 2);
            $table = $parts[1] ?? $table;
        }
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        try {
            $this->getConnectionInterface()->execute('REINDEX ' . $quoted);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getIndexFields(string $table): array
    {
        $table = self::processName($table);
        // 获取表的索引列表
        $indexList = $this->query("PRAGMA index_list('$table')")->fetch();

        $indexFields = [];

        foreach ($indexList as $index) {
            // 获取索引的详细信息
            $indexInfo = $this->query("PRAGMA index_info('{$index['name']}')")->fetch();

            foreach ($indexInfo as $info) {
                $indexFields[] = [
                    'Table' => $table,
                    'Non_unique' => $index['unique'] ? 0 : 1,
                    'Key_name' => $index['name'],
                    'Seq_in_index' => $info['seqno'],
                    'Column_name' => $info['name'],
                    'Collation' => 'A', // SQLite 默认使用二进制排序
                ];
            }
        }

        return $indexFields;
    }

    public function dev()
    {
        return "
# 查询表的索引字段并拼接成索引重建SQL
SET @rebuild_indexer_schema = 'weline';
SET @rebuild_indexer_table = 'm_contact';
SET @rebuild_indexer_sql = '';

SELECT GROUP_CONCAT(index_field.rebuild_field_sql)
INTO @rebuild_indexer_sql
FROM (SELECT--   i.TABLE_NAME,
--   i.INDEX_NAME,
--   GROUP_CONCAT( i.COLUMN_NAME ) AS COLUMN_NAME,
            CONCAT(
                    ' DROP ',
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' PRIMARY KEY ', ' INDEX '),
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' ', i.INDEX_NAME),
                    ' , ADD ',
                    IF
                    (i.NON_UNIQUE = '0', IF(i.INDEX_NAME = 'PRIMARY', ' ', ' UNIQUE '), ''),
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' PRIMARY KEY ', ' INDEX '),
                    IF
                    (i.INDEX_NAME = 'PRIMARY', ' ', i.INDEX_NAME),
                    '(',
                    GROUP_CONCAT('`', i.COLUMN_NAME, '`'), IF(i.COLLATION = 'A', ' ASC ', ' DESC '),
                    ')',
                    ' COMMENT \'',
                    i.INDEX_COMMENT,
                    '\' USING ',
                    i.INDEX_TYPE
            ) AS rebuild_field_sql
      FROM INFORMATION_SCHEMA.STATISTICS i
      WHERE i.TABLE_SCHEMA = @rebuild_indexer_schema
        AND i.TABLE_NAME = @rebuild_indexer_table
      GROUP BY i.INDEX_NAME
      ORDER BY i.SEQ_IN_INDEX)
         AS index_field;
SELECT CONCAT('ALTER TABLE `', @rebuild_indexer_schema, '`.`', @rebuild_indexer_table, '`',
              @rebuild_indexer_sql) AS rebuild_indexer_sql;";
    }

    /**
     * @DESC          # 读取创建表SQL
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 22:08
     * 参数区：
     *
     * @param string $table_name
     *
     * @return mixed
     */
    public function getCreateTableSql(string $table_name): string
    {
        $table_name = self::processName($table_name);
        // 获取表的元数据
        $tableMeta = $this->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table_name'")->fetch();

        if ($tableMeta === false) {
            throw new \Exception("Table '$table_name' does not exist.");
        }
        // 返回 CREATE TABLE 语句
        return $tableMeta[0]['sql'] ?? '';
    }

    public function getConfigProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function createTable(): Sql\Table\CreateInterface
    {
        return ObjectManager::getInstance(Table\Create::class)->setConnection($this);
    }

    public function alterTable(): Sql\Table\AlterInterface
    {
        return ObjectManager::getInstance(Table\Alter::class)->setConnection($this);
    }

    public function dropTableIfExists(string $table): void
    {
        $quoted = $this->quoteTable(self::processName($table));
        $this->query("DROP TABLE IF EXISTS {$quoted}")->fetch();
    }

    public function tableExist(string $table_name): bool
    {
        $table_name = self::processName($table_name);
        try {
            $res = $this->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table_name}'; ")->fetch();
            if (empty($res)) {
                return false;
            }
            return true;
        } catch (\Exception $exception) {
            return false;
        }
    }

    /** @inheritDoc */
    public function getExistingTables(array $tableNames): array
    {
        // SQLite connector 已弃用（构造函数 throw），此处降级为逐表检查
        return array_values(array_filter(
            array_map(fn($t) => trim(str_replace(['`', '"'], '', (string) $t)), $tableNames),
            fn($t) => $t !== '' && $this->tableExist($t)
        ));
    }

    public function getVersion(): string
    {
        // 查询数据库版本号
        return $this->link->getAttribute(PDO::ATTR_CLIENT_VERSION);
    }

    public function hasField(string $table, string $field): bool
    {
        $table = self::processName($table);
        $field = self::processName($field);
        $sql = "SELECT name FROM pragma_table_info('{$table}') WHERE name LIKE '{$field}';";
        $res = $this->query($sql)->fetch();
        return (bool)$res;
    }

    public function hasIndex(string $table, string $idx_name): bool
    {
        $table = self::processName($table);
        $idx_name = self::processName($idx_name);
        $sql = "SELECT name FROM pragma_index_list('{$table}') WHERE name LIKE '{$idx_name}';";
        $res = $this->query($sql)->fetch();
        return !empty($res);
    }

    public function getQuery(): QueryInterface
    {
        return $this;
    }

    /** @inheritDoc */
    public function getTableComment(string $table): string
    {
        return '';
    }

    /** @inheritDoc */
    public function getTableColumns(string $table): array
    {
        $table = self::processName($table);
        $rows = $this->query("PRAGMA table_info(" . $this->getLink()->quote($table) . ")")->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $list = [];
        foreach ($rows as $row) {
            $name = $row['name'] ?? '';
            $type = strtolower($row['type'] ?? '');
            $notnull = (int) ($row['notnull'] ?? 0);
            $pk = (int) ($row['pk'] ?? 0);
            $default = $row['dflt_value'] ?? null;
            $list[] = [
                'name' => $name,
                'type' => $type ?: 'text',
                'length' => null,
                'nullable' => $notnull === 0,
                'primary_key' => $pk > 0,
                'auto_increment' => $pk > 0 && ($type === 'integer' || $type === 'int'),
                'default' => $default,
                'comment' => '',
                'unique' => false,
            ];
        }
        return $list;
    }

    /** @inheritDoc */
    public function getTableIndexes(string $table): array
    {
        $table = self::processName($table);
        $indexList = $this->query("PRAGMA index_list(" . $this->getLink()->quote($table) . ")")->fetchArray();
        if (!is_array($indexList)) {
            return [];
        }
        $list = [];
        foreach ($indexList as $idx) {
            $name = $idx['name'] ?? '';
            $unique = (bool) ($idx['unique'] ?? false);
            $info = $this->query("PRAGMA index_info(" . $this->getLink()->quote($name) . ")")->fetchArray();
            $columns = [];
            if (is_array($info)) {
                foreach ($info as $r) {
                    $columns[] = $r['name'] ?? '';
                }
            }
            $list[] = ['name' => $name, 'columns' => $columns, 'unique' => $unique];
        }
        return $list;
    }

    /** @inheritDoc */
    public function quoteTable(string $table): string
    {
        return $this->getDialect()->quoteTable($table);
    }

    /** @inheritDoc */
    public function quoteIdentifier(string $identifier): string
    {
        return $this->getDialect()->quoteIdentifier($identifier);
    }

    /** @inheritDoc */
    public function buildAlterAddColumnSql(string $table, array $col): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $def = $this->sqliteColumnDef($col);
        return "ALTER TABLE {$t} ADD COLUMN {$def}";
    }

    /** @inheritDoc */
    public function buildAlterModifyColumnSql(string $table, array $col, ?array $existingCol = null): string
    {
        throw new \RuntimeException('SQLite does not support ALTER COLUMN MODIFY. Use table recreation workaround.');
    }

    /** @inheritDoc */
    public function buildAlterDropColumnSql(string $table, string $colName): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $c = $d->quoteIdentifier($colName);
        return "ALTER TABLE {$t} DROP COLUMN {$c}";
    }

    /** @inheritDoc */
    public function buildAlterTableCommentSql(string $table, string $comment): string
    {
        return "SELECT 1"; // SQLite 不支持表注释，返回无操作
    }

    /** @inheritDoc */
    public function buildAddIndexSql(string $table, array $idx): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $name = $d->quoteIdentifier($idx['name'] ?? '');
        $cols = array_map(fn (string $c) => $d->quoteIdentifier($c), $idx['columns'] ?? []);
        $colList = implode(',', $cols);
        $type = strtoupper($idx['type'] ?? 'INDEX');
        if ($type === 'UNIQUE') {
            return "CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON {$t} ({$colList})";
        }
        return "CREATE INDEX IF NOT EXISTS {$name} ON {$t} ({$colList})";
    }

    /** @inheritDoc */
    public function buildDropIndexSql(string $table, string $indexName): string
    {
        $n = $this->getDialect()->quoteIdentifier($indexName);
        return "DROP INDEX IF EXISTS {$n}";
    }

    /** @inheritDoc */
    public function buildAddForeignKeySql(string $table, array $fk): string
    {
        return "SELECT 1"; // SQLite 不支持 ALTER TABLE ADD CONSTRAINT
    }

    /** @inheritDoc */
    public function buildDropForeignKeySql(string $table, string $fkName): string
    {
        return "SELECT 1"; // SQLite 不支持 ALTER TABLE DROP CONSTRAINT
    }

    private function sqliteColumnDef(array $col): string
    {
        $c = $this->getDialect()->quoteIdentifier($col['name'] ?? '');
        $type = strtoupper($col['type'] ?? 'TEXT');
        $len = $col['length'] ?? null;
        $typeLen = $type . ($len !== null ? "({$len})" : '');
        $opts = [];
        if (!empty($col['primaryKey'])) {
            $opts[] = 'PRIMARY KEY';
        }
        if (!empty($col['autoIncrement']) && !empty($col['primaryKey'])) {
            $opts[] = 'AUTOINCREMENT';
        }
        if (empty($col['nullable']) && empty($col['primaryKey'])) {
            $opts[] = 'NOT NULL';
        }
        if (isset($col['default']) && $col['default'] !== null) {
            $d = $col['default'];
            $opts[] = is_string($d) && strtoupper($d) === 'CURRENT_TIMESTAMP'
                ? "DEFAULT (datetime('now'))"
                : (is_string($d) ? "DEFAULT '" . str_replace("'", "''", $d) . "'" : "DEFAULT {$d}");
        }
        $optStr = implode(' ', $opts);
        return "{$c} {$typeLen} {$optStr}";
    }

    /** @inheritDoc */
    public function getTableForeignKeys(string $table): array
    {
        $table = self::processName($table);
        $rows = $this->query("PRAGMA foreign_key_list(" . $this->getLink()->quote($table) . ")")->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'name' => 'fk_' . ($row['id'] ?? 0),
                'columns' => [$row['from'] ?? ''],
                'ref_table' => $row['table'] ?? '',
                'ref_columns' => [$row['to'] ?? ''],
                'on_delete_cascade' => strtoupper($row['on_delete'] ?? '') === 'CASCADE',
                'on_update_cascade' => strtoupper($row['on_update'] ?? '') === 'CASCADE',
            ];
        }
        return $list;
    }

    /** @inheritDoc */
    public function getDefaultTableAdditional(): string
    {
        return '';
    }
}
