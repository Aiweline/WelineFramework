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

namespace Weline\Framework\Database\Connection\Adapter\SqLite;

use PDO;
use PDOException;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\Connection\Api\Sql;
use Weline\Framework\Database\Connection\Api\Sql\QueryInterface;
use Weline\Framework\Database\DbManager\ConfigProviderInterface;
use Weline\Framework\Database\Exception\LinkException;
use Weline\Framework\Manager\ObjectManager;

final class Connector extends Query implements ConnectorInterface
{
    public function __construct(
        private readonly ConfigProviderInterface $configProvider
    ) {
        $this->db_name    = $this->configProvider->getDatabase();
    }
    protected ?PDO $link = null;
    protected ?Query $query = null;
    public function create(): static
    {
        $db_type = $this->configProvider->getDbType();
        $dsn     = "{$db_type}:{$this->configProvider->getData('path')}";
        try {
            //初始化一个Connection对象
            $this->link = new PDO($dsn);
            //            $this->link->exec("set names {$this->configProvider->getCharset()} COLLATE {$this->configProvider->getCollate()}");
            // 设置错误模式为异常
            $this->link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
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

    public function getIndexFields(string $table): QueryInterface
    {
        return $this->query('show index from ' . $table);
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

    public function createTable(): Sql\Table\CreateInterface
    {
        return ObjectManager::getInstance(Table\Create::class)->setConnection($this);
    }

    public function alterTable(): Sql\Table\AlterInterface
    {
        return ObjectManager::getInstance(Table\Alter::class)->setConnection($this);
    }
    public function tableExist(string $table_name): bool
    {
        $table_name = trim($table_name, '`');
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
    public function getVersion(): string
    {
        // 查询数据库版本号
        return $this->link->getAttribute(PDO::ATTR_CLIENT_VERSION);
    }
}
