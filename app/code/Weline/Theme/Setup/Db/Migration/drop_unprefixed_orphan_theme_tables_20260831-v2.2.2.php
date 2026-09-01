<?php

declare(strict_types=1);

namespace Weline\Theme\Setup\Db\Migration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

/**
 * Drop unprefixed orphan tables left from pre-prefix Theme installs.
 * Prefixed w_theme_layout* were already dropped in 2.2.0; Models no longer recreate them.
 */
final class DropUnprefixedOrphanThemeTables20260831V222 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Theme 2.2.2：删除无前缀孤儿表 theme_layout / theme_layout_version / theme_widget_default_injection。';
    }

    public function getVersion(): string
    {
        return '2.2.2';
    }

    public function getDate(): string
    {
        return '2026-08-31';
    }

    /** @return list<string> */
    public function getAffectedTables(): array
    {
        return [
            'theme_layout',
            'theme_layout_version',
            'theme_widget_default_injection',
        ];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        foreach ($this->getAffectedTables() as $table) {
            if ($connection->tableExist($table)) {
                $connection->dropTableIfExists($table);
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
