<?php

declare(strict_types=1);

namespace Weline\Seo\Setup\Db\Migration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SeoAccount;
use Weline\Seo\Model\SeoWebsiteAccount;

/**
 * Sitemap/URL 自动选项默认开启：列默认值 + 历史账户对齐为 1（用户曾显式关闭的可再改回）。
 */
class EnableCronSitemapDefaultOn20260922V12101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '将 SEO 账户 enable_cron_sitemap 默认改为开启，并补齐历史账户与绑定自动开关。';
    }

    public function getVersion(): string
    {
        return '1.2.101';
    }

    public function getDate(): string
    {
        return '2026-09-22';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return [SeoAccount::schema_table, SeoWebsiteAccount::schema_table];
    }

    public function requiresBackup(): bool
    {
        return true;
    }

    public function getBackupStrategy(): array
    {
        return [
            'strategy' => 'table',
            'tables' => [SeoAccount::schema_table, SeoWebsiteAccount::schema_table],
            'columns' => [],
        ];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $accountTable = ObjectManager::getInstance(SeoAccount::class)->getTable();
        $bindingTable = ObjectManager::getInstance(SeoWebsiteAccount::class)->getTable();

        if ($connection->tableExist($accountTable)) {
            try {
                $connection->query(
                    sprintf(
                        'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT 1',
                        $accountTable,
                        SeoAccount::schema_fields_ENABLE_CRON_SITEMAP
                    )
                );
            } catch (\Throwable) {
                // MySQL 等方言可能用不同语法；模型 Col default 仍由 schema 同步兜底。
            }
            try {
                $connection->query(
                    sprintf(
                        'UPDATE %s SET %s = 1 WHERE %s = 0 OR %s IS NULL',
                        $accountTable,
                        SeoAccount::schema_fields_ENABLE_CRON_SITEMAP,
                        SeoAccount::schema_fields_ENABLE_CRON_SITEMAP,
                        SeoAccount::schema_fields_ENABLE_CRON_SITEMAP
                    )
                );
            } catch (\Throwable) {
                // ignore if column missing in odd envs
            }
            try {
                $connection->query(
                    sprintf(
                        'UPDATE %s SET %s = 1 WHERE %s = 0 OR %s IS NULL',
                        $accountTable,
                        SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS,
                        SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS,
                        SeoAccount::schema_fields_ENABLE_CRON_PUSH_URLS
                    )
                );
            } catch (\Throwable) {
            }
        }

        if ($connection->tableExist($bindingTable)) {
            try {
                $connection->query(
                    sprintf(
                        'UPDATE %s SET %s = 1 WHERE %s = 0 OR %s IS NULL',
                        $bindingTable,
                        SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT,
                        SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT,
                        SeoWebsiteAccount::schema_fields_IS_AUTO_SUBMIT
                    )
                );
            } catch (\Throwable) {
            }
            try {
                $connection->query(
                    sprintf(
                        'UPDATE %s SET %s = 1 WHERE %s = 0 OR %s IS NULL',
                        $bindingTable,
                        SeoWebsiteAccount::schema_fields_ENABLE_URL_PUSH,
                        SeoWebsiteAccount::schema_fields_ENABLE_URL_PUSH,
                        SeoWebsiteAccount::schema_fields_ENABLE_URL_PUSH
                    )
                );
            } catch (\Throwable) {
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
