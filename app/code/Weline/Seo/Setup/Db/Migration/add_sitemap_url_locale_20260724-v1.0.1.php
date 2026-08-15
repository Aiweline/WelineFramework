<?php

declare(strict_types=1);

namespace Weline\Seo\Setup\Db\Migration;

use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SitemapUrl;

/**
 * Ensure weline_sitemap_url.locale exists and unique identity includes locale.
 * setup:upgrade schema-diff may be blocked by unrelated tables; keep this idempotent.
 */
class AddSitemapUrlLocale20260724V101 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '为 Sitemap URL 表增加 locale 列，并将唯一键升级为 website+scope+module+url_key+locale。';
    }

    public function getVersion(): string
    {
        return '1.0.1';
    }

    public function getDate(): string
    {
        return '2026-07-24';
    }

    /**
     * @return array<int, string>
     */
    public function getAffectedTables(): array
    {
        return [SitemapUrl::schema_table];
    }

    public function requiresBackup(): bool
    {
        return true;
    }

    public function getBackupStrategy(): array
    {
        return ['strategy' => 'table', 'tables' => [SitemapUrl::schema_table], 'columns' => []];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $model = ObjectManager::getInstance(SitemapUrl::class);
        $table = $model->getTable();

        if (!$connection->tableExist($table)) {
            return true;
        }

        if (!$this->columnExists($connection, $table, SitemapUrl::schema_fields_LOCALE)) {
            $alter = $connection->alterTable()->forTable($table, SitemapUrl::schema_fields_ID, '');
            $alter->addColumn(
                SitemapUrl::schema_fields_LOCALE,
                SitemapUrl::schema_fields_URL_KEY,
                TableInterface::column_type_VARCHAR,
                32,
                "NOT NULL DEFAULT ''",
                '语言代码，空值为 legacy/default 桶'
            );
            $alter->alter();
        }

        $this->ensureLocaleUniqueIndex($connection, $table);
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function ensureLocaleUniqueIndex(object $connection, string $table): void
    {
        $legacy = 'idx_unique_url_key';
        $target = 'idx_unique_url_key_locale';
        $columns = [
            SitemapUrl::schema_fields_WEBSITE_ID,
            SitemapUrl::schema_fields_SCOPE,
            SitemapUrl::schema_fields_MODULE,
            SitemapUrl::schema_fields_URL_KEY,
            SitemapUrl::schema_fields_LOCALE,
        ];

        if ($connection->hasIndex($table, $legacy)) {
            $connection->query($connection->buildDropIndexSql(
                $connection->formatTableName($table),
                $legacy,
            ))->fetch();
        }

        if (!$connection->hasIndex($table, $target)) {
            $connection->query($connection->buildAddIndexSql($connection->formatTableName($table), [
                'name' => $target,
                'type' => TableInterface::index_type_UNIQUE,
                'columns' => $columns,
            ]))->fetch();
        }
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (method_exists($connection, 'hasField')) {
            return (bool)$connection->hasField($table, $field);
        }

        foreach ($connection->getTableColumns($table) as $column) {
            $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? '';
            if (strcasecmp((string)$name, $field) === 0) {
                return true;
            }
        }

        return false;
    }
}
