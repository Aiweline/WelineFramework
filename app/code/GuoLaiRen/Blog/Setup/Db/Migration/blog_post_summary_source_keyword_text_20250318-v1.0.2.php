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
        $connector = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $table = ObjectManager::getInstance(Post::class)->getTable();
        $tableSql = $this->tableSqlIdent($table);
        $driver = strtolower((string)$connector->getConfigProvider()->getDbType());
        $hasSummary = $connector->hasField($table, Post::schema_fields_SUMMARY);
        $hasSourceKeyword = $connector->hasField($table, Post::schema_fields_SOURCE_KEYWORD);

        if ($driver === 'pgsql') {
            if ($hasSummary) {
                $connector->query(
                    "ALTER TABLE {$tableSql} ALTER COLUMN \"summary\" TYPE TEXT USING \"summary\"::text"
                )->fetch();
            }
            if ($hasSourceKeyword) {
                $connector->query(
                    "ALTER TABLE {$tableSql} ALTER COLUMN \"source_keyword\" TYPE TEXT USING \"source_keyword\"::text"
                )->fetch();
            }
        } elseif (str_contains($driver, 'mysql') || $driver === 'mariadb') {
            $alterClauses = [];
            if ($hasSummary) {
                $alterClauses[] = "MODIFY COLUMN `summary` TEXT NULL";
            }
            if ($hasSourceKeyword) {
                $alterClauses[] = "MODIFY COLUMN `source_keyword` TEXT NULL";
            }

            if ($alterClauses !== []) {
                $connector->query(
                    "ALTER TABLE {$tableSql} " . implode(', ', $alterClauses)
                )->fetch();
            }
        } else {
            // SQLite 等场景不需要额外处理。
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
        $table = trim($formatted);
        if ($table === '') {
            return '"' . Post::schema_table . '"';
        }
        if (str_contains($table, '"') || str_contains($table, '`')) {
            return $table;
        }

        return '"' . str_replace('"', '', $table) . '"';
    }
}
