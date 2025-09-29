<?php
/**
 * Weline_Database模块安装脚本
 * 
 * @author WelineFramework
 * @package Weline\Database\Setup
 */

namespace Weline\Database\Setup;

use Weline\Framework\Setup\InstallInterface;
use Weline\Framework\Setup\Data\Context;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Database\Api\Db\Ddl\TableInterface;

class Install implements InstallInterface
{
    /**
     * 安装数据库表结构
     * 
     * @param ModelSetup $setup
     * @param Context $context
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $setup->startSetup();
        
        // 创建迁移记录表
        $this->createMigrationsTable($setup);
        
        // 创建备份记录表
        $this->createBackupsTable($setup);
        
        // 创建模块版本表
        $this->createModuleVersionsTable($setup);
        
        $setup->endSetup();
    }
    
    /**
     * 创建备份记录表
     * 
     * @param ModelSetup $setup
     */
    private function createBackupsTable(ModelSetup $setup): void
    {
        $table = $setup->getConnection()->newTable('weline_database_backups')
            ->addColumn(
                'backup_id',
                TableInterface::column_type_INTEGER,
                null,
                ['identity' => true, 'nullable' => false, 'primary' => true],
                'Backup ID'
            )
            ->addColumn(
                'migration_id',
                TableInterface::column_type_INTEGER,
                null,
                ['nullable' => false],
                'Migration ID'
            )
            ->addColumn(
                'table_name',
                TableInterface::column_type_TEXT,
                255,
                ['nullable' => false],
                'Table Name'
            )
            ->addColumn(
                'backup_data',
                TableInterface::column_type_TEXT,
                '2M',
                ['nullable' => false],
                'Backup Data'
            )
            ->addColumn(
                'backup_type',
                TableInterface::column_type_TEXT,
                50,
                ['nullable' => false],
                'Backup Type'
            )
            ->addColumn(
                'created_at',
                TableInterface::column_type_TIMESTAMP,
                null,
                ['nullable' => false, 'default' => 'CURRENT_TIMESTAMP'],
                'Created At'
            )
            ->addIndex(
                'idx_migration_id',
                ['migration_id']
            )
            ->addIndex(
                'idx_table_name',
                ['table_name']
            )
            ->setComment('Database Migration Backups');
        
        $setup->getConnection()->createTable($table);
    }
    
    /**
     * 创建模块版本表
     * 
     * @param ModelSetup $setup
     */
    private function createModuleVersionsTable(ModelSetup $setup): void
    {
        $table = $setup->getConnection()->newTable('weline_database_module_versions')
            ->addColumn(
                'id',
                TableInterface::column_type_INTEGER,
                null,
                ['identity' => true, 'nullable' => false, 'primary' => true],
                'ID'
            )
            ->addColumn(
                'module_name',
                TableInterface::column_type_TEXT,
                255,
                ['nullable' => false],
                'Module Name'
            )
            ->addColumn(
                'current_version',
                TableInterface::column_type_TEXT,
                50,
                ['nullable' => false],
                'Current Version'
            )
            ->addColumn(
                'last_migration',
                TableInterface::column_type_TEXT,
                255,
                ['nullable' => true],
                'Last Migration'
            )
            ->addColumn(
                'updated_at',
                TableInterface::column_type_TIMESTAMP,
                null,
                ['nullable' => false, 'default' => 'CURRENT_TIMESTAMP'],
                'Updated At'
            )
            ->addIndex(
                'idx_module_name',
                ['module_name'],
                'UNIQUE'
            )
            ->setComment('Module Versions');
        
        $setup->getConnection()->createTable($table);
    }
    
