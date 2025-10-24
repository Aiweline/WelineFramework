<?php
/**
 * 模块版本管理服务
 * 
 * @author WelineFramework
 * @package Weline\Database\Service
 */

namespace Weline\Database\Service;

use Weline\Database\Model\ModuleVersion;
use Weline\Framework\Output\Cli\Printing;

class VersionService
{
    private ModuleVersion $versionModel;
    private Printing $printing;
    
    public function __construct(
        ModuleVersion $versionModel,
        Printing $printing
    ) {
        $this->versionModel = $versionModel;
        $this->printing = $printing;
    }
    
    /**
     * 更新模块版本
     * 
     * @param string $moduleName 模块名称
     * @param string $newVersion 新版本
     * @param string $lastMigration 最后执行的迁移
     * @return bool
     */
    public function updateModuleVersion(string $moduleName, string $newVersion, string $lastMigration = ''): bool
    {
        try {
            // 检查模块版本记录是否存在
            $existingVersion = $this->getModuleVersion($moduleName);
            
            if ($existingVersion) {
                // 更新现有记录
                $existingVersion->setData([
                    ModuleVersion::fields_CURRENT_VERSION => $newVersion,
                    ModuleVersion::fields_LAST_MIGRATION => $lastMigration,
                    ModuleVersion::fields_UPDATED_AT => date('Y-m-d H:i:s')
                ]);
                $result = $existingVersion->save();
            } else {
                // 创建新记录
                $this->versionModel->setData([
                    ModuleVersion::fields_MODULE_NAME => $moduleName,
                    ModuleVersion::fields_CURRENT_VERSION => $newVersion,
                    ModuleVersion::fields_LAST_MIGRATION => $lastMigration,
                    ModuleVersion::fields_UPDATED_AT => date('Y-m-d H:i:s')
                ]);
                $result = $this->versionModel->save();
            }
            
            if ($result) {
                $this->printing->info("模块 {$moduleName} 版本更新为 {$newVersion}");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->printing->error("更新模块版本失败: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * 获取模块版本
     * 
     * @param string $moduleName 模块名称
     * @return ModuleVersion|null
     */
    public function getModuleVersion(string $moduleName): ?ModuleVersion
    {
        $collection = $this->versionModel->getCollection();
        $collection->addFieldToFilter(ModuleVersion::fields_MODULE_NAME, $moduleName);
        
        $version = $collection->getFirstItem();
        
        return $version->getId() ? $version : null;
    }
    
    /**
     * 检查模块版本是否发生变化
     * 
     * @param string $moduleName 模块名称
     * @param string $newVersion 新版本
     * @return bool
     */
    public function isVersionChanged(string $moduleName, string $newVersion): bool
    {
        $currentVersion = $this->getModuleVersion($moduleName);
        
        if (!$currentVersion) {
            return true; // 新模块
        }
        
        $oldVersion = $currentVersion->getData(ModuleVersion::fields_CURRENT_VERSION);
        
        return $oldVersion !== $newVersion;
    }
    
    /**
     * 获取所有模块版本信息
     * 
     * @return array
     */
    public function getAllModuleVersions(): array
    {
        $collection = $this->versionModel->getCollection();
        $collection->setOrder(ModuleVersion::fields_UPDATED_AT, 'DESC');
        
        return $collection->getItems();
    }
    
    /**
     * 获取模块版本历史
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getModuleVersionHistory(string $moduleName): array
    {
        // 这里可以实现版本历史记录功能
        // 暂时返回当前版本信息
        $currentVersion = $this->getModuleVersion($moduleName);
        
        if (!$currentVersion) {
            return [];
        }
        
        return [
            [
                'version' => $currentVersion->getData(ModuleVersion::fields_CURRENT_VERSION),
                'last_migration' => $currentVersion->getData(ModuleVersion::fields_LAST_MIGRATION),
                'updated_at' => $currentVersion->getData(ModuleVersion::fields_UPDATED_AT)
            ]
        ];
    }
    
    /**
     * 比较版本号
     * 
     * @param string $version1 版本1
     * @param string $version2 版本2
     * @return int -1: version1 < version2, 0: 相等, 1: version1 > version2
     */
    public function compareVersions(string $version1, string $version2): int
    {
        return version_compare($version1, $version2);
    }
    
    /**
     * 检查版本兼容性
     * 
     * @param string $currentVersion 当前版本
     * @param string $requiredVersion 要求版本
     * @return bool
     */
    public function isVersionCompatible(string $currentVersion, string $requiredVersion): bool
    {
        return version_compare($currentVersion, $requiredVersion, '>=');
    }
    
    /**
     * 获取版本统计信息
     * 
     * @return array
     */
    public function getVersionStats(): array
    {
        $collection = $this->versionModel->getCollection();
        $modules = $collection->getItems();
        
        $stats = [
            'total_modules' => count($modules),
            'version_distribution' => [],
            'recent_updates' => []
        ];
        
        foreach ($modules as $module) {
            $version = $module->getData(ModuleVersion::fields_CURRENT_VERSION);
            $updatedAt = $module->getData(ModuleVersion::fields_UPDATED_AT);
            
            // 版本分布统计
            if (!isset($stats['version_distribution'][$version])) {
                $stats['version_distribution'][$version] = 0;
            }
            $stats['version_distribution'][$version]++;
            
            // 最近更新
            $stats['recent_updates'][] = [
                'module' => $module->getData(ModuleVersion::fields_MODULE_NAME),
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
