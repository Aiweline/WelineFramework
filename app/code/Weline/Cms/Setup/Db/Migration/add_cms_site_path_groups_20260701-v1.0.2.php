<?php

declare(strict_types=1);

namespace Weline\Cms\Setup\Db\Migration;

use Weline\Cms\Model\Page;
use Weline\Cms\Model\PathGroup;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class AddCmsSitePathGroups20260701V102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add website-aware CMS path groups and split page identifiers into group plus slug.';
    }

    public function getVersion(): string
    {
        return '1.0.2';
    }

    public function getDate(): string
    {
        return '2026-07-01';
    }

    /**
     * @return array<int,string>
     */
    public function getAffectedTables(): array
    {
        return [Page::schema_table, PathGroup::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $pageModel = ObjectManager::getInstance(Page::class);
        $pathGroupModel = ObjectManager::getInstance(PathGroup::class);
        $pageTable = $pageModel->getTable();
        $pathGroupTable = $pathGroupModel->getTable();

        foreach ($this->pageColumns() as $column) {
            if (!$this->columnExists($connection, $pageTable, (string)$column['name'])) {
                $connection->query($connection->buildAlterAddColumnSql($pageTable, $column))->fetch();
            }
        }

        if ($connection->hasIndex($pageTable, 'uk_cms_page_identifier_scope')) {
            $connection->query($connection->buildDropIndexSql($pageTable, 'uk_cms_page_identifier_scope'))->fetch();
        }

        $pageIndexes = [
            'uk_cms_page_website_identifier' => [
                'type' => TableInterface::index_type_UNIQUE,
                'columns' => [Page::schema_fields_WEBSITE_ID, Page::schema_fields_IDENTIFIER],
            ],
            'idx_cms_page_site_group' => [
                'type' => TableInterface::index_type_KEY,
                'columns' => [Page::schema_fields_WEBSITE_ID, Page::schema_fields_PATH_GROUP, Page::schema_fields_STATUS],
            ],
        ];
        foreach ($pageIndexes as $name => $index) {
            if (!$connection->hasIndex($pageTable, $name)) {
                $connection->query($connection->buildAddIndexSql($pageTable, [
                    'name' => $name,
                    'type' => $index['type'],
                    'columns' => $index['columns'],
                ]))->fetch();
            }
        }

        $this->backfillPages($connection->tableExist($pathGroupTable));

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function pageColumns(): array
    {
        return [
            [
                'name' => Page::schema_fields_WEBSITE_ID,
                'type' => TableInterface::column_type_INTEGER,
                'length' => 11,
                'nullable' => false,
                'default' => 0,
                'comment' => 'Website ID',
            ],
            [
                'name' => Page::schema_fields_WEBSITE_CODE,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 128,
                'nullable' => false,
                'default' => 'default',
                'comment' => 'Website code',
            ],
            [
                'name' => Page::schema_fields_PATH_GROUP,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 100,
                'nullable' => false,
                'default' => '',
                'comment' => 'First-level path group',
            ],
            [
                'name' => Page::schema_fields_PATH_GROUP_ALIAS,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 255,
                'nullable' => false,
                'default' => '',
                'comment' => 'Path group display alias',
            ],
            [
                'name' => Page::schema_fields_SLUG,
                'type' => TableInterface::column_type_VARCHAR,
                'length' => 190,
                'nullable' => false,
                'default' => '',
                'comment' => 'Slug inside path group',
            ],
        ];
    }

    private function backfillPages(bool $canCreatePathGroups): void
    {
        $pageModel = ObjectManager::getInstance(Page::class);
        $rows = (clone $pageModel)->clearData()->reset()->select()->fetchArray();
        if (!is_array($rows)) {
            return;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $identifier = trim((string)($row[Page::schema_fields_IDENTIFIER] ?? ''), '/');
            if ($identifier === '') {
                continue;
            }
            $parts = explode('/', $identifier);
            $pathGroup = (string)array_shift($parts);
            $slug = implode('/', $parts);
            $alias = trim((string)($row[Page::schema_fields_PATH_GROUP_ALIAS] ?? ''));
            if ($alias === '') {
                $alias = $pathGroup;
            }
            $websiteId = (int)($row[Page::schema_fields_WEBSITE_ID] ?? 0);
            $websiteCode = trim((string)($row[Page::schema_fields_WEBSITE_CODE] ?? ''));
            if ($websiteCode === '') {
                $websiteCode = 'default';
            }

            $page = clone $pageModel;
            $page->clearData()->setData($row);
            $page->setData(Page::schema_fields_WEBSITE_ID, $websiteId);
            $page->setData(Page::schema_fields_WEBSITE_CODE, $websiteCode);
            $page->setData(Page::schema_fields_PATH_GROUP, $pathGroup);
            $page->setData(Page::schema_fields_PATH_GROUP_ALIAS, $alias);
            $page->setData(Page::schema_fields_SLUG, $slug);
            $page->save();

            if ($canCreatePathGroups) {
                $this->upsertPathGroup($websiteId, $websiteCode, $pathGroup, $alias);
            }
        }
    }

    private function upsertPathGroup(int $websiteId, string $websiteCode, string $pathGroup, string $alias): void
    {
        $pathGroupModel = ObjectManager::getInstance(PathGroup::class);
        $group = clone $pathGroupModel;
        $group->clearData()->reset()
            ->where(PathGroup::schema_fields_WEBSITE_ID, $websiteId)
            ->where(PathGroup::schema_fields_PATH_GROUP, $pathGroup)
            ->find()
            ->fetch();
        if ($group->getGroupId() <= 0) {
            $group->clearData();
        }

        $group->setData(PathGroup::schema_fields_WEBSITE_ID, $websiteId);
        $group->setData(PathGroup::schema_fields_WEBSITE_CODE, $websiteCode);
        $group->setData(PathGroup::schema_fields_PATH_GROUP, $pathGroup);
        $group->setData(PathGroup::schema_fields_ALIAS, $alias);
        $group->setData(PathGroup::schema_fields_DELETED_AT, null);
        $group->save();
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (method_exists($connection, 'hasField')) {
            return $connection->hasField($table, $field);
        }

        foreach ($connection->getTableColumns($table) as $column) {
            $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? $column['name'] ?? '';
            if (strcasecmp((string)$name, $field) === 0) {
                return true;
            }
        }

        return false;
    }
}