    /**
     * 创建迁移记录表
     * 
     * @param ModelSetup $setup
     */
    private function createMigrationsTable(ModelSetup $setup): void
    {
        $table = $setup->getConnection()->newTable($setup->getTable('weline_database_migrations'))
            ->addColumn(
                'migration_id',
                TableInterface::column_type_INTEGER,
                null,
                ['identity' => true, 'nullable' => false, 'primary' => true],
                'Migration ID'
            )
            ->addColumn(
                'module_name',
                TableInterface::column_type_TEXT,
                100,
                ['nullable' => false],
                'Module Name'
            )
            ->addColumn(
                'version',
                TableInterface::column_type_TEXT,
                20,
                ['nullable' => false],
                'Version'
            )
            ->addColumn(
                'migration_file',
                TableInterface::column_type_TEXT,
                255,
                ['nullable' => false],
                'Migration File'
            )
            ->addColumn(
                'description',
                TableInterface::column_type_TEXT,
                500,
                ['nullable' => true],
                'Description'
            )
            ->addColumn(
                'status',
                TableInterface::column_type_TEXT,
                20,
                ['nullable' => false, 'default' => 'pending'],
                'Status'
            )
            ->addColumn(
                'executed_at',
                TableInterface::column_type_TIMESTAMP,
                null,
                ['nullable' => true],
                'Executed At'
            )
            ->addColumn(
                'rollback_at',
                TableInterface::column_type_TIMESTAMP,
                null,
                ['nullable' => true],
                'Rollback At'
            )
            ->addColumn(
                'dependencies',
                TableInterface::column_type_TEXT,
                1000,
                ['nullable' => true],
                'Dependencies'
            )
            ->addColumn(
                'checksum',
                TableInterface::column_type_TEXT,
                32,
                ['nullable' => true],
                'Checksum'
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
            ->setComment('Database Migrations Table');
            
        $setup->getConnection()->createTable($table);
        
        // 添加索引
        $setup->getConnection()->addIndex(
            $setup->getTable('weline_database_migrations'),
            'idx_migrations_module',
            ['module_name']
        );
        
        $setup->getConnection()->addIndex(
            $setup->getTable('weline_database_migrations'),
            'idx_migrations_status',
            ['status']
        );
    }
    
    /**
     * 创建备份记录表
     * 
     * @param ModelSetup $setup
     */
    private function createBackupsTable(ModelSetup $setup): void
    {
        $table = $setup->getConnection()->newTable($setup->getTable('weline_database_backups'))
            ->addColumn(
                'backup_id',
                TableInterface::column_type_INTEGER,
                null,
                ['identity' => true, 'nullable' => false, 'primary' => true],
                'Backup ID'
            )
            ->addColumn(
                'migration_id',
                TableInterface::column_type_INTEGER,
                null,
                ['nullable' => false],
                'Migration ID'
            )
            ->addColumn(
                'table_name',
                TableInterface::column_type_TEXT,
                100,
                ['nullable' => false],
                'Table Name'
            )
            ->addColumn(
                'backup_data',
                TableInterface::column_type_TEXT,
                0,
                ['nullable' => true],
                'Backup Data'
            )
            ->addColumn(
                'backup_type',
                TableInterface::column_type_TEXT,
                20,
                ['nullable' => false],
                'Backup Type'
            )
            ->addColumn(
                'created_at',
                TableInterface::column_type_TIMESTAMP,
                null,
                ['nullable' => false, 'default' => TableInterface::TIMESTAMP_INIT],
                'Created At'
            )
            ->setComment('Database Backups Table');
            
        $setup->getConnection()->createTable($table);
        
        // 添加外键约束
        $setup->getConnection()->addForeignKey(
            $setup->getTable('weline_database_backups'),
            'fk_backups_migration',
            'migration_id',
            $setup->getTable('weline_database_migrations'),
            'migration_id',
            'CASCADE'
        );
    }
    
    /**
     * 创建模块版本表
     * 
     * @param ModelSetup $setup
     */
    private function createModuleVersionsTable(ModelSetup $setup): void
    {
        $table = $setup->getConnection()->newTable($setup->getTable('weline_database_module_versions'))
            ->addColumn(
                'version_id',
                TableInterface::column_type_INTEGER,
                null,
                ['identity' => true, 'nullable' => false, 'primary' => true],
                'Version ID'
            )
            ->addColumn(
                'module_name',
                TableInterface::column_type_TEXT,
                100,
                ['nullable' => false],
                'Module Name'
            )
            ->addColumn(
                'current_version',
                TableInterface::column_type_TEXT,
                20,
                ['nullable' => false],
                'Current Version'
            )
            ->addColumn(
                'last_migration',
                TableInterface::column_type_TEXT,
                255,
                ['nullable' => true],
                'Last Migration'
            )
            ->addColumn(
                'updated_at',
                TableInterface::column_type_TIMESTAMP,
                null,
                ['nullable' => false, 'default' => TableInterface::TIMESTAMP_INIT_UPDATE],
                'Updated At'
            )
            ->setComment('Module Versions Table');
            
        $setup->getConnection()->createTable($table);
        
        // 添加唯一索引
        $setup->getConnection()->addIndex(
            $setup->getTable('weline_database_module_versions'),
            'idx_module_versions_name',
            ['module_name'],
            'UNIQUE'
        );
    }
}
