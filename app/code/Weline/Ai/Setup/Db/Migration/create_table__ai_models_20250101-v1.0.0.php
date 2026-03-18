<?php

declare(strict_types=1);

/**
 * 历史迁移：曾创建 ai_models 表。当前模块使用 ai_model（Install / AiModel），若已存在则跳过。
 */

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Ai\Model\AiModel;
use Weline\Database\AbstractMigration;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Database\Connection\Api\ConnectorInterface;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Manager\ObjectManager;

class CreateTableAiModels20250101V100 extends AbstractMigration
{
    private function connector(): ConnectorInterface
    {
        return ObjectManager::getInstance(ConnectionFactory::class)->getConnector();
    }

    public function install(): bool
    {
        $connection = $this->connector();

        $modernTable = ObjectManager::getInstance(AiModel::class)->getTable();
        if ($connection->tableExist($modernTable)) {
            return true;
        }

        foreach (['ai_models', 'm_ai_models'] as $legacy) {
            if ($connection->tableExist($legacy)) {
                return true;
            }
        }

        try {
            $table = $connection->newTable('ai_models')
                ->addColumn(
                    'entity_id',
                    TableInterface::column_type_INTEGER,
                    null,
                    ['identity' => true, 'nullable' => false, 'primary' => true],
                    'Entity ID'
                )
                ->addColumn(
                    'name',
                    TableInterface::column_type_TEXT,
                    255,
                    ['nullable' => false],
                    'Model Name'
                )
                ->addColumn(
                    'provider',
                    TableInterface::column_type_TEXT,
                    100,
                    ['nullable' => false],
                    'AI Provider'
                )
                ->addColumn(
                    'model_code',
                    TableInterface::column_type_TEXT,
                    100,
                    ['nullable' => false],
                    'Model Code'
                )
                ->addColumn(
                    'api_key',
                    TableInterface::column_type_TEXT,
                    500,
                    ['nullable' => true],
                    'API Key'
                )
                ->addColumn(
                    'base_url',
                    TableInterface::column_type_TEXT,
                    500,
                    ['nullable' => true],
                    'Base URL'
                )
                ->addColumn(
                    'status',
                    TableInterface::column_type_INTEGER,
                    null,
                    ['nullable' => false, 'default' => 1],
                    'Status'
                )
                ->addColumn(
                    'is_default',
                    TableInterface::column_type_INTEGER,
                    null,
                    ['nullable' => false, 'default' => 0],
                    'Is Default'
                )
                ->addColumn(
                    'created_at',
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => TableInterface::TIMESTAMP_INIT],
                    'Created At'
                )
                ->addColumn(
                    'updated_at',
                    TableInterface::column_type_TIMESTAMP,
                    null,
                    ['nullable' => false, 'default' => TableInterface::TIMESTAMP_INIT_UPDATE],
                    'Updated At'
                )
                ->setComment('AI Models Table');

            $connection->createTable($table);

            $connection->addIndex(
                'ai_models',
                'idx_ai_models_provider',
                ['provider']
            );

            $connection->addIndex(
                'ai_models',
                'idx_ai_models_status',
                ['status']
            );

            return true;
        } catch (\Throwable $e) {
            throw new \RuntimeException('创建AI模型表失败: ' . $e->getMessage(), 0, $e);
        }
    }

    public function uninstall(): bool
    {
        $connection = $this->connector();
        $connection->dropTableIfExists('ai_models');

        return true;
    }

    public function getInfo(): array
    {
        return [
            'name' => 'Create AI Models Table',
            'description' => '创建AI模型表，包含模型名称、提供商、API配置等信息',
            'version' => '1.0.0',
            'date' => '2025-01-01',
            'author' => 'WelineFramework',
        ];
    }

    public function validate(): bool
    {
        $this->connector();

        return true;
    }

    public function getDependencies(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return '创建AI模型表，包含模型名称、提供商、API配置等信息';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    public function getDate(): string
    {
        return '2025-01-01';
    }
}
