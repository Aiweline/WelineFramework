<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\AiModel;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 添加 token_price_input 和 token_price_output 字段
 *
 * 用于记录模型的输入和输出价格
 * 类名须与 MigrationService::getMigrationClassName(文件名) 推导一致
 */
class AddTokenPriceFields20250111V110 extends AbstractMigration
{
    private const TABLE_AI_MODEL = AiModel::schema_table;

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
        $connection = ObjectManager::getInstance()->get(ConnectionFactory::class)->getConnection();
        $table = self::TABLE_AI_MODEL;
        $alter = $connection->alterTable()->forTable($table, AiModel::schema_primary_key, '');

        $hasField = method_exists($connection, 'hasField')
            ? fn(string $t, string $f) => $connection->hasField($t, $f)
            : function (string $t, string $f) use ($connection) {
                $cols = $connection->getTableColumns($t);
                foreach ($cols as $col) {
                    $name = $col['Field'] ?? $col['field'] ?? $col['column_name'] ?? '';
                    if (strcasecmp($name, $f) === 0) {
                        return true;
                    }
                }
                return false;
            };

        $changed = false;
        if (!$hasField($table, AiModel::schema_fields_TOKEN_PRICE_INPUT)) {
            $alter->addColumn(
                AiModel::schema_fields_TOKEN_PRICE_INPUT,
                '',
                TableInterface::column_type_DECIMAL,
                '10,6',
                'NULL DEFAULT 0',
                '每1000个输入tokens的价格(美元)'
            );
            $changed = true;
        }
        if (!$hasField($table, AiModel::schema_fields_TOKEN_PRICE_OUTPUT)) {
            $alter->addColumn(
                AiModel::schema_fields_TOKEN_PRICE_OUTPUT,
                '',
                TableInterface::column_type_DECIMAL,
                '10,6',
                'NULL DEFAULT 0',
                '每1000个输出tokens的价格(美元)'
            );
            $changed = true;
        }
        if (!$changed) {
            return true;
        }
        $alter->alter();
        return true;
    }

    public function uninstall(): bool
    {
        $connection = ObjectManager::getInstance()->get(ConnectionFactory::class)->getConnection();
        $table = self::TABLE_AI_MODEL;
        $alter = $connection->alterTable()->forTable($table, AiModel::schema_primary_key, '');

        $hasField = method_exists($connection, 'hasField')
            ? fn(string $t, string $f) => $connection->hasField($t, $f)
            : function (string $t, string $f) use ($connection) {
                $cols = $connection->getTableColumns($t);
                foreach ($cols as $col) {
                    $name = $col['Field'] ?? $col['field'] ?? $col['column_name'] ?? '';
                    if (strcasecmp($name, $f) === 0) {
                        return true;
                    }
                }
                return false;
            };

        $changed = false;
        if ($hasField($table, AiModel::schema_fields_TOKEN_PRICE_INPUT)) {
            $alter->deleteColumn(AiModel::schema_fields_TOKEN_PRICE_INPUT);
            $changed = true;
        }
        if ($hasField($table, AiModel::schema_fields_TOKEN_PRICE_OUTPUT)) {
            $alter->deleteColumn(AiModel::schema_fields_TOKEN_PRICE_OUTPUT);
            $changed = true;
        }
        if (!$changed) {
            return true;
        }
        $alter->alter();
        return true;
    }
}
