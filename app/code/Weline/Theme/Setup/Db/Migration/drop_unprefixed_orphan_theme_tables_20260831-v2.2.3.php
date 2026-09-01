<?php

declare(strict_types=1);

namespace Weline\Theme\Setup\Db\Migration;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Manager\ObjectManager;

/**
 * Drop unprefixed orphan tables left from pre-prefix Theme installs.
 *
 * IMPORTANT: Connector::tableExist / dropTableIfExists auto-prefix with w_,
 * so orphan names must be dropped via raw SQL against the literal table name.
 * 2.2.2 migration was recorded as installed but skipped orphans for this reason;
 * 2.2.3 corrects the drop path.
 */
final class DropUnprefixedOrphanThemeTables20260831V223 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Theme 2.2.3：用裸表名 DROP 无前缀孤儿 theme_layout / theme_layout_version / theme_widget_default_injection（绕过 w_ 前缀）。';
    }

    public function getVersion(): string
    {
        return '2.2.3';
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
        $pdo = $connection->getLink();
        foreach ($this->getAffectedTables() as $table) {
            if (!\preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
                throw new \InvalidArgumentException('invalid_orphan_table:' . $table);
            }
            $pdo->exec('DROP TABLE IF EXISTS ' . $table . ' CASCADE');
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }
}
