<?php
/**
 * 数据库迁移记录模型
 * 
 * @author WelineFramework
 * @package Weline\Database\Model
 */

namespace Weline\Database\Model;

use Weline\Framework\Database\Api\Db\ModelInterface;
use Weline\Framework\Database\Model;

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
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ROLLED_BACK = 'rolled_back';
    public const STATUS_FAILED = 'failed';
    
    public function _construct()
    {
        $this->init('weline_database_migrations', self::fields_ID);
    }
    
    /**
     * 获取模块的所有迁移记录
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getModuleMigrations(string $moduleName): array
    {
        $collection = $this->getCollection();
        $collection->addFieldToFilter(self::fields_MODULE, $moduleName);
        $collection->setOrder(self::fields_EXECUTED_AT, 'ASC');
        
        return $collection->getItems();
    }
    
    /**
     * 获取已安装的迁移
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getInstalledMigrations(string $moduleName): array
    {
        $collection = $this->getCollection();
        $collection->addFieldToFilter(self::fields_MODULE, $moduleName);
        $collection->addFieldToFilter(self::fields_STATUS, self::STATUS_INSTALLED);
        $collection->setOrder(self::fields_EXECUTED_AT, 'ASC');
        
        return $collection->getItems();
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
        $collection = $this->getCollection();
        $collection->addFieldToFilter(self::fields_MODULE, $moduleName);
        $collection->addFieldToFilter(self::fields_FILE, $migrationFile);
        
        return $collection->getSize() > 0;
    }
    
    /**
     * 记录迁移执行
     * 
     * @param array $data 迁移数据
     * @return bool
     */
    public function recordMigration(array $data): bool
    {
        $this->setData([
            self::fields_MODULE => $data['module_name'],
            self::fields_VERSION => $data['version'],
            self::fields_FILE => $data['migration_file'],
            self::fields_DESCRIPTION => $data['description'],
            self::fields_STATUS => $data['status'],
            self::fields_DEPENDENCIES => json_encode($data['dependencies'] ?? []),
            self::fields_CHECKSUM => $data['checksum'] ?? '',
            self::fields_EXECUTED_AT => $data['executed_at'] ?? date('Y-m-d H:i:s')
        ]);
        
        return $this->save();
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
     * 获取迁移统计信息
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getMigrationStats(string $moduleName): array
    {
        $collection = $this->getCollection();
        $collection->addFieldToFilter(self::fields_MODULE, $moduleName);
        
        $total = $collection->getSize();
        
        $installedCollection = clone $collection;
        $installedCollection->addFieldToFilter(self::fields_STATUS, self::STATUS_INSTALLED);
        $installed = $installedCollection->getSize();
        
        $failedCollection = clone $collection;
        $failedCollection->addFieldToFilter(self::fields_STATUS, self::STATUS_FAILED);
        $failed = $failedCollection->getSize();
        
        return [
            'total' => $total,
            'installed' => $installed,
            'failed' => $failed,
            'pending' => $total - $installed - $failed
        ];
    }
}
