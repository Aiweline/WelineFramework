<?php
/**
 * 数据库迁移记录模型
 * 
 * @author WelineFramework
 * @package Weline\Database\Model
 */

namespace Weline\Database\Model;

use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

class Migration extends Model implements ModelInterface
{
    public const fields_ID = 'migration_id';
    public const fields_MODULE = 'module_name';
    public const fields_VERSION = 'version';
    public const fields_FILE = 'migration_file';
    public const fields_DESCRIPTION = 'description';
    public const fields_STATUS = 'status';
    public const fields_EXECUTED_AT = 'executed_at';
    public const fields_ROLLBACK_AT = 'rollback_at';
    public const fields_DEPENDENCIES = 'dependencies';
    public const fields_CHECKSUM = 'checksum';
    public const fields_CREATED_AT = 'created_at';
    public const fields_UPDATED_AT = 'updated_at';
    
    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_FAILED = 'failed';
    public const STATUS_MANUAL = 'manual';
    
    public function _construct()
    {
        $this->init('weline_database_migrations', self::fields_ID);
    }
    
    /**
     * 设置模型
     */
    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    /**
     * 升级模型
     */
    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    /**
     * 安装模型
     */
    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('Database Migrations Table')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'Migration ID')
                ->addColumn(self::fields_MODULE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', 'Module Name')
                ->addColumn(self::fields_VERSION, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', 'Version')
                ->addColumn(self::fields_FILE, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', 'Migration File')
                ->addColumn(self::fields_DESCRIPTION, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'default null', 'Description')
                ->addColumn(self::fields_STATUS, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null default \'pending\'', 'Status')
                ->addColumn(self::fields_EXECUTED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'default null', 'Executed At')
                ->addColumn(self::fields_ROLLBACK_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'default null', 'Rollback At')
                ->addColumn(self::fields_DEPENDENCIES, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TEXT, null, 'default null', 'Dependencies')
                ->addColumn(self::fields_CHECKSUM, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'default null', 'Checksum')
                ->addColumn(self::fields_CREATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Created At')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Updated At')
                ->create();
        }
    }
    
    /**
     * 获取模块的所有迁移记录
     *
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getModuleMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::fields_MODULE, $moduleName)
            ->order(self::fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 获取已安装的迁移
     *
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getInstalledMigrations(string $moduleName): array
    {
        return $this->reset()
            ->where(self::fields_MODULE, $moduleName)
            ->where(self::fields_STATUS, self::STATUS_INSTALLED)
            ->order(self::fields_EXECUTED_AT, 'ASC')
            ->select()
            ->fetch()
            ->getItems();
    }

    /**
     * 检查迁移是否已存在
     *
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件名
     * @return bool
     */
    public function isMigrationExists(string $moduleName, string $migrationFile): bool
    {
        return $this->reset()
            ->where(self::fields_MODULE, $moduleName)
            ->where(self::fields_FILE, $migrationFile)
            ->total() > 0;
    }
    
    /**
     * 记录迁移执行
     * 
     * @param array $data 迁移数据
     * @return int 插入的迁移记录 ID，失败返回 0
     */
    public function recordMigration(array $data): int
    {
        $this->clearData();
        $this->setData([
            self::fields_MODULE => $data['module_name'],
            self::fields_VERSION => $data['version'],
            self::fields_FILE => $data['migration_file'],
            self::fields_DESCRIPTION => $data['description'] ?? '',
            self::fields_STATUS => $data['status'],
            self::fields_DEPENDENCIES => json_encode($data['dependencies'] ?? []),
            self::fields_CHECKSUM => $data['checksum'] ?? '',
            self::fields_EXECUTED_AT => $data['executed_at'] ?? date('Y-m-d H:i:s')
        ]);
        
        $saved = $this->save();
        return $saved ? (int) $this->getId() : 0;
    }
    
    /**
     * 更新迁移状态
     * 
     * @param string $status 新状态
     * @return bool
     */
    public function updateStatus(string $status): bool
    {
        $this->setData(self::fields_STATUS, $status);
        
        if ($status === self::STATUS_ROLLED_BACK) {
            $this->setData(self::fields_ROLLBACK_AT, date('Y-m-d H:i:s'));
        }
        
        return $this->save();
    }
    
    /**
     * 按模块名和文件名查找迁移记录 ID
     *
     * @param string $moduleName 模块名称
     * @param string $migrationFile 迁移文件名
     * @return int 记录 ID，未找到返回 0
     */
    public function findMigrationId(string $moduleName, string $migrationFile): int
    {
        $items = $this->reset()
            ->where(self::fields_MODULE, $moduleName)
            ->where(self::fields_FILE, $migrationFile)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $first = $items[0] ?? null;
        return $first && $first->getId() ? (int) $first->getId() : 0;
    }

    /**
     * 获取迁移统计信息
     *
     * @param string $moduleName 模块名称
     * @return array{total: int, installed: int, failed: int, pending: int}
     */
    public function getMigrationStats(string $moduleName): array
    {
        $total = $this->reset()
            ->where(self::fields_MODULE, $moduleName)
            ->total();
        $installed = $this->reset()
            ->where(self::fields_MODULE, $moduleName)
            ->where(self::fields_STATUS, self::STATUS_INSTALLED)
            ->total();
        $failed = $this->reset()
            ->where(self::fields_MODULE, $moduleName)
            ->where(self::fields_STATUS, self::STATUS_FAILED)
            ->total();
        return [
            'total' => $total,
            'installed' => $installed,
            'failed' => $failed,
            'pending' => $total - $installed - $failed
        ];
    }
}
