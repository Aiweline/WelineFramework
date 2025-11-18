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
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Alter;
use Weline\Framework\Database\Connection\Adapter\Pgsql\Table\Create;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Database\Helper\Standar;
use Weline\Framework\Manager\ObjectManager;

final class Connector extends Query implements ConnectorInterface
{
    public function __construct(
        private readonly ?ConfigProviderInterface $configProvider
    )
    {
        $this->db_name = $this->configProvider->getDatabase();
    }

    protected ?PDO $link = null;
    protected ?Query $query = null;

    public function create(): static
    {
        $db_type = $this->configProvider->getDbType();
        if (!in_array($db_type, PDO::getAvailableDrivers())) {
            throw new LinkException(__('驱动不存在：%{1},可用驱动列表：%{2}，更多驱动配置请转到php.ini中开启。', [$db_type, implode(',', PDO::getAvailableDrivers())]));
        }
        
        // PostgreSQL DSN 格式: pgsql:host=hostname;port=5432;dbname=database;user=username;password=password
        $dsn = "pgsql:host={$this->configProvider->getHostName()};port={$this->configProvider->getHostPort()};dbname={$this->configProvider->getDatabase()}";
        if ($this->configProvider->getCharset()) {
            $dsn .= ";options='--client_encoding={$this->configProvider->getCharset()}'";
        }
        
        try {
            //初始化一个Connection对象
            $this->link = new PDO($dsn, $this->configProvider->getUsername(), $this->configProvider->getPassword(), $this->configProvider->getOptions());
            if ($this->configProvider->getPreSql()) {
                $this->link->exec($this->configProvider->getPreSql());
            }
            // 设置字符集
            if ($this->configProvider->getCharset()) {
                $this->link->exec("SET NAMES '{$this->configProvider->getCharset()}'");
            }
        } catch (PDOException $e) {
            throw new LinkException($e->getMessage());
        }
        return $this;
    }

    public function close(): void
    {
        $this->link = null;
    }

    public function getLink(): PDO
    {
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
            $table_name = str_replace(['`', '"'], '', $table_name);
            $schema = 'public';
            if (str_contains($table_name, '.')) {
                list($schema, $table_name) = explode('.', $table_name);
            }
            
            $sql = "SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = '{$schema}' AND table_name = '{$table_name}')";
            $result = $this->query($sql)->fetch();
            return (bool)($result[0]['exists'] ?? false);
        } catch (\Exception $exception) {
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
        $schema = 'public';
        if (str_contains($table, '.')) {
            list($schema, $table) = explode('.', $table);
        }
        
        $sql = "SELECT EXISTS (SELECT FROM information_schema.columns WHERE table_schema = '{$schema}' AND table_name = '{$table}' AND column_name = '{$field}')";
        $result = $this->query($sql)->fetch();
        return (bool)($result[0]['exists'] ?? false);
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

