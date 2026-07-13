<?php

declare(strict_types=1);

namespace Weline\Cms\Setup\Db\Migration;

use Weline\Cms\Model\Page;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class RemoveCmsPageContentHtml20260701V101 extends AbstractMigration
{
    private const FIELD_CONTENT_HTML = 'content_html';

    public function getDescription(): string
    {
        return 'Remove obsolete CMS body HTML column; visual page content belongs to Theme.';
    }

    public function getVersion(): string
    {
        return '1.0.1';
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
        return [Page::schema_table];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $page = ObjectManager::getInstance(Page::class);
        $table = $page->getTable();

        if (!$this->columnExists($connection, $table, self::FIELD_CONTENT_HTML)) {
            return true;
        }

        $alter = $connection->alterTable()->forTable($table, Page::schema_primary_key, '');
        $alter->deleteColumn(self::FIELD_CONTENT_HTML);
        $alter->alter();

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    private function columnExists(object $connection, string $table, string $field): bool
    {
        if (method_exists($connection, 'hasField')) {
            return $connection->hasField($table, $field);
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
