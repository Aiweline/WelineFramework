<?php
/**
 * 模块版本模型
 * 
 * @author WelineFramework
 * @package Weline\Database\Model
 */

namespace Weline\Database\Model;

use Weline\Framework\Database\ModelInterface;
use Weline\Framework\Database\Model;
use Weline\Framework\Setup\Db\ModelSetup;
use Weline\Framework\Setup\Data\Context;

class ModuleVersion extends Model implements ModelInterface
{
    public const fields_ID = 'id';
    public const fields_MODULE_NAME = 'module_name';
    public const fields_CURRENT_VERSION = 'current_version';
    public const fields_LAST_MIGRATION = 'last_migration';
    public const fields_UPDATED_AT = 'updated_at';
    
    public function _construct()
    {
        $this->init('weline_database_module_versions', self::fields_ID);
    }

    public function setup(ModelSetup $setup, Context $context): void
    {
        $this->install($setup, $context);
    }

    public function upgrade(ModelSetup $setup, Context $context): void
    {
    }

    public function install(ModelSetup $setup, Context $context): void
    {
        if ($setup->tableExist() === false) {
            $setup->createTable('Module Versions Table')
                ->addColumn(self::fields_ID, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_INTEGER, null, 'primary key auto_increment', 'ID')
                ->addColumn(self::fields_MODULE_NAME, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'not null', 'Module Name')
                ->addColumn(self::fields_CURRENT_VERSION, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 50, 'not null', 'Current Version')
                ->addColumn(self::fields_LAST_MIGRATION, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_VARCHAR, 255, 'default null', 'Last Migration')
                ->addColumn(self::fields_UPDATED_AT, \Weline\Framework\Database\Api\Db\Ddl\TableInterface::column_type_TIMESTAMP, null, 'default CURRENT_TIMESTAMP', 'Updated At')
                ->create();
        }
    }

    /**
     * 获取模块当前版本
     * 
     * @param string $moduleName 模块名称
     * @return string|null
     */
    public function getCurrentVersion(string $moduleName): ?string
    {
        $collection = $this->getCollection();
        $collection->addFieldToFilter(self::fields_MODULE_NAME, $moduleName);
        
        $version = $collection->getFirstItem();
        
        return $version->getId() ? $version->getData(self::fields_CURRENT_VERSION) : null;
    }
    
    /**
     * 获取模块最后执行的迁移
     * 
     * @param string $moduleName 模块名称
     * @return string|null
     */
    public function getLastMigration(string $moduleName): ?string
    {
        $collection = $this->getCollection();
        $collection->addFieldToFilter(self::fields_MODULE_NAME, $moduleName);
        
        $version = $collection->getFirstItem();
        
        return $version->getId() ? $version->getData(self::fields_LAST_MIGRATION) : null;
    }
    
    /**
     * 检查模块版本是否存在
     * 
     * @param string $moduleName 模块名称
     * @return bool
     */
    public function isModuleExists(string $moduleName): bool
    {
        $collection = $this->getCollection();
        $collection->addFieldToFilter(self::fields_MODULE_NAME, $moduleName);
        
        return $collection->getSize() > 0;
    }
    
    /**
     * 获取所有模块的版本信息
     * 
     * @return array
     */
    public function getAllModuleVersions(): array
    {
        $collection = $this->getCollection();
        $collection->setOrder(self::fields_UPDATED_AT, 'DESC');
        
        return $collection->getItems();
    }
    
    /**
     * 获取版本统计信息
     * 
     * @return array
     */
    public function getVersionStats(): array
    {
        $collection = $this->getCollection();
        $modules = $collection->getItems();
        
        $stats = [
            'total_modules' => count($modules),
            'version_distribution' => [],
            'recent_updates' => []
        ];
        
        foreach ($modules as $module) {
            $version = $module->getData(self::fields_CURRENT_VERSION);
            $updatedAt = $module->getData(self::fields_UPDATED_AT);
            
            // 版本分布统计
            if (!isset($stats['version_distribution'][$version])) {
                $stats['version_distribution'][$version] = 0;
            }
            $stats['version_distribution'][$version]++;
            
            // 最近更新
            $stats['recent_updates'][] = [
                'module' => $module->getData(self::fields_MODULE_NAME),
                'version' => $version,
                'updated_at' => $updatedAt
            ];
        }
        
        // 按更新时间排序
        usort($stats['recent_updates'], function($a, $b) {
            return strtotime($b['updated_at']) - strtotime($a['updated_at']);
        });
        
        // 只保留最近10个更新
        $stats['recent_updates'] = array_slice($stats['recent_updates'], 0, 10);
        
        return $stats;
    }
}
