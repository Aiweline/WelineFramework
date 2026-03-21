<?php

declare(strict_types=1);
/**
 * 鏂囦欢淇℃伅
 * 浣滆€咃細閭逛竾鎵?
 * 缃戝悕锛氱椋庨泚椋?Aiweline)
 * 缃戠珯锛歸ww.aiweline.com/bbs.aiweline.com
 * 宸ュ叿锛歅hpStorm
 * 鏃ユ湡锛?021/6/21
 * 鏃堕棿锛?1:45
 * 鎻忚堪锛氭鏂囦欢婧愮爜鐢盇iweline锛堢鏋泚椋烇級寮€鍙戯紝璇峰嬁闅忔剰淇敼婧愮爜锛?
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
use Weline\Framework\Database\Exception\DbException;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Manager\ObjectManager;

final class Connector extends Query implements ConnectorInterface
{
    public function __construct(
        private readonly ConfigProvider $configProvider
    ) {
        // FIXME 鍋滄浣跨敤锛岄€傞厤涓嶅畬鍏紝浠呮彁渚汸gsql閫傞厤鍣紝鍚庣画鐗堟湰鍙兘绉婚櫎
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

    /** Connector 鑷韩鍗虫寔鏈夎繛鎺ワ紝浣滀负 Query 浣跨敤鏃剁洿鎺ヨ繑鍥烇紝閬垮厤渚濊禆 SqlTrait 鐨?$this->connection */
    public function getConnectionInterface(): DbConnectionInterface
    {
        return $this->getWrappedConnection();
    }

    protected ?PDO $link = null;
    protected ?DbConnectionInterface $wrappedConnection = null;
    protected ?Query $query = null;
    protected bool $fromPool = false; // 鏍囪杩炴帴鏄惁鏉ヨ嚜杩炴帴姹?

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
        
        // 浠庤繛鎺ユ睜鑾峰彇杩炴帴
        $this->link = ConnectionPool::getConnection(
            $this->configProvider,
            function () use ($db_type) {
                $dsn = "{$db_type}:{$this->configProvider->getData('path')}";
                try {
                    $options = $this->configProvider->getOptions();
                    // SQLite 涔熸敮鎸佹寔涔呰繛鎺ワ紝浣嗛渶瑕佺‘淇濋敊璇ā寮忚缃?
                    if (!isset($options[PDO::ATTR_ERRMODE])) {
                        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
                    }
                    $connection = new PDO($dsn, null, null, $options);
                    # PRAGMA case_sensitive_like = ON;  -- 寮€鍚ぇ灏忓啓鏁忔劅鐨凩IKE鏌ヨ
                    $connection->exec('PRAGMA case_sensitive_like = OFF; -- 鍏抽棴澶у皬鍐欐晱鎰熺殑LIKE鏌ヨ锛堥粯璁わ級');
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
            w_log_warning(__('SQLite 鐗堟湰鏍￠獙鏈€氳繃锛堣繛鎺ュ凡寤虹珛锛屽崌绾у彲缁х画锛夛細%{1}', [$e->getMessage()]), [], 'database_version.log');
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
     * 鏋愭瀯鍑芥暟锛氱‘淇濊繛鎺ュ湪浣跨敤鍚庤褰掕繕鍒拌繛鎺ユ睜
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @deprecated 璇蜂娇鐢?getWrappedConnection() 鑾峰彇杩炴帴骞惰皟鐢ㄥ叾鏂规硶锛屽悗缁増鏈彲鑳界Щ闄?
     */
    public function getLink(): PDO
    {
        $this->create();
        return $this->link;
    }

    /**
     * 浣跨敤 SQLite 鍘熺敓 REINDEX 閲嶅缓琛ㄧ储寮曪紙@since SQLite 3.45+锛?
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
        // 鑾峰彇琛ㄧ殑绱㈠紩鍒楄〃
        $indexList = $this->query("PRAGMA index_list('$table')")->fetch();

        $indexFields = [];

        foreach ($indexList as $index) {
            // 鑾峰彇绱㈠紩鐨勮缁嗕俊鎭?
            $indexInfo = $this->query("PRAGMA index_info('{$index['name']}')")->fetch();

            foreach ($indexInfo as $info) {
                $indexFields[] = [
                    'Table' => $table,
                    'Non_unique' => $index['unique'] ? 0 : 1,
                    'Key_name' => $index['name'],
                    'Seq_in_index' => $info['seqno'],
                    'Column_name' => $info['name'],
                    'Collation' => 'A', // SQLite 榛樿浣跨敤浜岃繘鍒舵帓搴?
                ];
            }
        }

        return $indexFields;
    }

    public function dev()
    {
        return "
# 鏌ヨ琛ㄧ殑绱㈠紩瀛楁骞舵嫾鎺ユ垚绱㈠紩閲嶅缓SQL
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
     * @DESC          # 璇诲彇鍒涘缓琛⊿QL
     *
     * @AUTH    绉嬫灚闆侀
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/9/5 22:08
     * 鍙傛暟鍖猴細
     *
     * @param string $table_name
     *
     * @return mixed
     */
    public function getCreateTableSql(string $table_name): string
    {
        $table_name = self::processName($table_name);
        // 鑾峰彇琛ㄧ殑鍏冩暟鎹?
        $tableMeta = $this->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table_name'")->fetch();

        if ($tableMeta === false) {
            throw new \Exception("Table '$table_name' does not exist.");
        }
        // 杩斿洖 CREATE TABLE 璇彞
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
        // SQLite connector 宸插純鐢紙鏋勯€犲嚱鏁?throw锛夛紝姝ゅ闄嶇骇涓洪€愯〃妫€鏌?
        return array_values(array_filter(
            array_map(fn($t) => trim(str_replace(['`', '"'], '', (string) $t)), $tableNames),
            fn($t) => $t !== '' && $this->tableExist($t)
        ));
    }

    public function getVersion(): string
    {
        // 鏌ヨ鏁版嵁搴撶増鏈彿
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
        throw new DbException(__('SQLite 不支持表注释 DDL：ALTER TABLE COMMENT %{1}', [$table]));
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
        throw new DbException(__('SQLite 不支持添加外键 DDL：ALTER TABLE ADD CONSTRAINT %{1}', [$table]));
    }

    /** @inheritDoc */
    public function buildDropForeignKeySql(string $table, string $fkName): string
    {
        throw new DbException(__('SQLite 不支持删除外键 DDL：ALTER TABLE DROP CONSTRAINT %{1}.%{2}', [$table, $fkName]));
    }

    private function sqliteColumnDef(array $col): string
    {
        $c = $this->getDialect()->quoteIdentifier($col['name'] ?? '');
        $type = strtoupper($col['type'] ?? 'TEXT');
        $len = $col['length'] ?? null;
        $typeLen = $len ? "{$type}({$len})" : $type;
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
