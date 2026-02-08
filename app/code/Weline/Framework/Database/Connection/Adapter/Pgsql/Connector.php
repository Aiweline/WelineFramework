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

    protected ?PDO $link = null;
    protected ?DbConnectionInterface $wrappedConnection = null;
    protected ?Query $query = null;
    protected bool $fromPool = false; // 标记连接是否来自连接池
    protected ?PDO $_original_pdo = null; // 原始 PDO 引用，用于克隆后的对象访问

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
                $installHint = ' Windows系统：请确保 php_pdo_pgsql.dll 和 php_pgsql.dll 已在 php.ini 中启用。';
            } elseif (PHP_OS_FAMILY === 'Linux') {
                $installHint = ' Linux系统：请运行 "apt-get install php-pgsql" 或 "yum install php-pgsql" 安装扩展，然后重启 PHP-FPM/Apache。';
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
            (new PgsqlDialect())->validateVersion((string)$this->link->getAttribute(PDO::ATTR_SERVER_VERSION));
        } catch (\Throwable $e) {
            \Weline\Framework\App\Env::log_warning('database_version.log', __('PostgreSQL 版本校验未通过（连接已建立，升级可继续）：%{1}', [$e->getMessage()]));
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
        
        $sql = "SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_schema = '{$schema}' AND table_name = '{$table}' AND column_name = '{$field}')";
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
}

