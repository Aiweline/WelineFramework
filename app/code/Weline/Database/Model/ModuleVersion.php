<?php

declare(strict_types=1);

/**
 * 模块版本模型
 * 表结构由 SchemaDiffStage 根据 #[Col] 同步。
 *
 * @author WelineFramework
 * @package Weline\Database\Model
 */

namespace Weline\Database\Model;

use Weline\Framework\Database\Model;
use Weline\Framework\Database\Schema\Attribute\Col;
use Weline\Framework\Database\Schema\Attribute\Table as TableAttribute;

#[TableAttribute(comment: 'Module Versions Table')]
class ModuleVersion extends Model
{
    /** 列名 module_name 与 AbstractModel::$module_name 冲突，用 name 指定列名 */

    public const fields_ID = 'id';
    public const fields_MODULE_NAME = 'module_name';
    public const fields_CURRENT_VERSION = 'current_version';
    public const fields_LAST_MIGRATION = 'last_migration';
    public const fields_UPDATED_AT = 'updated_at';

    /** 供 getModelFields() 使用，与 fields_* 同值 */
    #[Col('int', primaryKey: true, autoIncrement: true, nullable: false, comment: 'ID')]
    public const schema_fields_ID = 'id';
    #[Col('varchar', 100, nullable: false, comment: 'Module Name')]
    public const schema_fields_MODULE_NAME = 'module_name';
    #[Col('varchar', 50, nullable: false, comment: 'Current Version')]
    public const schema_fields_CURRENT_VERSION = 'current_version';
    #[Col('varchar', 255, comment: 'Last Migration')]
    public const schema_fields_LAST_MIGRATION = 'last_migration';
    #[Col('timestamp', nullable: true, default: 'CURRENT_TIMESTAMP', comment: 'Updated At')]
    public const schema_fields_UPDATED_AT = 'updated_at';

    public function _construct()
    {
        $this->init('weline_database_module_versions', self::schema_fields_ID);
    }

    /**
     * 获取模块当前版本
     * 
     * @param string $moduleName 模块名称
     * @return string|null
     */
    public function getCurrentVersion(string $moduleName): ?string
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE_NAME, $moduleName)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $version = $items[0] ?? null;
        return $version && $version->getId() ? $version->getData(self::schema_fields_CURRENT_VERSION) : null;
    }
    
    /**
     * 获取模块最后执行的迁移
     * 
     * @param string $moduleName 模块名称
     * @return string|null
     */
    public function getLastMigration(string $moduleName): ?string
    {
        $items = $this->reset()
            ->where(self::schema_fields_MODULE_NAME, $moduleName)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $version = $items[0] ?? null;
        return $version && $version->getId() ? $version->getData(self::schema_fields_LAST_MIGRATION) : null;
    }
    
    /**
     * 检查模块版本是否存在
     * 
     * @param string $moduleName 模块名称
     * @return bool
     */
    public function isModuleExists(string $moduleName): bool
    {
        return $this->reset()
            ->where(self::schema_fields_MODULE_NAME, $moduleName)
            ->total() > 0;
    }
    
    /**
     * 获取所有模块的版本信息
     * 
     * @return array
     */
    public function getAllModuleVersions(): array
    {
        return $this->reset()
            ->order(self::schema_fields_UPDATED_AT, 'DESC')
            ->select()
            ->fetch()
            ->getItems();
    }
    
    /**
     * 获取版本统计信息
     * 
     * @return array
     */
    public function getVersionStats(): array
    {
        $modules = $this->reset()
            ->select()
            ->fetch()
            ->getItems();
        
        $stats = [
            'total_modules' => count($modules),
            'version_distribution' => [],
            'recent_updates' => []
        ];
        
        foreach ($modules as $module) {
            $version = $module->getData(self::schema_fields_CURRENT_VERSION);
            $updatedAt = $module->getData(self::schema_fields_UPDATED_AT);
            
            // 版本分布统计
            if (!isset($stats['version_distribution'][$version])) {
                $stats['version_distribution'][$version] = 0;
            }
            $stats['version_distribution'][$version]++;
            
            // 最近更新
            $stats['recent_updates'][] = [
                'module' => $module->getData(self::schema_fields_MODULE_NAME),
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
