<?php

declare(strict_types=1);

namespace Weline\Seo\Setup\Db\Migration;

use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Seo\Model\SitemapUrl;

class RebuildSitemapUrlKeyUnique20260703V100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy sitemap entity unique constraint and keep website+scope+module+url_key identity.';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDate(): string
    {
        return '2026-07-03';
    }

    /**
     * @return array<int,string>
     */
    public function getAffectedTables(): array
    {
        return [SitemapUrl::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $table = $this->rawTableName(ObjectManager::getInstance(SitemapUrl::class)->getTable());

        if (!$connection->tableExist($table)) {
            return true;
        }

        if ($this->isSqlite($connection) && $this->hasSqliteUniqueAutoIndex($connection, $table, [
            SitemapUrl::schema_fields_WEBSITE_ID,
            SitemapUrl::schema_fields_MODULE,
            SitemapUrl::schema_fields_ENTITY_TYPE,
            SitemapUrl::schema_fields_ENTITY_ID,
        ])) {
            $this->rebuildSqliteTable($connection, $table);
            return true;
        }

        foreach (['idx_unique_url', 'uk_sitemap_url_entity', 'uk_sitemap_url_entity_scope'] as $legacyIndex) {
            if ($connection->hasIndex($table, $legacyIndex)) {
                $connection->query($connection->buildDropIndexSql($table, $legacyIndex))->fetch();
            }
        }

        $this->ensureIndexes($connection, $table);
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function rebuildSqliteTable(object $connection, string $table): void
    {
        $backup = $table . '_legacy_' . date('YmdHis');
        $columns = [
            SitemapUrl::schema_fields_ID,
            SitemapUrl::schema_fields_WEBSITE_ID,
            SitemapUrl::schema_fields_MODULE,
            SitemapUrl::schema_fields_SCOPE,
            SitemapUrl::schema_fields_ENTITY_TYPE,
            SitemapUrl::schema_fields_ENTITY_ID,
            SitemapUrl::schema_fields_URL,
            SitemapUrl::schema_fields_CHANGEFREQ,
            SitemapUrl::schema_fields_PRIORITY,
            SitemapUrl::schema_fields_LASTMOD,
            SitemapUrl::schema_fields_STATUS,
            SitemapUrl::schema_fields_CREATED_AT,
            SitemapUrl::schema_fields_UPDATED_AT,
            'create_time',
            'update_time',
            SitemapUrl::schema_fields_METADATA,
            SitemapUrl::schema_fields_URL_KEY,
        ];
        $existingColumns = $this->tableColumns($connection, $table);
        $copyColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => in_array($column, $existingColumns, true)
        ));
        if ($copyColumns === []) {
            throw new \RuntimeException('Cannot rebuild sitemap URL table without readable columns.');
        }

        $connection->query('PRAGMA foreign_keys=OFF')->fetch();
        $connection->query('ALTER TABLE ' . $this->q($table) . ' RENAME TO ' . $this->q($backup))->fetch();
        $connection->query(
            'CREATE TABLE ' . $this->q($table) . ' (
                "url_id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "website_id" INT NOT NULL DEFAULT 0,
                "module" varchar(100) NOT NULL,
                "scope" varchar(50) NOT NULL DEFAULT \'\',
                "entity_type" varchar(50) NOT NULL DEFAULT \'\',
                "entity_id" INT NOT NULL DEFAULT 0,
                "url" TEXT NOT NULL,
                "changefreq" varchar(20) NOT NULL DEFAULT \'weekly\',
                "priority" varchar(10) NOT NULL DEFAULT \'0.5\',
                "lastmod" datetime DEFAULT NULL,
                "status" smallint(1) NOT NULL DEFAULT 1,
                "created_at" datetime DEFAULT NULL,
                "updated_at" datetime DEFAULT NULL,
                "create_time" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                "update_time" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                "metadata" TEXT DEFAULT NULL,
                "url_key" VARCHAR(191) DEFAULT NULL
            )'
        )->fetch();

        $copyList = implode(',', array_map([$this, 'q'], $copyColumns));
        $connection->query(
            'INSERT INTO ' . $this->q($table) . ' (' . $copyList . ') SELECT ' . $copyList . ' FROM ' . $this->q($backup)
        )->fetch();
        $connection->query('DROP TABLE ' . $this->q($backup))->fetch();
        $connection->query('PRAGMA foreign_keys=ON')->fetch();

        $this->ensureIndexes($connection, $table);
    }

    private function ensureIndexes(object $connection, string $table): void
    {
        $indexes = [
            'idx_website_id' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [SitemapUrl::schema_fields_WEBSITE_ID],
            ],
            'idx_module' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [SitemapUrl::schema_fields_MODULE],
            ],
            'idx_module_scope' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [SitemapUrl::schema_fields_MODULE, SitemapUrl::schema_fields_SCOPE],
            ],
            'idx_status' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [SitemapUrl::schema_fields_STATUS],
            ],
            'idx_unique_url_key' => [
                'type' => TableInterface::index_type_UNIQUE,
                'columns' => [
                    SitemapUrl::schema_fields_WEBSITE_ID,
                    SitemapUrl::schema_fields_SCOPE,
                    SitemapUrl::schema_fields_MODULE,
                    SitemapUrl::schema_fields_URL_KEY,
                ],
            ],
        ];

        foreach ($indexes as $name => $index) {
            if (!$connection->hasIndex($table, $name)) {
                $connection->query($connection->buildAddIndexSql($table, [
                    'name' => $name,
                    'type' => $index['type'],
                    'columns' => $index['columns'],
                ]))->fetch();
            }
        }
    }

    /**
     * @return list<string>
     */
    private function tableColumns(object $connection, string $table): array
    {
        $rows = $connection->query('PRAGMA table_info(' . $connection->getLink()->quote($table) . ')')->fetch();
        $columns = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['name'])) {
                $columns[] = (string)$row['name'];
            }
        }
        return $columns;
    }

    /**
     * @param list<string> $columns
     */
    private function hasSqliteUniqueAutoIndex(object $connection, string $table, array $columns): bool
    {
        $rows = $connection->query('PRAGMA index_list(' . $connection->getLink()->quote($table) . ')')->fetch();
        foreach (is_array($rows) ? $rows : [] as $row) {
            $name = (string)($row['name'] ?? '');
            if ($name === '' || (int)($row['unique'] ?? 0) !== 1 || !str_starts_with($name, 'sqlite_autoindex_')) {
                continue;
            }
            $info = $connection->query('PRAGMA index_info(' . $connection->getLink()->quote($name) . ')')->fetch();
            $actual = [];
            foreach (is_array($info) ? $info : [] as $indexColumn) {
                if (is_array($indexColumn) && isset($indexColumn['name'])) {
                    $actual[] = (string)$indexColumn['name'];
                }
            }
            if ($actual === $columns) {
                return true;
            }
        }
        return false;
    }

    private function isSqlite(object $connection): bool
    {
        return str_contains(strtolower($connection::class), 'sqlite');
    }

    private function q(string $identifier): string
    {
        $identifier = $this->rawTableName($identifier);
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function rawTableName(string $table): string
    {
        return trim($table, "\"` \t\n\r\0\x0B");
    }
}
