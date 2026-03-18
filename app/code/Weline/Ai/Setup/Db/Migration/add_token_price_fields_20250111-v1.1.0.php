<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\AiModel;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 为 ai_model 表添加 token_price_input / token_price_output（与 AiModel、Install 一致）
 *
 * 类名须与 MigrationService::getMigrationClassName(文件名) 推导一致
 */
class AddTokenPriceFields20250111V110 extends AbstractMigration
{

    public function getDescription(): string
    {
        return '添加 token_price_input、token_price_output 字段';
    }

    public function getVersion(): string
    {
        return '1.1.0';
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(AiModel::class)->getTable();
        $alter = $connection->alterTable()->forTable($table, AiModel::schema_primary_key, '');
        $hasField = $this->columnExistsFn($connection);

        $changed = false;
        if (!$hasField($table, 'token_price_input')) {
            $alter->addColumn(
                'token_price_input',
                '',
                TableInterface::column_type_DECIMAL,
                '10,6',
                'NULL DEFAULT 0',
                '每1000个输入tokens的价格(美元)'
            );
            $changed = true;
        }
        if (!$hasField($table, 'token_price_output')) {
            $alter->addColumn(
                'token_price_output',
                '',
                TableInterface::column_type_DECIMAL,
                '10,6',
                'NULL DEFAULT 0',
                '每1000个输出tokens的价格(美元)'
            );
            $changed = true;
        }
        if ($changed) {
            $alter->alter();
        }

        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $table = ObjectManager::getInstance(AiModel::class)->getTable();
        $alter = $connection->alterTable()->forTable($table, AiModel::schema_primary_key, '');
        $hasField = $this->columnExistsFn($connection);

        $changed = false;
        if ($hasField($table, 'token_price_input')) {
            $alter->deleteColumn('token_price_input');
            $changed = true;
        }
        if ($hasField($table, 'token_price_output')) {
            $alter->deleteColumn('token_price_output');
            $changed = true;
        }
        if ($changed) {
            $alter->alter();
        }

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
}
