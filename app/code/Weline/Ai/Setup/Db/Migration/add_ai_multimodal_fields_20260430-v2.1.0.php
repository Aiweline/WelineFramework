<?php

declare(strict_types=1);

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\AiModel;
use Weline\Ai\Model\AiScenarioAdapter;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Connection\Api\Sql\TableInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class AddAiMultimodalFields20260430V210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '添加 AI 模型主模态与场景适配器模型绑定字段';
    }

    public function getVersion(): string
    {
        return '2.1.0';
    }

    public function install(): bool
    {
        $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
        $hasField = $this->columnExistsFn($connection);

        $modelTable = ObjectManager::getInstance(AiModel::class)->getTable();
        if (!$hasField($modelTable, AiModel::schema_fields_PRIMARY_MODALITY)) {
            $connection->alterTable()
                ->forTable($modelTable, AiModel::schema_primary_key, '')
                ->addColumn(
                    AiModel::schema_fields_PRIMARY_MODALITY,
                    '',
                    TableInterface::column_type_VARCHAR,
                    '32',
                    "NOT NULL DEFAULT 'text2text'",
                    '主要模态'
                )
                ->alter();
        }

        $adapterTable = ObjectManager::getInstance(AiScenarioAdapter::class)->getTable();
        if (!$hasField($adapterTable, AiScenarioAdapter::schema_fields_MODEL_BINDINGS)) {
            $connection->alterTable()
                ->forTable($adapterTable, AiScenarioAdapter::schema_primary_key, '')
                ->addColumn(
                    AiScenarioAdapter::schema_fields_MODEL_BINDINGS,
                    '',
                    TableInterface::column_type_TEXT,
                    '',
                    'NULL DEFAULT NULL',
                    '按模态绑定模型JSON'
                )
                ->alter();
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
        return function (string $t, string $f) use ($connection): bool {
            if (method_exists($connection, 'hasField')) {
                return $connection->hasField($t, $f);
            }
            foreach ($connection->getTableColumns($t) as $col) {
                $name = $col['Field'] ?? $col['field'] ?? $col['column_name'] ?? '';
                if (strcasecmp((string)$name, $f) === 0) {
                    return true;
                }
            }

            return false;
        };
    }
}
