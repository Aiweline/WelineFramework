<?php

declare(strict_types=1);
/**
 * 文件信息
 * 作者：邹万才
 * 网名：秋风雁飞(Aiweline)
 * 网站：www.aiweline.com/bbs.aiweline.com
 * 工具：PhpStorm
 * 日期：2025/01/XX
 * 时间：11:45
 * 描述：PostgreSQL 数据库连接适配器
 */

namespace Weline\Framework\Database\Connection\Adapter\Pgsql;

use PDO;
use PDOException;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlIdentifierFormatter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Dialect\PgsqlTableNameStrategy;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Alter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Create;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Compiler\Dialect\PgsqlDialect;
use Weline\Framework\Database\Connection\ConnectionInterface as DbConnectionInterface;
use Weline\Framework\Database\Connection\PdoConnection;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\Connection\Pool\ConnectionPool;
use Weline\Framework\Database\DbManager\ConfigProvider;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Helper\Standar;
use Weline\Framework\Manager\ObjectManager;

final class Connector extends Query implements ConnectorInterface
{
    /** @inheritDoc */
    public function whereRaw(string $sql, string $where_logic = 'AND'): QueryInterface
    {
        return parent::whereRaw($sql, $where_logic);
    }

    public function __construct(
        private readonly ?ConfigProvider $configProvider
    ) {
        $identifierFormatter = new PgsqlIdentifierFormatter();
        $tableStrategy = new PgsqlTableNameStrategy(
            $identifierFormatter,
            $this->configProvider->getPrefix() ?: '',
            'public'
        );
        parent::__construct(
            $identifierFormatter,
            $tableStrategy
        );
        $this->db_name = $this->configProvider->getDatabase() ?: 'public';
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
    protected ?PDO $_original_pdo = null; // 原始 PDO 引用，用于克隆后的对象访问

    private ?PgsqlDialect $dialect = null;

    private function getDialect(): PgsqlDialect
    {
        return $this->dialect ??= new PgsqlDialect();
    }

    public function create(): static
    {
        if ($this->link !== null) {
            // 🔧 修复：如果连接已存在，确保引用也被设置
            $this->_original_pdo = $this->link;
            return $this;
        }

        $db_type = $this->configProvider->getDbType();
        if (!in_array($db_type, PDO::getAvailableDrivers())) {
            $availableDrivers = implode(',', PDO::getAvailableDrivers());
            $installHint = '';
            if (PHP_OS_FAMILY === 'Windows') {
                $installHint = ' ' . __('Windows: Ensure php_pdo_pgsql.dll and php_pgsql.dll are enabled in php.ini.');
            } elseif (PHP_OS_FAMILY === 'Linux') {
                $installHint = ' ' . __('Linux: Run "apt-get install php-pgsql" or "yum install php-pgsql" to install the extension, then restart PHP-FPM/Apache.');
            }
            throw new LinkException(__('PostgreSQL 驱动不存在：%{1}。可用驱动列表：%{2}。%{3}更多驱动配置请转到 php.ini 中开启。', [$db_type, $availableDrivers, $installHint]));
        }

        // 从连接池获取连接
        $this->link = ConnectionPool::getConnection(
            $this->configProvider,
            function () {
                // PostgreSQL DSN 格式: pgsql:host=hostname;port=5432;dbname=database;user=username;password=password
                $dsn = "pgsql:host={$this->configProvider->getHostName()};port={$this->configProvider->getHostPort()};dbname={$this->configProvider->getDatabase()}";
                if ($this->configProvider->getCharset()) {
                    $dsn .= ";options='--client_encoding={$this->configProvider->getCharset()}'";
                }
                
                try {
                    $connection = new PDO($dsn, $this->configProvider->getUsername(), $this->configProvider->getPassword(), $this->configProvider->getOptions());
                    // 确保错误模式设置为异常模式（即使配置中已设置，这里也明确设置一次，确保生效）
                    $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    if ($this->configProvider->getPreSql()) {
                        $connection->exec($this->configProvider->getPreSql());
                    }
                    // 设置字符集
                    if ($this->configProvider->getCharset()) {
                        $connection->exec("SET NAMES '{$this->configProvider->getCharset()}'");
                    }
                    return $connection;
                } catch (PDOException $e) {
                    throw new LinkException($e->getMessage());
                }
            }
        );
        $this->_original_pdo = $this->link;
        $this->fromPool = true;
        try {
            $this->getDialect()->validateVersion((string)$this->link->getAttribute(PDO::ATTR_SERVER_VERSION));
        } catch (\Throwable $e) {
            w_log_warning(__('PostgreSQL 版本校验未通过（连接已建立，升级可继续）：%{1}', [$e->getMessage()]), [], 'database_version.log');
        }
        $this->wrappedConnection = new PdoConnection($this->link, 'pgsql');
        return $this;
    }

    public function getWrappedConnection(): DbConnectionInterface
    {
        $this->create();
        if ($this->wrappedConnection === null) {
            $this->wrappedConnection = new PdoConnection($this->link, 'pgsql');
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
        if ($this->link === null) {
            throw new LinkException(__('数据库连接未初始化'));
        }
        
        // 🔧 修复：直接返回原始 PDO 对象，不再使用包装器
        // SQL 转换逻辑已移到 Query 类中处理
        return $this->link;
    }

    public function reindex(string $table): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        if (empty($schema)) {
            $schema = 'public';
        }
        
        // PostgreSQL 重建索引
        $sql = "REINDEX TABLE \"{$schema}\".\"{$table}\"";
        try {
            $this->query($sql)->fetch();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getIndexFields(string $table): array
    {
        $table = str_replace(['`', '"'], '', $table);
        $schema = 'public';
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        
        // PostgreSQL 查询索引信息
        $sql = <<<SQL
SELECT 
    i.relname AS "Key_name",
    a.attname AS "Column_name",
    ix.indisunique AS "Non_unique",
    a.attnum AS "Seq_in_index",
    CASE WHEN ix.indisunique THEN 0 ELSE 1 END AS "Non_unique"
FROM 
    pg_class t,
    pg_class i,
    pg_index ix,
    pg_attribute a,
    pg_namespace n
WHERE 
    t.oid = ix.indrelid
    AND i.oid = ix.indexrelid
    AND a.attrelid = t.oid
    AND a.attnum = ANY(ix.indkey)
    AND t.relkind = 'r'
    AND n.oid = t.relnamespace
    AND n.nspname = '{$schema}'
    AND t.relname = '{$table}'
ORDER BY 
    i.relname, a.attnum
SQL;
        
        $result = $this->query($sql)->fetchArray();
        return $result ?? [];
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
        $table_name = str_replace(['`', '"'], '', $table_name);
        $schema = 'public';
        if (str_contains($table_name, '.')) {
            list($schema, $table_name) = explode('.', $table_name);
        }
        
        // PostgreSQL 查询建表语句
        $sql = <<<SQL
SELECT 
    'CREATE TABLE ' || quote_ident(n.nspname) || '.' || quote_ident(c.relname) || E' (\n' ||
    string_agg(
        '    ' || quote_ident(a.attname) || ' ' || 
        pg_catalog.format_type(a.atttypid, a.atttypmod) ||
        CASE 
            WHEN a.attnotnull THEN ' NOT NULL'
            ELSE ''
        END,
        E',\n'
        ORDER BY a.attnum
    ) || E'\n);'
FROM 
    pg_catalog.pg_class c
    JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_catalog.pg_attribute a ON a.attrelid = c.oid
WHERE 
    n.nspname = '{$schema}'
    AND c.relname = '{$table_name}'
    AND a.attnum > 0
    AND NOT a.attisdropped
GROUP BY 
    n.nspname, c.relname
SQL;
        
        $result = $this->query($sql)->fetch();
        return $result[0]['?column?'] ?? '';
    }

    public function getConfigProvider(): ConfigProviderInterface
    {
        return $this->configProvider;
    }

    public function createTable(): Sql\Table\CreateInterface
    {
        return ObjectManager::getInstance(Create::class)->setConnection($this);
    }

    public function alterTable(): Sql\Table\AlterInterface
    {
        return ObjectManager::getInstance(Alter::class)->setConnection($this);
    }

    /** @inheritDoc 方言：PostgreSQL 使用 CASCADE 自动清理依赖 */
    public function dropTableIfExists(string $table): void
    {
        $quoted = $this->quoteTable(str_replace(['`', '"'], '', $table));
        $this->query("DROP TABLE IF EXISTS {$quoted} CASCADE")->fetch();
    }

    public function tableExist(string $table_name): bool
    {
        try {
            // 清理表名，移除引号
            $table_name = str_replace(['`', '"'], '', $table_name);
            $dbName = $this->configProvider->getDatabase();
            $schema = 'public';
            $table = $table_name;
            
            if (str_contains($table_name, '.')) {
                $parts = explode('.', $table_name, 2);
                $firstPart = $parts[0];
                
                // 如果第一部分是数据库名，移除它，使用 public schema
                if ($firstPart === $dbName) {
                    $table = $parts[1] ?? $parts[0];
                    $schema = 'public';
                } else {
                    // 第一部分是 schema 名
                    $schema = $firstPart;
                    $table = $parts[1] ?? $parts[0];
                }
            }
            
            // 使用 prepared statement 避免 SQL 注入，并确保不会报错
            $sql = "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table)";
            $stmt = $this->getLink()->prepare($sql);
            if ($stmt === false) {
                return false;
            }
            
            // 使用 @ 抑制可能的警告，然后检查执行结果
            $executed = @$stmt->execute([
                ':schema' => $schema,
                ':table' => $table
            ]);
            
            if (!$executed) {
                return false;
            }
            
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (bool)($result['exists'] ?? false);
        } catch (\Exception $exception) {
            // 任何异常都返回 false，不报错
            return false;
        } catch (\Throwable $throwable) {
            // 捕获所有可抛出对象，确保不会报错
            return false;
        }
    }

    public function getVersion(): string
    {
        // 查询数据库版本号
        $query = 'SELECT version() AS version';
        $stmt = $this->link->prepare($query);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['version'] ?? '';
    }

    public function hasField(string $table, string $field): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        $field = str_replace(['`', '"'], '', $field);
        $dbName = $this->configProvider->getDatabase();
        $schema = 'public';
        
        if (str_contains($table, '.')) {
            $parts = explode('.', $table);
            $firstPart = $parts[0];
            
            // 如果第一部分是数据库名，移除它，使用 public schema
            if ($firstPart === $dbName) {
                $table = $parts[1] ?? $parts[0];
                $schema = 'public';
            } else {
                // 第一部分是 schema 名
                $schema = $firstPart;
                $table = $parts[1] ?? $parts[0];
            }
        }
        $schema = str_replace("'", "''", $schema);
        $table = str_replace("'", "''", $table);
        $field = str_replace("'", "''", $field);
        $sql = "SELECT EXISTS (SELECT 1 FROM information_schema.columns WHERE LOWER(table_schema) = LOWER('{$schema}') AND LOWER(table_name) = LOWER('{$table}') AND LOWER(column_name) = LOWER('{$field}'))";
        $stmt = $this->getLink()->query($sql);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (bool)($result['exists'] ?? false);
    }

    public function hasIndex(string $table, string $idx_name): bool
    {
        $table = str_replace(['`', '"'], '', $table);
        $idx_name = Standar::getIndexName($table, $idx_name);
        $schema = 'public';
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        
        $sql = "SELECT EXISTS (SELECT FROM pg_indexes WHERE schemaname = '{$schema}' AND tablename = '{$table}' AND indexname = '{$idx_name}')";
        $result = $this->query($sql)->fetch();
        return (bool)($result[0]['exists'] ?? false);
    }

    public function query(string $sql): QueryInterface
    {
        if (!$this->link) {
            $this->create();
        }
        return parent::query($sql);
    }

    public function getQuery(): QueryInterface
    {
        return $this;
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

    /**
     * @inheritDoc
     * PostgreSQL: 向非空表添加 NOT NULL 列时，需提供 DEFAULT，否则报 "contains null values"。
     * 若模型未声明 default，按类型生成临时默认值（varchar→''，int→0），以通过 ADD COLUMN。
     */
    public function buildAlterAddColumnSql(string $table, array $col): string
    {
        $dialect = $this->getDialect();
        $t = $dialect->quoteTable($table);
        $c = $dialect->quoteIdentifier($col['name'] ?? '');
        $type = $this->pgsqlTypeFromCol($col);
        $clauses = [$c . ' ' . $type];
        $nullable = !empty($col['nullable']);
        $hasDefault = isset($col['default']) && $col['default'] !== null;
        $isSerial = (!empty($col['autoIncrement']) || !empty($col['primaryKey']))
            && in_array(strtolower($col['type'] ?? ''), ['int', 'integer', 'bigint', 'smallint', 'tinyint'], true);
        if ($hasDefault) {
            $defVal = $col['default'];
            $clauses[] = is_string($defVal) && strtoupper($defVal) === 'CURRENT_TIMESTAMP'
                ? 'DEFAULT CURRENT_TIMESTAMP'
                : (is_string($defVal) ? "DEFAULT '" . str_replace("'", "''", $defVal) . "'" : "DEFAULT {$defVal}");
        } elseif (!$nullable && !$isSerial) {
            $baseType = strtolower($col['type'] ?? 'varchar');
            $clauses[] = match (true) {
                in_array($baseType, ['varchar', 'char', 'text', 'longtext', 'mediumtext', 'tinytext'], true) => "DEFAULT ''",
                in_array($baseType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => 'DEFAULT 0',
                in_array($baseType, ['decimal', 'numeric', 'float', 'double'], true) => 'DEFAULT 0',
                $baseType === 'bool' || $baseType === 'boolean' => 'DEFAULT false',
                $baseType === 'date' => "DEFAULT '1970-01-01'",
                in_array($baseType, ['datetime', 'timestamp', 'timestamptz'], true) => "DEFAULT '1970-01-01 00:00:00'",
                default => "DEFAULT ''",
            };
        }
        if (!$nullable) {
            $clauses[] = 'NOT NULL';
        }
        return "ALTER TABLE {$t} ADD COLUMN " . implode(' ', $clauses);
    }

    /**
     * @inheritDoc
     * PostgreSQL: 设置 NOT NULL 前需先将现有 NULL 填充为默认值，否则报 "contains null values"。
     * 类型变更时，UPDATE 填充值必须与当前列类型兼容，使用 $existingCol 生成兼容值。
     */
    public function buildAlterModifyColumnSql(string $table, array $col, ?array $existingCol = null): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $c = $d->quoteIdentifier($col['name'] ?? '');
        $type = $this->pgsqlTypeFromCol($col, true); // ALTER COLUMN 不支持 SERIAL 伪类型，使用 INTEGER/BIGINT
        $usingExpr = $this->pgsqlModifyColumnUsingExpr($c, $type, $col, $existingCol);
        $parts = ["ALTER COLUMN {$c} TYPE {$type} USING {$usingExpr}"];
        $setNotNull = empty($col['nullable']);
        $prefix = '';
        if ($setNotNull) {
            $fillCol = $existingCol ?? $col;
            $fillVal = $this->pgsqlDefaultForNullFill($fillCol);
            $prefix = "UPDATE {$t} SET {$c} = {$fillVal} WHERE {$c} IS NULL;\n";
        }
        $parts[] = $setNotNull ? "ALTER COLUMN {$c} SET NOT NULL" : "ALTER COLUMN {$c} DROP NOT NULL";
        if (!empty($col['autoIncrement'])) {
            [$schema, $tableName] = $this->parseSchemaTable($table);
            $colName = (string) ($col['name'] ?? '');
            $seqName = $tableName . '_' . $colName . '_seq';
            $seqRef = $schema . '.' . $seqName;
            $parts[] = "ALTER COLUMN {$c} SET DEFAULT nextval('" . str_replace("'", "''", $seqRef) . "'::regclass)";
            $createSeq = 'CREATE SEQUENCE IF NOT EXISTS ' . $d->quoteIdentifier($schema) . '.' . $d->quoteIdentifier($seqName);
            return $prefix . $createSeq . ";\nALTER TABLE {$t} " . implode(', ', $parts);
        }
        if (isset($col['default']) && $col['default'] !== null) {
            $defVal = $col['default'];
            $def = is_string($defVal) && strtoupper($defVal) === 'CURRENT_TIMESTAMP'
                ? 'CURRENT_TIMESTAMP'
                : (is_string($defVal) ? "'" . str_replace("'", "''", $defVal) . "'" : (string) $defVal);
            $parts[] = "ALTER COLUMN {$c} SET DEFAULT {$def}";
        } else {
            $parts[] = "ALTER COLUMN {$c} DROP DEFAULT";
        }
        return $prefix . "ALTER TABLE {$t} " . implode(', ', $parts);
    }

    /** 用于 MODIFY 时填充 NULL 的默认值（按类型）。UPDATE 只能用字面量，不能用 nextval 等表达式。 */
    private function pgsqlDefaultForNullFill(array $col): string
    {
        $baseType = strtolower($col['type'] ?? 'varchar');
        $isSerial = !empty($col['autoIncrement']) || (is_string($col['default'] ?? '') && stripos((string) $col['default'], 'nextval') !== false);
        if ($isSerial || in_array($baseType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true)) {
            return '0';
        }
        if (isset($col['default']) && $col['default'] !== null) {
            $d = $col['default'];
            if (is_string($d) && strtoupper($d) === 'CURRENT_TIMESTAMP') {
                return 'CURRENT_TIMESTAMP';
            }
            if (!is_string($d) || stripos($d, 'nextval') === false) {
                $val = is_string($d) ? $d : (string) $d;
                $maxLen = isset($col['length']) ? (int) $col['length'] : null;
                if ($maxLen !== null && $maxLen > 0 && strlen($val) > $maxLen) {
                    $val = substr($val, 0, $maxLen);
                }
                return "'" . str_replace("'", "''", $val) . "'";
            }
        }
        $isDateLike = $baseType === 'date'
            || in_array($baseType, ['datetime', 'timestamp', 'timestamptz'], true)
            || str_contains($baseType, 'timestamp');
        return match (true) {
            in_array($baseType, ['varchar', 'char', 'text', 'longtext', 'mediumtext', 'tinytext'], true) => "''",
            in_array($baseType, ['int', 'integer', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => '0',
            in_array($baseType, ['decimal', 'numeric', 'float', 'double'], true) => '0',
            $baseType === 'bool' || $baseType === 'boolean' => 'false',
            $baseType === 'date' => "'1970-01-01'",
            $isDateLike => "'1970-01-01 00:00:00'",
            default => "''",
        };
    }

    /**
     * @inheritDoc
     * PostgreSQL: 使用 CASCADE 自动删除列上的外键、索引等依赖，避免手动 DROP CONSTRAINT 时约束名不匹配
     *（PG 自生成约束名如 tablename_columnname_fkey，与模型声明的列名可能不同）
     */
    public function buildAlterDropColumnSql(string $table, string $colName): string
    {
        $d = $this->getDialect();
        $t = $d->quoteTable($table);
        $c = $d->quoteIdentifier($colName);
        return "ALTER TABLE {$t} DROP COLUMN IF EXISTS {$c} CASCADE";
    }

    /** @inheritDoc */
    public function buildAlterTableCommentSql(string $table, string $comment): string
    {
        $t = $this->getDialect()->quoteTable($table);
        $c = $comment !== '' ? "'" . str_replace("'", "''", $comment) . "'" : 'NULL';
        return "COMMENT ON TABLE {$t} IS {$c}";
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
        $usingPart = (!empty($idx['method']) && strtoupper($idx['method']) !== 'BTREE') ? ' USING ' . $idx['method'] : '';
        if ($type === 'UNIQUE') {
            return "CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON {$t}{$usingPart} ({$colList})";
        }
        return "CREATE INDEX IF NOT EXISTS {$name} ON {$t}{$usingPart} ({$colList})";
    }

    /**
     * @inheritDoc
     * PostgreSQL: UNIQUE 约束的索引必须用 DROP CONSTRAINT，不能 DROP INDEX。
     * 优先 DROP CONSTRAINT（约束删除后索引自动删除）；若非约束则需 DROP INDEX。
     * 先尝试 DROP CONSTRAINT，再 DROP INDEX（对约束型索引后者为 no-op）。
     */
    public function buildDropIndexSql(string $table, string $indexName): string
    {
        $n = $this->getDialect()->quoteIdentifier($indexName);
        return "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$n} CASCADE;\nDROP INDEX IF EXISTS {$n}";
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

    /**
     * @inheritDoc
     * PostgreSQL: 使用 IF EXISTS 避免约束名不匹配时报错（PG 自生成名如 tablename_columnname_fkey，
     * 与模型声明的 FK 名可能不同）；CASCADE 删除依赖该约束的对象。
     */
    public function buildDropForeignKeySql(string $table, string $fkName): string
    {
        $t = $this->getDialect()->quoteTable($table);
        $n = $this->getDialect()->quoteIdentifier($fkName);
        return "ALTER TABLE {$t} DROP CONSTRAINT IF EXISTS {$n} CASCADE";
    }

    /** MODIFY COLUMN 的 USING 表达式。varchar(n)/char(n) 时截断；date/timestamp 时需处理空字符串 ''（PostgreSQL ''::date 报错）。 */
    private function pgsqlModifyColumnUsingExpr(string $quotedCol, string $pgType, array $col, ?array $existingCol): string
    {
        $len = $col['length'] ?? null;
        if ($len !== null && $len > 0 && (str_starts_with($pgType, 'VARCHAR') || str_starts_with($pgType, 'CHAR'))) {
            return "LEFT({$quotedCol}::text, {$len})::{$pgType}";
        }
        $pgUpper = strtoupper($pgType);
        if ($pgUpper === 'DATE' || str_starts_with($pgUpper, 'DATE(')) {
            return "CASE WHEN {$quotedCol} IS NULL OR TRIM({$quotedCol}::text) = '' THEN '1970-01-01'::date ELSE ({$quotedCol}::text)::date END";
        }
        if ($pgUpper === 'TIMESTAMP' || $pgUpper === 'TIMESTAMPTZ') {
            return "CASE WHEN {$quotedCol} IS NULL OR TRIM({$quotedCol}::text) = '' OR (TRIM({$quotedCol}::text) !~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}') THEN '1970-01-01 00:00:00'::timestamp ELSE ({$quotedCol} AT TIME ZONE 'UTC') END";
        }
        return "{$quotedCol}::{$pgType}";
    }

    /**
     * @param array $col 列定义
     * @param bool $forAlterModify 若为 true，不使用 SERIAL（PostgreSQL ALTER COLUMN 不支持 SERIAL 伪类型）
     */
    private function pgsqlTypeFromCol(array $col, bool $forAlterModify = false): string
    {
        $type = strtolower($col['type'] ?? 'varchar');
        $len = $col['length'] ?? null;
        if (!$forAlterModify && !empty($col['autoIncrement']) && in_array($type, ['int', 'integer', 'bigint', 'smallint', 'tinyint'], true)) {
            return match ($type) {
                'bigint' => 'BIGSERIAL',
                'smallint', 'tinyint' => 'SMALLSERIAL',
                default => 'SERIAL',
            };
        }
        $lenPart = $len !== null ? "({$len})" : '';
        $pgType = match ($type) {
            'int', 'integer' => 'INTEGER',
            'bigint' => 'BIGINT',
            'smallint' => 'SMALLINT',
            'tinyint' => 'SMALLINT',
            'mediumint' => 'INTEGER',
            'varchar' => 'VARCHAR' . $lenPart,
            'char' => 'CHAR' . $lenPart,
            'text', 'longtext', 'mediumtext', 'tinytext' => 'TEXT',
            'blob', 'longblob', 'mediumblob', 'tinyblob' => 'BYTEA',
            'datetime' => 'TIMESTAMP',
            'timestamp' => 'TIMESTAMP',
            'json' => 'JSONB',
            'decimal', 'numeric' => 'DECIMAL' . $lenPart,
            'float', 'double' => 'DOUBLE PRECISION',
            'bool', 'boolean' => 'BOOLEAN',
            default => strtoupper($type) . $lenPart,
        };
        return $pgType;
    }

    /** @return array{0: string, 1: string} [schema, table] */
    private function parseSchemaTable(string $table): array
    {
        $table = str_replace(['`', '"'], '', $table);
        if (str_contains($table, '.')) {
            $parts = explode('.', $table, 2);
            return [trim($parts[0]) ?: 'public', trim($parts[1])];
        }
        return ['public', $table];
    }

    /** @inheritDoc */
    public function getTableComment(string $table): string
    {
        [$schema, $tableName] = $this->parseSchemaTable($table);
        if ($tableName === '') {
            return '';
        }
        try {
            $sql = "SELECT obj_description(c.oid) AS comment FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = :schema AND c.relname = :tbl AND c.relkind = 'r' LIMIT 1";
            $stmt = $this->getWrappedConnection()->prepare($sql);
            $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return (string) ($row['comment'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /** @inheritDoc */
    public function getTableColumns(string $table): array
    {
        [$schema, $tableName] = $this->parseSchemaTable($table);
        if ($tableName === '') {
            return [];
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        try {
            $sql = "SELECT column_name, data_type, character_maximum_length, numeric_precision, is_nullable, column_default
                FROM information_schema.columns
                WHERE table_schema = :schema AND table_name = :tbl
                ORDER BY ordinal_position";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        if ($rows === []) {
            return [];
        }

        $oidSql = "SELECT c.oid FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = :schema AND c.relname = :tbl AND c.relkind = 'r' LIMIT 1";
        $oidStmt = $pdo->prepare($oidSql);
        $oidStmt->execute([':schema' => $schema, ':tbl' => $tableName]);
        $tableOid = $oidStmt->fetchColumn();
        $pkCols = [];
        $uniqueCols = [];
        if ($tableOid !== false) {
            $conSql = "SELECT c.contype, a.attname
                FROM pg_constraint c
                CROSS JOIN unnest(c.conkey) AS conkey_attnum
                JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = conkey_attnum AND a.attnum > 0 AND NOT a.attisdropped
                WHERE c.conrelid = :oid AND c.contype IN ('p','u')";
            $conStmt = $pdo->prepare($conSql);
            $conStmt->execute([':oid' => $tableOid]);
            while (($r = $conStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $col = $r['attname'] ?? '';
                if (($r['contype'] ?? '') === 'p') {
                    $pkCols[$col] = true;
                } else {
                    $uniqueCols[$col] = true;
                }
            }
        }

        $commentSql = "SELECT a.attname, col_description(c.oid, a.attnum) AS col_comment
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace JOIN pg_attribute a ON a.attrelid = c.oid
            WHERE n.nspname = :schema AND c.relname = :tbl AND c.relkind = 'r' AND a.attnum > 0 AND NOT a.attisdropped";
        $commentStmt = $pdo->prepare($commentSql);
        $commentStmt->execute([':schema' => $schema, ':tbl' => $tableName]);
        $comments = [];
        while (($r = $commentStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
            $comments[$r['attname'] ?? ''] = (string) ($r['col_comment'] ?? '');
        }

        $list = [];
        foreach ($rows as $row) {
            $field = $row['column_name'] ?? '';
            $dataType = $row['data_type'] ?? '';
            $charLen = $row['character_maximum_length'] ?? null;
            $numPrec = $row['numeric_precision'] ?? null;
            $length = $charLen !== null ? (int) $charLen : ($numPrec !== null ? (int) $numPrec : null);
            $nullable = strtoupper($row['is_nullable'] ?? 'YES') !== 'NO';
            $default = $row['column_default'] ?? null;
            $autoIncrement = $default !== null && stripos((string) $default, 'nextval') !== false;
            $comment = $comments[$field] ?? '';
            $primaryKey = isset($pkCols[$field]);
            $unique = isset($uniqueCols[$field]);

            $pgType = strtolower($dataType);
            $baseType = $pgType;
            if ($pgType === 'character varying') {
                $baseType = 'varchar';
            } elseif ($pgType === 'integer') {
                $baseType = 'int';
            } elseif (in_array($pgType, ['bigint', 'smallint'], true)) {
                $baseType = $pgType;
            } elseif ($pgType === 'double precision') {
                $baseType = 'double';
            } elseif ($pgType === 'timestamp without time zone' || $pgType === 'timestamp with time zone') {
                $baseType = 'datetime';
            } elseif ($pgType === 'text') {
                $baseType = 'text';
            }
            if ($length === null && $charLen === null && $numPrec !== null) {
                $length = (int) $numPrec;
            }

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

    /** @inheritDoc */
    public function getTableIndexes(string $table): array
    {
        [$schema, $tableName] = $this->parseSchemaTable($table);
        if ($tableName === '') {
            return [];
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        try {
            $sql = "SELECT i.relname AS index_name, a.attname AS column_name, k.ord AS seq, ix.indisunique
                FROM pg_index ix
                JOIN pg_class t ON t.oid = ix.indrelid
                JOIN pg_namespace n ON n.oid = t.relnamespace
                JOIN pg_class i ON i.oid = ix.indexrelid
                JOIN LATERAL unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord) ON true
                JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = k.attnum AND a.attnum > 0 AND NOT a.attisdropped
                WHERE n.nspname = :schema AND t.relname = :tbl AND t.relkind = 'r' AND NOT ix.indisprimary
                ORDER BY i.relname, k.ord";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $byName = [];
        foreach ($rows as $row) {
            $keyName = $row['index_name'] ?? '';
            $column = $row['column_name'] ?? '';
            $seq = (int) ($row['seq'] ?? 0);
            $unique = (bool) ($row['indisunique'] ?? false);
            if (!isset($byName[$keyName])) {
                $byName[$keyName] = ['columns' => [], 'unique' => $unique];
            }
            $byName[$keyName]['columns'][$seq] = $column;
        }
        $list = [];
        foreach ($byName as $name => $data) {
            ksort($data['columns']);
            $list[] = [
                'name' => $name,
                'columns' => array_values($data['columns']),
                'unique' => $data['unique'],
            ];
        }
        return $list;
    }

    /** @inheritDoc */
    public function getTableForeignKeys(string $table): array
    {
        [$schema, $tableName] = $this->parseSchemaTable($table);
        if ($tableName === '') {
            return [];
        }
        $pdo = $this->getWrappedConnection()->getPdo();
        try {
            $sql = "SELECT
                    kcu.constraint_name,
                    kcu.column_name,
                    rcu.table_name AS ref_table,
                    rcu.column_name AS ref_column,
                    rc.delete_rule,
                    rc.update_rule
                FROM information_schema.key_column_usage kcu
                JOIN information_schema.referential_constraints rc
                    ON rc.constraint_name = kcu.constraint_name AND rc.constraint_schema = kcu.table_schema
                JOIN information_schema.key_column_usage rcu
                    ON rcu.constraint_name = rc.unique_constraint_name AND rcu.table_schema = rc.unique_constraint_schema AND rcu.ordinal_position = kcu.ordinal_position
                WHERE kcu.table_schema = :schema AND kcu.table_name = :tbl
                ORDER BY kcu.constraint_name, kcu.ordinal_position";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':schema' => $schema, ':tbl' => $tableName]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            return [];
        }
        $byName = [];
        foreach ($rows as $row) {
            $name = $row['constraint_name'] ?? '';
            $col = $row['column_name'] ?? '';
            $refTable = $row['ref_table'] ?? '';
            $refCol = $row['ref_column'] ?? '';
            $onDelete = strtoupper($row['delete_rule'] ?? '');
            $onUpdate = strtoupper($row['update_rule'] ?? '');
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

    /** @inheritDoc */
    public function getDefaultTableAdditional(): string
    {
        return '';
    }
}

