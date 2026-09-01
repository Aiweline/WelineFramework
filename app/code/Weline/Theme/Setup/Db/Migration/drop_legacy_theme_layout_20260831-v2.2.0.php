<?php

declare(strict_types=1);

namespace Weline\Theme\Setup\Db\Migration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\ThemeVirtualLayout;
use Weline\Theme\Model\ThemeVirtualLayoutVersion;

final class DropLegacyThemeLayout20260831V220 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Theme 2.2.0：删除 legacy theme_layout / virtual layout 表（scoped workspace 为唯一权威）。';
    }

    public function getVersion(): string
    {
        return '2.2.0';
    }

    public function getDate(): string
    {
        return '2026-08-31';
    }

    /** @return list<string> */
    public function getAffectedTables(): array
    {
        return [
            ThemeLayout::schema_table,
            ThemeVirtualLayout::schema_table,
            ThemeVirtualLayoutVersion::schema_table,
        ];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        $models = [
            ThemeLayout::class,
            ThemeVirtualLayout::class,
            ThemeVirtualLayoutVersion::class,
        ];
        foreach ($models as $modelClass) {
            $table = ObjectManager::getInstance($modelClass)->getTable();
            if ($connection->tableExist($table)) {
                $connection->dropTableIfExists($table);
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        // Destructive drop migration: legacy tables are not recreated on rollback.
        return true;
    }
}
