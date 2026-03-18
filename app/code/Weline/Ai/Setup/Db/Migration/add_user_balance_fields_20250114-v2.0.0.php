<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;
use Weline\Frontend\Model\FrontendUser;

/**
 * 为 frontend_user 表添加余额相关字段（与 MigrationService 文件名推导类名一致）
 */
class AddUserBalanceFields20250114V200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'frontend_user 余额、充值、消费、货币字段及 balance 索引';
    }

    public function getVersion(): string
    {
        return '2.0.0';
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(FrontendUser::class)->getTable();
        $alter = $connection->alterTable()->forTable($table, FrontendUser::schema_primary_key, '');

        $hasField = $this->columnExistsFn($connection);

        if (!$hasField($table, 'balance')) {
            $alter->addColumn(
                'balance',
                '',
                TableInterface::column_type_DECIMAL,
                '12,4',
                'NOT NULL DEFAULT 0.0000',
                '账户余额'
            );
        }
        if (!$hasField($table, 'total_recharge')) {
            $alter->addColumn(
                'total_recharge',
                '',
                TableInterface::column_type_DECIMAL,
                '12,4',
                'NOT NULL DEFAULT 0.0000',
                '累计充值金额'
            );
        }
        if (!$hasField($table, 'total_consumption')) {
            $alter->addColumn(
                'total_consumption',
                '',
                TableInterface::column_type_DECIMAL,
                '12,4',
                'NOT NULL DEFAULT 0.0000',
                '累计消费金额'
            );
        }
        if (!$hasField($table, 'currency')) {
            $alter->addColumn(
                'currency',
                '',
                TableInterface::column_type_VARCHAR,
                '10',
                "NOT NULL DEFAULT 'CNY'",
                '货币类型'
            );
        }
        $alter->alter();

        if ($hasField($table, 'balance') && !$this->indexExists($connection, $table, 'idx_balance')) {
            try {
                $connection->query("ALTER TABLE `{$table}` ADD INDEX `idx_balance` (`balance`)")->fetch();
            } catch (\Throwable) {
                // 索引可能已存在或引擎不支持
            }
        }

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(FrontendUser::class)->getTable();
        $hasField = $this->columnExistsFn($connection);

        try {
            $connection->query("ALTER TABLE `{$table}` DROP INDEX `idx_balance`")->fetch();
        } catch (\Throwable) {
        }

        $alter = $connection->alterTable()->forTable($table, FrontendUser::schema_primary_key, '');
        foreach (['currency', 'total_consumption', 'total_recharge', 'balance'] as $col) {
            if ($hasField($table, $col)) {
                $alter->deleteColumn($col);
            }
        }
        $alter->alter();

        return true;
    }

    /** @return callable(string,string):bool */
    private function columnExistsFn(object $connection): callable
    {
        return function (string $t, string $f) use ($connection): bool {
            if (method_exists($connection, 'hasField')) {
                return $connection->hasField($t, $f);
            }
            foreach ($connection->getTableColumns($t) as $col) {
                $name = $col['Field'] ?? $col['field'] ?? $col['column_name'] ?? '';
                if (strcasecmp((string) $name, $f) === 0) {
                    return true;
                }
            }

            return false;
        };
    }

    private function indexExists(object $connection, string $table, string $indexName): bool
    {
        try {
            $rows = $connection->query('SHOW INDEX FROM `' . str_replace('`', '``', $table) . '`')->fetch();
            if (!is_array($rows)) {
                return false;
            }
            foreach ($rows as $row) {
                $key = $row['Key_name'] ?? $row['key_name'] ?? '';
                if (strcasecmp((string) $key, $indexName) === 0) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }
}
