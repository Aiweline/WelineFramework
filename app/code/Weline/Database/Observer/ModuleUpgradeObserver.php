<?php
/**
 * 模块升级事件监听器
 * 监听模块升级事件，自动执行迁移
 * 
 * @author WelineFramework
 * @package Weline\Database\Observer
 */

namespace Weline\Database\Observer;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Observer\ObserverAbstract;
use Weline\Framework\Output\Cli\Printing;

class ModuleUpgradeObserver extends ObserverAbstract implements ObserverInterface
{
    private MigrationService $migrationService;
    private Printing $printing;
    
    public function __construct(
        MigrationService $migrationService,
        Printing $printing
    ) {
        $this->migrationService = $migrationService;
        $this->printing = $printing;
    }
    
    /**
     * 处理模块升级事件
     * 
     * @param \Weline\Framework\Event\Observer\ObserverAbstract $observer
     * @param array $data
     * @return void
     */
    public function execute(\Weline\Framework\Event\Observer\ObserverAbstract $observer, array $data = []): void
    {
        $moduleName = $data['module_name'] ?? '';
        $oldVersion = $data['old_version'] ?? '';
        $newVersion = $data['new_version'] ?? '';
        
        if (empty($moduleName)) {
            $this->printing->error('模块升级事件缺少模块名称');
            return;
        }
        
        $this->printing->info("检测到模块升级: {$moduleName} {$oldVersion} -> {$newVersion}");
        
        try {
            // 获取待执行的迁移
            $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
            
            if (empty($pendingMigrations)) {
                $this->printing->info("模块 {$moduleName} 没有待执行的迁移");
                return;
            }
            
            $this->printing->info("发现 " . count($pendingMigrations) . " 个待执行的迁移");
            
            // 检查模块版本是否真的发生了变化
            if (!$this->isVersionChanged($moduleName, $oldVersion, $newVersion)) {
                $this->printing->warning("模块版本未发生变化，但检测到新的迁移文件");
                $this->printing->warning("请确认是否需要执行这些迁移");
                
                // 显示待执行的迁移列表
                foreach ($pendingMigrations as $migration) {
                    $this->printing->println("  - {$migration['filename']}");
                }
                
                $this->printing->error("迁移执行已中断，请手动确认后执行");
                return;
            }
            
            // 执行迁移
            $this->executeMigrations($moduleName, $pendingMigrations);
            
        } catch (\Exception $e) {
            $this->printing->error("模块升级迁移执行失败: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 检查模块版本是否真的发生了变化
     * 
     * @param string $moduleName
     * @param string $oldVersion
     * @param string $newVersion
     * @return bool
     */
    private function isVersionChanged(string $moduleName, string $oldVersion, string $newVersion): bool
    {
        // 如果版本号不同，说明真的升级了
        if ($oldVersion !== $newVersion) {
            return true;
        }
        
        // 如果版本号相同，但检测到新迁移文件，需要用户确认
        return false;
    }
    
    /**
     * 执行迁移
     * 
     * @param string $moduleName
     * @param array $pendingMigrations
     */
    private function executeMigrations(string $moduleName, array $pendingMigrations): void
    {
        $successCount = 0;
        $failCount = 0;
        
        foreach ($pendingMigrations as $migration) {
            try {
                $this->printing->info("执行迁移: {$migration['filename']}");
                
                $result = $this->migrationService->upgradeMigration(
                    $moduleName,
                    $migration['file']
                );
                
                if ($result) {
                    $successCount++;
                    $this->printing->success("迁移成功: {$migration['filename']}");
                } else {
                    $failCount++;
                    $this->printing->error("迁移失败: {$migration['filename']}");
                }
                
            } catch (\Exception $e) {
                $failCount++;
                $this->printing->error("迁移异常: {$migration['filename']} - " . $e->getMessage());
            }
        }
        
        // 输出执行结果
        $this->printing->println('');
        $this->printing->info("迁移执行完成:");
        $this->printing->info("  成功: {$successCount}");
        $this->printing->info("  失败: {$failCount}");
        
        if ($failCount > 0) {
            $this->printing->error("存在失败的迁移，请检查日志并手动处理");
        }
    }
}
