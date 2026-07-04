<?php

declare(strict_types=1);

namespace Weline\Cms\Setup\Db\Migration;

use Weline\Cms\Model\Page;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class RebuildCmsPageWebsiteUnique20260703V103 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy CMS page identifier+scope unique constraint and keep website-scoped page identity.';
    }

    public function getVersion(): string
    {
        return '1.0.3';
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
        return [Page::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $table = $this->rawTableName(ObjectManager::getInstance(Page::class)->getTable());

        if (!$connection->tableExist($table)) {
            return true;
        }

        if ($this->isSqlite($connection) && $this->hasSqliteUniqueAutoIndex($connection, $table, [
            Page::schema_fields_IDENTIFIER,
            Page::schema_fields_SCOPE,
        ])) {
            $this->rebuildSqliteTable($connection, $table);
            return true;
        }

        if ($connection->hasIndex($table, 'uk_cms_page_identifier_scope')) {
            $connection->query($connection->buildDropIndexSql($table, 'uk_cms_page_identifier_scope'))->fetch();
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
            Page::schema_fields_ID,
            Page::schema_fields_IDENTIFIER,
            Page::schema_fields_TITLE,
            Page::schema_fields_STATUS,
            Page::schema_fields_SCOPE,
            Page::schema_fields_CREATED_AT,
            Page::schema_fields_UPDATED_AT,
            Page::schema_fields_DELETED_AT,
            'create_time',
            'update_time',
            Page::schema_fields_WEBSITE_ID,
            Page::schema_fields_WEBSITE_CODE,
            Page::schema_fields_PATH_GROUP,
            Page::schema_fields_PATH_GROUP_ALIAS,
            Page::schema_fields_SLUG,
        ];
        $existingColumns = $this->tableColumns($connection, $table);
        $copyColumns = array_values(array_filter(
            $columns,
            static fn (string $column): bool => in_array($column, $existingColumns, true)
        ));
        if ($copyColumns === []) {
            throw new \RuntimeException('Cannot rebuild CMS page table without readable columns.');
        }

        $connection->query('PRAGMA foreign_keys=OFF')->fetch();
        $connection->query('ALTER TABLE ' . $this->q($table) . ' RENAME TO ' . $this->q($backup))->fetch();
        $connection->query(
            'CREATE TABLE ' . $this->q($table) . ' (
                "page_id" INTEGER PRIMARY KEY AUTOINCREMENT,
                "identifier" varchar(190) NOT NULL,
                "title" varchar(255) NOT NULL,
                "status" varchar(32) NOT NULL DEFAULT \'draft\',
                "scope" varchar(128) NOT NULL DEFAULT \'default\',
                "created_at" datetime DEFAULT NULL,
                "updated_at" datetime DEFAULT NULL,
                "deleted_at" datetime DEFAULT NULL,
                "create_time" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                "update_time" timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                "website_id" INTEGER(11) NOT NULL DEFAULT 0,
                "website_code" VARCHAR(128) NOT NULL DEFAULT \'default\',
                "path_group" VARCHAR(100) NOT NULL DEFAULT \'\',
                "path_group_alias" VARCHAR(255) NOT NULL DEFAULT \'\',
                "slug" VARCHAR(190) NOT NULL DEFAULT \'\'
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
            'uk_cms_page_website_identifier' => [
                'type' => TableInterface::index_type_UNIQUE,
                'columns' => [Page::schema_fields_WEBSITE_ID, Page::schema_fields_IDENTIFIER],
            ],
            'idx_cms_page_site_group' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [Page::schema_fields_WEBSITE_ID, Page::schema_fields_PATH_GROUP, Page::schema_fields_STATUS],
            ],
            'idx_cms_page_status_scope' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [Page::schema_fields_STATUS, Page::schema_fields_SCOPE],
            ],
            'idx_cms_page_deleted_at' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [Page::schema_fields_DELETED_AT],
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
