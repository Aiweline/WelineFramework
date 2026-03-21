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

namespace Weline\Framework\Database\Connection\Adapter\Mysql;

use PDO;
use PDOException;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table\Alter;
use Weline\Framework\Database\Connection\Adapter\Mysql\Table\Create;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Compiler\Dialect\MysqlDialect;
use Weline\Framework\Database\Connection\ConnectionInterface as DbConnectionInterface;
use Weline\Framework\Database\Connection\PdoConnection;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultIdentifierFormatter;
use Weline\Framework\Database\Connection\Api\Sql\Dialect\DefaultTableNameStrategy;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Helper\Standar;
use Weline\Framework\Manager\ObjectManager;

final class Connector extends Query implements ConnectorInterface
{
    public function __construct(
        private readonly ?ConfigProvider $configProvider
    ) {
        // FIXME 停止使用，适配不完全，仅提供Pgsql适配器，后续版本可能移除
        throw new \Exception('MySQL 数据库连接适配器已停止使用，请使用 Pgsql。');
        $identifierFormatter = new DefaultIdentifierFormatter();
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

    private ?MysqlDialect $dialect = null;

    private function getDialect(): MysqlDialect
    {
        return $this->dialect ??= new MysqlDialect();
    }

    public function create(): static
    {
        if ($this->link !== null) {
            return $this;
        }

        $db_type = $this->configProvider->getDbType();
        if (!in_array($db_type, PDO::getAvailableDrivers())) {
            throw new LinkException(__('驱动不存在：%{1},可用驱动列表：%{2}，更多驱动配置请转到php.ini中开启。', [$db_type, implode(',', PDO::getAvailableDrivers())]));
        }

        // 从连接池获取连接
        $this->link = ConnectionPool::getConnection(
            $this->configProvider,
            function () use ($db_type) {
                $dsn = "{$db_type}:host={$this->configProvider->getHostName()}:{$this->configProvider->getHostPort()};dbname={$this->configProvider->getDatabase()};charset={$this->configProvider->getCharset()};collate={$this->configProvider->getCollate()}";
                try {
                    $connection = new PDO($dsn, $this->configProvider->getUsername(), $this->configProvider->getPassword(), $this->configProvider->getOptions());
                    // 确保错误模式已设置（如果选项中没有设置）
                    if (!$connection->getAttribute(PDO::ATTR_ERRMODE)) {
                        $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    }
                    if ($this->configProvider->getPreSql()) {
                        $connection->exec($this->configProvider->getPreSql());
                    }
                    return $connection;
                } catch (PDOException $e) {
                    throw new LinkException($e->getMessage());
                }
            }
        );
        $this->fromPool = true;
        try {
            $this->getDialect()->validateVersion((string)$this->link->getAttribute(PDO::ATTR_SERVER_VERSION));
        } catch (\Throwable $e) {
            w_log_warning(__('MySQL 版本校验未通过（连接已建立，升级可继续）：%{1}', [$e->getMessage()]), [], 'database_version.log');
        }
        $this->wrappedConnection = new PdoConnection($this->link, 'mysql');
        return $this;
    }

    public function getWrappedConnection(): DbConnectionInterface
    {
        $this->create();
        if ($this->wrappedConnection === null) {
            $this->wrappedConnection = new PdoConnection($this->link, 'mysql');
        }
        return $this->wrappedConnection;
    }

    public function close(): void
    {
        // 如果连接来自连接池，归还到池中；否则直接释放
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

    public function reindex(string $table): bool
    {
        $table = str_replace('`', '', $table);
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        if (empty($schema)) {
            $schema = $this->configProvider->getDatabase();
        }
        # 查询表的存储引擎
        $RebuildIndexerSql = <<<REBUILD_INDEXER_SQL
SET @rebuild_indexer_schema = '{$schema}';

SET @rebuild_indexer_table = '{$table}';

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
              @rebuild_indexer_sql) AS rebuild_indexer_sql;
REBUILD_INDEXER_SQL;
        $rebuild_indexer_sql = $this->query($RebuildIndexerSql)->fetch()[4][0]['rebuild_indexer_sql'] ?? '';
        if (empty($rebuild_indexer_sql)) {
            return false;
        }
        $this->query($rebuild_indexer_sql)->fetch();
        return true;
    }

    public function getIndexFields(string $table): array
    {
        return $this->query('show index from ' . $table)->fetchArray();
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
        return $this->query("SHOW CREATE TABLE {$table_name}")->fetch()[0]["Create Table"];
    }

    public function getConfigProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function getQuery(): QueryInterface
    {
        return $this;
    }

    public function createTable(): Sql\Table\CreateInterface
    {
        return ObjectManager::getInstance(Create::class)->setConnection($this);
    }

    public function alterTable(): Sql\Table\AlterInterface
    {
        return ObjectManager::getInstance(Alter::class)->setConnection($this);
    }

    public function dropTableIfExists(string $table): void
    {
        $quoted = $this->quoteTable(str_replace(['`', '"'], '', $table));
        $this->query("DROP TABLE IF EXISTS {$quoted}")->fetch();
    }

    public function tableExist(string $table_name): bool
    {
        try {
            // 清理表名，移除反引号
            $table_name = str_replace(['`', '"'], '', $table_name);

            // 处理数据库名和表名（如果包含点号分隔）
            $dbName = $this->configProvider->getDatabase();
            $schema = $dbName;
            $table = $table_name;

            if (str_contains($table_name, '.')) {
                $parts = explode('.', $table_name, 2);
                $schema = $parts[0];
                $table = $parts[1] ?? $parts[0];
            }

            // 使用 information_schema 查询表是否存在，不会产生错误或警告
            $sql = "SELECT COUNT(*) as count FROM information_schema.tables
                    WHERE table_schema = :schema AND table_name = :table";
            $stmt = $this->getLink()->prepare($sql);
            $stmt->execute([
                ':schema' => $schema,
                ':table' => $table
            ]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return (bool)($result['count'] ?? 0);
        } catch (\Exception $exception) {
            return false;
        }
    }

    /** @inheritDoc */
    public function getExistingTables(array $tableNames): array
    {
        // MySQL connector 已弃用（构造函数 throw），此处降级为逐表检查
        return array_values(array_filter(
            array_map(fn($t) => trim(str_replace(['`', '"'], '', (string) $t)), $tableNames),
            fn($t) => $t !== '' && $this->tableExist($t)
        ));
    }

    public function getVersion(): string
    {
        // 查询数据库版本号
        $query = 'SELECT VERSION() AS version';
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['version'];
    }

    public function hasField(string $table, string $field): bool
    {
        # 使用 SHOW COLUMNS 检查字段
        $query = "SHOW COLUMNS FROM {$table} LIKE '{$field}'";
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    public function hasIndex(string $table, string $idx_name): bool
    {
        # 检查索引是否存在
        $idx_name = Standar::getIndexName($table, $idx_name);

        $query = "SHOW INDEXES FROM {$table} WHERE Key_name = '{$idx_name}'";
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        return $stmt->rowCount() > 0;
    }

    /** @inheritDoc */
    public function getTableComment(string $table): string
    {
        $db = $this->configProvider->getDatabase();
        $table = str_replace(['`', '"'], '', $table);
        $db = str_replace(['`', '"'], '', $db ?? '');
        try {
            $sql = 'SELECT TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = :schema AND TABLE_NAME = :tbl LIMIT 1';
            $stmt = $this->getWrappedConnection()->prepare($sql);
            $stmt->execute([':schema' => $db, ':tbl' => $table]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (string) ($row['TABLE_COMMENT'] ?? $row['table_comment'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @inheritDoc */
    public function getDefaultTableAdditional(): string
    {
        return 'ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4';
    }

    /** @inheritDoc */
    public function getTableColumns(string $table): array
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $rows = $this->query("SHOW FULL COLUMNS FROM {$quoted}")->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $list = [];
        foreach ($rows as $row) {
            $field = $row['Field'] ?? $row['field'] ?? '';
            $type = $row['Type'] ?? $row['type'] ?? '';
            $null = strtoupper($row['Null'] ?? $row['null'] ?? 'YES');
            $key = $row['Key'] ?? $row['key'] ?? '';
            $default = $row['Default'] ?? $row['default'] ?? null;
            $extra = $row['Extra'] ?? $row['extra'] ?? '';
            $comment = $row['Comment'] ?? $row['comment'] ?? '';
            $nullable = $null !== 'NO';
            $primaryKey = $key === 'PRI';
            $autoIncrement = stripos($extra, 'auto_increment') !== false;
            $unique = $key === 'UNI';
            [$baseType, $length] = $this->parseColumnTypeMysql($type);
            $list[] = [
                'name' => $field,
                'type' => $baseType,
                'length' => $length,
                'nullable' => $nullable,
                'primary_key' => $primaryKey,
                'auto_increment' => $autoIncrement,
                'default' => $default,
                'comment' => $comment,
                'unique' => $unique,
            ];
        }
        return $list;
    }

    /** @return array{0: string, 1: int|string|null} */
    private function parseColumnTypeMysql(string $type): array
    {
        $type = trim($type);
        if (preg_match('/^(\w+)\s*\(\s*(\d+)\s*\)/', $type, $m)) {
            return [$m[1], (int) $m[2]];
        }
        if (preg_match('/^(\w+)\s*\(\s*(\d+)\s*,\s*(\d+)\s*\)/', $type, $m)) {
            return [$m[1], $m[2] . ',' . $m[3]];
        }
        return [strtolower($type), null];
    }

    /** @inheritDoc */
    public function getTableIndexes(string $table): array
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $rows = $this->query("SHOW INDEX FROM {$quoted}")->fetchArray();
        if (!is_array($rows)) {
            return [];
        }
        $byName = [];
        foreach ($rows as $row) {
            $keyName = $row['Key_name'] ?? $row['key_name'] ?? '';
            $column = $row['Column_name'] ?? $row['column_name'] ?? '';
            $nonUnique = (int) ($row['Non_unique'] ?? $row['non_unique'] ?? 1);
            $seq = (int) ($row['Seq_in_index'] ?? $row['seq_in_index'] ?? 0);
            if ($keyName === 'PRIMARY') {
                continue;
            }
            if (!isset($byName[$keyName])) {
                $byName[$keyName] = ['columns' => [], 'unique' => $nonUnique === 0];
            }
            $byName[$keyName]['columns'][$seq] = $column;
        }
        $list = [];
        foreach ($byName as $name => $data) {
            ksort($data['columns']);
            $list[] = ['name' => $name, 'columns' => array_values($data['columns']), 'unique' => $data['unique']];
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
        $t = $this->getDialect()->quoteTable($table);
        $def = $this->mysqlColumnDef($col);
        return "ALTER TABLE {$t} ADD COLUMN {$def}";
    }

    /** @inheritDoc */
    public function buildAlterModifyColumnSql(string $table, array $col, ?array $existingCol = null): string
    {
        $t = $this->getDialect()->quoteTable($table);
        $def = $this->mysqlColumnDef($col);
        return "ALTER TABLE {$t} MODIFY COLUMN {$def}";
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
        $t = $this->getDialect()->quoteTable($table);
        return "ALTER TABLE {$t} COMMENT '" . str_replace("'", "''", $comment) . "'";
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
            return "ALTER TABLE {$t} ADD UNIQUE {$name} ({$colList})";
        }
        return "ALTER TABLE {$t} ADD INDEX {$name} ({$colList})";
    }

    /** @inheritDoc */
    public function buildDropIndexSql(string $table, string $indexName): string
    {
        $t = $this->getDialect()->quoteTable($table);
        $n = $this->getDialect()->quoteIdentifier($indexName);
        return "ALTER TABLE {$t} DROP INDEX {$n}";
    }

    /** @inheritDoc */
    public function buildAddForeignKeySql(string $table, array $fk): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $name = $d->quoteIdentifier($fk['name'] ?? '');
        $cols = array_map(fn (string $c) => $d->quoteIdentifier($c), $fk['columns'] ?? []);
        $refCols = array_map(fn (string $c) => $d->quoteIdentifier($c), $fk['referencesColumns'] ?? []);
        $refTable = $d->quoteTable($fk['referencesTable'] ?? '');
        $onDelete = !empty($fk['onDeleteCascade']) ? ' ON DELETE CASCADE' : '';
        $onUpdate = !empty($fk['onUpdateCascade']) ? ' ON UPDATE CASCADE' : '';
        return "ALTER TABLE {$t} ADD CONSTRAINT {$name} FOREIGN KEY (" . implode(',', $cols) . ") REFERENCES {$refTable} (" . implode(',', $refCols) . "){$onDelete}{$onUpdate}";
    }

    /** @inheritDoc */
    public function buildDropForeignKeySql(string $table, string $fkName): string
    {
        $t = $this->getDialect()->quoteTable($table);
        $n = $this->getDialect()->quoteIdentifier($fkName);
        return "ALTER TABLE {$t} DROP FOREIGN KEY {$n}";
    }

    private function mysqlColumnDef(array $col): string
    {
        $c = $this->getDialect()->quoteIdentifier($col['name'] ?? '');
        $type = strtolower($col['type'] ?? 'varchar');
        $len = $col['length'] ?? null;
        $typeLen = $type . ($len !== null ? "({$len})" : '');
        $opts = [];
        if (!empty($col['primaryKey'])) {
            $opts[] = 'PRIMARY KEY';
        }
        if (!empty($col['autoIncrement'])) {
            $opts[] = 'AUTO_INCREMENT';
        }
        if (empty($col['nullable']) && empty($col['primaryKey'])) {
            $opts[] = 'NOT NULL';
        }
        if (isset($col['default']) && $col['default'] !== null) {
            $d = $col['default'];
            $opts[] = is_string($d) && strtoupper($d) === 'CURRENT_TIMESTAMP'
                ? 'DEFAULT CURRENT_TIMESTAMP'
                : (is_string($d) ? "DEFAULT '" . str_replace("'", "''", $d) . "'" : "DEFAULT {$d}");
        }
        if (!empty($col['unique']) && empty($col['primaryKey'])) {
            $opts[] = 'UNIQUE';
        }
        $optStr = implode(' ', $opts);
        $comment = isset($col['comment']) && $col['comment'] !== ''
            ? " COMMENT '" . str_replace("'", "''", $col['comment']) . "'"
            : '';
        return "{$c} {$typeLen} {$optStr}{$comment}";
    }

    /** @inheritDoc */
    public function getTableForeignKeys(string $table): array
    {
        $db = $this->configProvider->getDatabase();
        $table = str_replace(['`', '"'], '', $table);
        $db = str_replace(['`', '"'], '', $db ?? '');
        try {
            $sql = "SELECT kcu.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME, rc.DELETE_RULE, rc.UPDATE_RULE
                FROM information_schema.KEY_COLUMN_USAGE kcu
                JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME AND rc.CONSTRAINT_SCHEMA = kcu.TABLE_SCHEMA
                WHERE kcu.TABLE_SCHEMA = :schema AND kcu.TABLE_NAME = :tbl AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
                ORDER BY kcu.ORDINAL_POSITION";
            $stmt = $this->getWrappedConnection()->prepare($sql);
            $stmt->execute([':schema' => $db, ':tbl' => $table]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $byName = [];
        foreach ($rows as $row) {
            $name = $row['CONSTRAINT_NAME'] ?? '';
            $col = $row['COLUMN_NAME'] ?? '';
            $refTable = $row['REFERENCED_TABLE_NAME'] ?? '';
            $refCol = $row['REFERENCED_COLUMN_NAME'] ?? '';
            $onDelete = strtoupper($row['DELETE_RULE'] ?? '');
            $onUpdate = strtoupper($row['UPDATE_RULE'] ?? '');
            if (!isset($byName[$name])) {
                $byName[$name] = [
                    'columns' => [],
                    'ref_table' => $refTable,
                    'ref_columns' => [],
                    'on_delete_cascade' => $onDelete === 'CASCADE',
                    'on_update_cascade' => $onUpdate === 'CASCADE',
                ];
            }
            $byName[$name]['columns'][] = $col;
            $byName[$name]['ref_columns'][] = $refCol;
        }
        $list = [];
        foreach ($byName as $name => $data) {
            $list[] = [
                'name' => $name,
                'columns' => $data['columns'],
                'ref_table' => $data['ref_table'],
                'ref_columns' => $data['ref_columns'],
                'on_delete_cascade' => $data['on_delete_cascade'],
                'on_update_cascade' => $data['on_update_cascade'],
            ];
        }
        return $list;
    }
}
