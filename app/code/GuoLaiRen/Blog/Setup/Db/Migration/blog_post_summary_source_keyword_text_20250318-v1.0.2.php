<?php

declare(strict_types=1);

namespace GuoLaiRen\Blog\Setup\Db\Migration;

use GuoLaiRen\Blog\Model\Post;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

/**
 * 将 summary、source_keyword 从 varchar(255) 扩为 TEXT，避免 AI 长摘要/多行关键词插入失败。
 */
class BlogPostSummarySourceKeywordText20250318V102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'guolairen_blog_post：summary、source_keyword 改为 TEXT';
    }

    public function getVersion(): string
    {
        return '1.0.2';
    }

    public function install(): bool
    {
        $c = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $table = ObjectManager::getInstance(Post::class)->getTable();
        $tableSql = $this->tableSqlIdent($table);
        $driver = strtolower((string) $c->getDriverType());

        if (str_contains($driver, 'pgsql')) {
            $c->query(
                "ALTER TABLE {$tableSql} ALTER COLUMN \"summary\" TYPE TEXT USING \"summary\"::text"
            )->fetch();
            $c->query(
                "ALTER TABLE {$tableSql} ALTER COLUMN \"source_keyword\" TYPE TEXT USING \"source_keyword\"::text"
            )->fetch();
        } elseif (str_contains($driver, 'mysql') || str_contains($driver, 'mariadb')) {
            $c->query(
                "ALTER TABLE {$tableSql} MODIFY COLUMN `summary` TEXT NULL, MODIFY COLUMN `source_keyword` TEXT NULL"
            )->fetch();
        } else {
            // SQLite 等：TEXT 已兼容，跳过
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /** 供 ALTER TABLE 使用的表标识（保留 schema.database 前缀若存在） */
    private function tableSqlIdent(string $formatted): string
    {
        $s = trim($formatted);
        if ($s === '') {
            return '"' . Post::schema_table . '"';
        }
        if (str_contains($s, '"') || str_contains($s, '`')) {
            return $s;
        }

        return '"' . str_replace('"', '', $s) . '"';
    }
}
