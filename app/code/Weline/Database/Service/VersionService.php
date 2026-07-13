<?php
/**
 * 模块版本管理服务
 * 
 * @author WelineFramework
 * @package Weline\Database\Service
 */

declare(strict_types=1);

namespace Weline\Database\Service;

use Weline\Database\Model\ModuleVersion;
use Weline\Database\Model\ModuleVersionHistory;
use Weline\Framework\Output\Cli\Printing;

class VersionService
{
    private ModuleVersion $versionModel;
    private ModuleVersionHistory $historyModel;
    private Printing $printing;
    
    public function __construct(
        ModuleVersion $versionModel,
        ModuleVersionHistory $historyModel,
        Printing $printing
    ) {
        $this->versionModel = $versionModel;
        $this->historyModel = $historyModel;
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
                    ModuleVersion::schema_fields_CURRENT_VERSION => $newVersion,
                    ModuleVersion::schema_fields_LAST_MIGRATION => $lastMigration,
                    ModuleVersion::schema_fields_UPDATED_AT => date('Y-m-d H:i:s')
                ]);
                $result = $existingVersion->save();
            } else {
                // 创建新记录
                $this->versionModel->setData([
                    ModuleVersion::schema_fields_MODULE_NAME => $moduleName,
                    ModuleVersion::schema_fields_CURRENT_VERSION => $newVersion,
                    ModuleVersion::schema_fields_LAST_MIGRATION => $lastMigration,
                    ModuleVersion::schema_fields_UPDATED_AT => date('Y-m-d H:i:s')
                ]);
                $result = $this->versionModel->save();
            }
            
            $success = (bool)$result;
            if ($success) {
                $this->printing->info(__("模块 %{1} 版本更新为 %{2}", [$moduleName, $newVersion]));
            }
            
            return $success;
            
        } catch (\Exception $e) {
            $this->printing->error(__("更新模块版本失败: %{1}", $e->getMessage()));
            return false;
        }
    }

    /**
     * Reconcile the durable database cursor after a successful setup run.
     *
     * The cursor may only move forward here. A cursor ahead of the runtime
     * manifest means code/database drift and must be repaired before setup or
     * rollback can continue.
     */
    public function reconcileSuccessfulSetup(
        string $moduleName,
        string $runtimeVersion,
        string $lastMigration = ''
    ): void {
        if (!$this->validateVersion($runtimeVersion)) {
            throw new \InvalidArgumentException(__(
                '模块 %{1} 的运行时版本 %{2} 不是有效语义版本',
                [$moduleName, $runtimeVersion]
            ));
        }

        $currentVersion = $this->getModuleVersionString($moduleName);
        if ($currentVersion !== null && version_compare($currentVersion, $runtimeVersion, '>')) {
            throw new \RuntimeException(__(
                '模块 %{1} 数据库版本游标 %{2} 高于代码版本 %{3}，已阻止继续升级',
                [$moduleName, $currentVersion, $runtimeVersion]
            ));
        }

        if ($currentVersion === $runtimeVersion) {
            if (!$this->updateModuleVersion($moduleName, $runtimeVersion, $lastMigration)) {
                throw new \RuntimeException(__('无法刷新模块 %{1} 的数据库版本游标', $moduleName));
            }
            return;
        }

        $fromVersion = $currentVersion ?? '0.0.0';
        if (!$this->updateModuleVersion($moduleName, $runtimeVersion, $lastMigration)) {
            throw new \RuntimeException(__('无法建立模块 %{1} 的数据库版本基线', $moduleName));
        }

        $this->recordVersionHistory(
            $moduleName,
            $fromVersion,
            $runtimeVersion,
            $currentVersion === null ? ModuleVersionHistory::ACTION_INSTALL : ModuleVersionHistory::ACTION_UPGRADE,
            $lastMigration
        );
    }
    
    /**
     * 获取模块版本
     * 
     * @param string $moduleName 模块名称
     * @return ModuleVersion|null
     */
    public function getModuleVersion(string $moduleName): ?ModuleVersion
    {
        $items = $this->versionModel->reset()
            ->where(ModuleVersion::schema_fields_MODULE_NAME, $moduleName)
            ->limit(1)
            ->select()
            ->fetch()
            ->getItems();
        $version = $items[0] ?? null;
        return $version && $version->getId() ? $version : null;
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
        
        $oldVersion = $currentVersion->getData(ModuleVersion::schema_fields_CURRENT_VERSION);
        
        return $oldVersion !== $newVersion;
    }
    
    /**
     * 获取所有模块版本信息
     * 
     * @return array
     */
    public function getAllModuleVersions(): array
    {
        return $this->versionModel->getAllModuleVersions();
    }
    
    /**
     * 获取模块版本历史
     * 
     * @param string $moduleName 模块名称
     * @param int $limit 限制数量
     * @return array
     */
    public function getModuleVersionHistory(string $moduleName, int $limit = 50): array
    {
        $history = $this->historyModel->getModuleHistory($moduleName, $limit);
        
        $result = [];
        foreach ($history as $record) {
            $result[] = [
                'from_version' => $record->getData(ModuleVersionHistory::schema_fields_FROM_VERSION),
                'to_version' => $record->getData(ModuleVersionHistory::schema_fields_TO_VERSION),
                'action' => $record->getData(ModuleVersionHistory::schema_fields_ACTION),
                'migration_file' => $record->getData(ModuleVersionHistory::schema_fields_MIGRATION_FILE),
                'operator' => $record->getData(ModuleVersionHistory::schema_fields_OPERATOR),
                'created_at' => $record->getData(ModuleVersionHistory::schema_fields_CREATED_AT),
            ];
        }
        
        return $result;
    }
    
    /**
     * 获取版本操作统计
     * 
     * @param string $moduleName 模块名称
     * @return array
     */
    public function getVersionActionStats(string $moduleName): array
    {
        return $this->historyModel->getActionStats($moduleName);
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
        $modules = $this->versionModel->reset()
            ->select()
            ->fetch()
            ->getItems();
        
        $stats = [
            'total_modules' => count($modules),
            'version_distribution' => [],
            'recent_updates' => []
        ];
        
        foreach ($modules as $module) {
            $version = $module->getData(ModuleVersion::schema_fields_CURRENT_VERSION);
            $updatedAt = $module->getData(ModuleVersion::schema_fields_UPDATED_AT);
            
            // 版本分布统计
            if (!isset($stats['version_distribution'][$version])) {
                $stats['version_distribution'][$version] = 0;
            }
            $stats['version_distribution'][$version]++;
            
            // 最近更新
            $stats['recent_updates'][] = [
                'module' => $module->getData(ModuleVersion::schema_fields_MODULE_NAME),
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
    
    /**
     * 设置模块版本（updateModuleVersion 的别名）
     * 
     * @param string $moduleName 模块名称
     * @param string $version 版本号
     * @return bool
     */
    public function setModuleVersion(string $moduleName, string $version): bool
    {
        return $this->updateModuleVersion($moduleName, $version);
    }
    
    /**
     * 回滚模块版本
     * 
     * @param string $moduleName 模块名称
     * @param string $toVersion 目标版本
     * @param string $migrationFile 迁移文件（可选）
     * @return bool
     */
    public function rollbackModuleVersion(string $moduleName, string $toVersion, string $migrationFile = ''): bool
    {
        $this->printing->error(__(
            '已禁止单独修改模块数据库版本；请使用 ModuleRollbackManagerInterface 执行联动回滚'
        ));
        return false;
    }

    /**
     * Commit a version cursor after the coordinated rollback executor has
     * already switched code, schema and scripts successfully.
     */
    public function finalizeCoordinatedRollback(
        string $moduleName,
        string $fromVersion,
        string $toVersion,
        string $operationId,
    ): void {
        $currentVersion = $this->getModuleVersionString($moduleName);
        if ($currentVersion !== $fromVersion) {
            throw new \RuntimeException(__(
                '模块 %{1} 版本游标已漂移：计划 %{2}，实际 %{3}',
                [$moduleName, $fromVersion, (string)$currentVersion]
            ));
        }
        if (!$this->updateModuleVersion($moduleName, $toVersion, $operationId)) {
            throw new \RuntimeException(__('无法提交模块 %{1} 的回滚版本游标', $moduleName));
        }
        $this->recordVersionHistory(
            $moduleName,
            $fromVersion,
            $toVersion,
            ModuleVersionHistory::ACTION_ROLLBACK,
            $operationId
        );
    }
    
    /**
     * 升级模块版本
     * 
     * @param string $moduleName 模块名称
     * @param string $toVersion 目标版本
     * @param string $migrationFile 迁移文件（可选）
     * @return bool
     */
    public function upgradeModuleVersion(string $moduleName, string $toVersion, string $migrationFile = ''): bool
    {
        $currentVersion = $this->getModuleVersionString($moduleName) ?? '0.0.0';
        
        if (version_compare($currentVersion, $toVersion, '>=')) {
            $this->printing->warning(__('当前版本 %{1} 已经是最新或更高版本', $currentVersion));
            return false;
        }
        
        $result = $this->updateModuleVersion($moduleName, $toVersion);
        if ($result) {
            $action = $currentVersion === '0.0.0' ? ModuleVersionHistory::ACTION_INSTALL : ModuleVersionHistory::ACTION_UPGRADE;
            $this->recordVersionHistory($moduleName, $currentVersion, $toVersion, $action, $migrationFile);
            $this->printing->info(__('模块 %{1} 版本已升级: %{2} -> %{3}', [$moduleName, $currentVersion, $toVersion]));
        }
        
        return $result;
    }
    
    /**
     * 记录版本历史
     * 
     * @param string $moduleName 模块名称
     * @param string $fromVersion 原版本
     * @param string $toVersion 目标版本
     * @param string $action 操作类型
     * @param string $migrationFile 迁移文件
     * @return void
     */
    private function recordVersionHistory(
        string $moduleName, 
        string $fromVersion, 
        string $toVersion, 
        string $action,
        string $migrationFile = ''
    ): void {
        try {
            $operator = php_sapi_name() === 'cli' ? ModuleVersionHistory::OPERATOR_CLI : ModuleVersionHistory::OPERATOR_ADMIN;
            
            $this->historyModel->reset()->setData([
                ModuleVersionHistory::schema_fields_MODULE_NAME => $moduleName,
                ModuleVersionHistory::schema_fields_FROM_VERSION => $fromVersion,
                ModuleVersionHistory::schema_fields_TO_VERSION => $toVersion,
                ModuleVersionHistory::schema_fields_ACTION => $action,
                ModuleVersionHistory::schema_fields_MIGRATION_FILE => $migrationFile,
                ModuleVersionHistory::schema_fields_OPERATOR => $operator,
                ModuleVersionHistory::schema_fields_CREATED_AT => date('Y-m-d H:i:s'),
            ])->save();
        } catch (\Exception $e) {
            $this->printing->warning(__('记录版本历史失败: %{1}', $e->getMessage()));
        }
    }
    
    /**
     * 验证版本号格式
     * 支持语义化版本：X.Y.Z 或 X.Y.Z-suffix（如 1.0.0-beta.1）
     * 
     * @param string $version 版本号
     * @return bool
     */
    public function validateVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version) === 1;
    }
    
    /**
     * 检查是否有版本更新（isVersionChanged 的别名）
     * 
     * @param string $moduleName 模块名称
     * @param string $newVersion 新版本
     * @return bool
     */
    public function checkVersionUpdate(string $moduleName, string $newVersion): bool
    {
        return $this->isVersionChanged($moduleName, $newVersion);
    }
    
    /**
     * 获取模块版本字符串
     * 
     * @param string $moduleName 模块名称
     * @return string|null
     */
    public function getModuleVersionString(string $moduleName): ?string
    {
        $version = $this->getModuleVersion($moduleName);
        return $version?->getData(ModuleVersion::schema_fields_CURRENT_VERSION);
    }
}
