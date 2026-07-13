<?php

declare(strict_types=1);

namespace Weline\Frontend\Setup\Db\Migration;

use Weline\Frontend\Model\FrontendUser;
use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class AddUserBalanceFields20250114V200 extends AbstractMigration
{
    private const TABLE_FRONTEND_USER = 'frontend_user';

    public function getDescription(): string
    {
        return 'Add billing balance fields to frontend_user';
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
        return [self::TABLE_FRONTEND_USER];
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
        if (!$connection->tableExist(self::TABLE_FRONTEND_USER)) {
            return true;
        }

        $hasField = $this->columnExistsFn($connection);
        $alter = $connection->alterTable()->forTable(
            self::TABLE_FRONTEND_USER,
            FrontendUser::schema_fields_ID,
            '',
        );
        $changed = false;

        if (!$hasField(self::TABLE_FRONTEND_USER, 'balance')) {
            $alter->addColumn('balance', '', TableInterface::column_type_DECIMAL, '12,4', 'NULL DEFAULT 0', 'Account balance');
            $changed = true;
        }
        if (!$hasField(self::TABLE_FRONTEND_USER, 'total_recharge')) {
            $alter->addColumn('total_recharge', '', TableInterface::column_type_DECIMAL, '12,4', 'NULL DEFAULT 0', 'Total recharge');
            $changed = true;
        }
        if (!$hasField(self::TABLE_FRONTEND_USER, 'total_consumption')) {
            $alter->addColumn('total_consumption', '', TableInterface::column_type_DECIMAL, '12,4', 'NULL DEFAULT 0', 'Total consumption');
            $changed = true;
        }
        if (!$hasField(self::TABLE_FRONTEND_USER, 'currency')) {
            $alter->addColumn('currency', '', TableInterface::column_type_VARCHAR, 10, "NOT NULL DEFAULT 'CNY'", 'Currency');
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
