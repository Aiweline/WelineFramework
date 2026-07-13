<?php
/**
 * 创建AI模型表迁移
 * 
 * @author WelineFramework
 * @package Weline\Ai\Setup\Db\Migration
 */

namespace Weline\Ai\Setup\Db\Migration;

use Weline\Framework\Database\Migration\AbstractMigration;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;
use Weline\Framework\Manager\ObjectManager;

class CreateTableAiModels20250101V100 extends AbstractMigration
{
    private const CURRENT_TABLE = 'ai_model';

    /**
     * 执行迁移安装
     *
     * @return bool
     */
    public function install(): bool
    {
        try {
            $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
            
            if ($connection->tableExist(self::CURRENT_TABLE)) {
                return true;
            }

            // 创建AI模型表
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
            
            // 创建索引
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
            
        } catch (\Exception $e) {
            throw new \Exception("创建AI模型表失败: " . $e->getMessage());
        }
    }
    
    /**
     * 执行迁移卸载
     * 
     * @return bool
     */
    public function uninstall(): bool
    {
        try {
            $connection = ObjectManager::getInstance(ConnectionFactory::class)->getConnection();
            
            // 删除表
            $connection->dropTable('ai_models');
            
            return true;
            
        } catch (\Exception $e) {
            throw new \Exception("删除AI模型表失败: " . $e->getMessage());
        }
    }
    
    /**
     * 获取迁移信息
     * 
     * @return array
     */
    public function getInfo(): array
    {
        return [
            'name' => 'Create AI Models Table',
            'description' => '创建AI模型表，包含模型名称、提供商、API配置等信息',
            'version' => '1.0.0',
            'date' => '2025-01-01',
            'author' => 'WelineFramework'
        ];
    }
    
    /**
     * 验证迁移前置条件
     * 
     * @return bool
     */
    public function validate(): bool
    {
        // 检查表是否已存在
        return true;
    }
    
    /**
     * 获取迁移依赖
     * 
     * @return array
     */
    public function getDependencies(): array
    {
        return [];
    }
    
    /**
     * 获取迁移描述
     * 
     * @return string
     */
    public function getDescription(): string
    {
        return '创建AI模型表，包含模型名称、提供商、API配置等信息';
    }
    
    /**
     * 获取迁移版本
     * 
     * @return string
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    /**
     * 获取迁移日期
     * 
     * @return string
     */
    public function getDate(): string
    {
        return '2025-01-01';
    }
}
