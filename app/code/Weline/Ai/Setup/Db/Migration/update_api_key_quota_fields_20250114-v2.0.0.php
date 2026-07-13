<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class UpdateApiKeyQuotaFields20250114V200 extends AbstractMigration
{
    private const TABLE_API_KEY = 'ai_api_key';

    public function getDescription(): string
    {
        return 'Update AI API key quota fields';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function getDate(): string
    {
        return '2025-01-14';
    }

    public function getAffectedTables(): array
    {
        return [self::TABLE_API_KEY];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        if (!$connection->tableExist(self::TABLE_API_KEY)) {
            return true;
        }

        $hasField = $this->columnExistsFn($connection);
        $alter = $connection->alterTable()->forTable(self::TABLE_API_KEY, 'id', '');
        $changed = false;

        if (!$hasField(self::TABLE_API_KEY, 'last_used_time')) {
            $alter->addColumn('last_used_time', '', TableInterface::column_type_DATETIME, 0, 'NULL', 'Last used time');
            $changed = true;
        }
        if (!$hasField(self::TABLE_API_KEY, 'call_count')) {
            $alter->addColumn('call_count', '', TableInterface::column_type_INTEGER, 11, 'NOT NULL DEFAULT 0', 'Call count');
            $changed = true;
        }

        if ($changed) {
            $alter->alter();
        }

        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    /** @return callable(string,string):bool */
    private function columnExistsFn(object $connection): callable
    {
        return function (string $table, string $field) use ($connection): bool {
            if (\method_exists($connection, 'hasField')) {
                return $connection->hasField($table, $field);
            }

            foreach ($connection->getTableColumns($table) as $column) {
                $name = $column['Field'] ?? $column['field'] ?? $column['column_name'] ?? '';
                if (\strcasecmp((string)$name, $field) === 0) {
                    return true;
                }
            }

            return false;
        };
    }
}
