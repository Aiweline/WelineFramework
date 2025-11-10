<?php
/**
 * 系统升级事件监听器
 * 监听系统升级事件，自动执行所有模块的迁移
 * 
 * @author WelineFramework
 * @package Weline\Database\Observer
 */

namespace Weline\Database\Observer;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;

class SetupUpgradeObserver implements ObserverInterface
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
     * 处理系统升级事件
     * 
     * @param Event &$event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $this->printing->info("系统升级事件触发，开始检查所有模块的迁移");
        
        try {
            // 获取所有激活的模块
            $activeModules = $this->getActiveModules();
            
            if (empty($activeModules)) {
                $this->printing->info("没有发现激活的模块");
                return;
            }
            
            $this->printing->info("发现 " . count($activeModules) . " 个激活的模块");
            
            $totalMigrations = 0;
            $totalSuccess = 0;
            $totalFailed = 0;
            
            // 遍历所有模块
            foreach ($activeModules as $moduleName) {
                $this->printing->println('');
                $this->printing->info("检查模块: {$moduleName}");
                
                try {
                    // 获取模块的待执行迁移
                    $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
                    
                    if (empty($pendingMigrations)) {
                        $this->printing->info("模块 {$moduleName} 没有待执行的迁移");
                        continue;
                    }
                    
                    $this->printing->info("模块 {$moduleName} 发现 " . count($pendingMigrations) . " 个待执行的迁移");
                    
                    // 执行模块迁移
                    $result = $this->executeModuleMigrations($moduleName, $pendingMigrations);
                    
                    $totalMigrations += count($pendingMigrations);
                    $totalSuccess += $result['success'];
                    $totalFailed += $result['failed'];
                    
                } catch (\Exception $e) {
                    $this->printing->error("模块 {$moduleName} 迁移执行异常: " . $e->getMessage());
                    $totalFailed++;
                }
            }
            
            // 输出总体结果
            $this->printing->println('');
            $this->printing->info("=== 系统升级迁移执行完成 ===");
            $this->printing->info("总迁移数: {$totalMigrations}");
            $this->printing->info("成功: {$totalSuccess}");
            $this->printing->info("失败: {$totalFailed}");
            
            if ($totalFailed > 0) {
                $this->printing->error("存在失败的迁移，请检查日志并手动处理");
            } else {
                $this->printing->success("所有迁移执行成功");
            }
            
        } catch (\Exception $e) {
            $this->printing->error("系统升级迁移执行失败: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * 获取激活的模块列表
     * 
     * @return array
     */
    private function getActiveModules(): array
    {
        // 这里应该从框架的模块管理器中获取激活的模块
        // 暂时返回一些示例模块
        $modules = [];
        
        // 扫描 app/code 目录下的所有模块
        $codePath = 'app/code/';
        if (is_dir($codePath)) {
            $directories = glob($codePath . '*', GLOB_ONLYDIR);
            
            foreach ($directories as $dir) {
                $vendorName = basename($dir);
                $vendorPath = $dir . '/';
                
                if (is_dir($vendorPath)) {
                    $moduleDirs = glob($vendorPath . '*', GLOB_ONLYDIR);
                    
                    foreach ($moduleDirs as $moduleDir) {
                        $moduleName = basename($moduleDir);
                        $fullModuleName = $vendorName . '_' . $moduleName;
                        
                        // 检查模块是否有迁移目录
                        $migrationPath = $moduleDir . '/Setup/Db/Migration/';
                        if (is_dir($migrationPath)) {
                            $modules[] = $fullModuleName;
                        }
                    }
                }
            }
        }
        
        return $modules;
    }
    
    /**
     * 执行模块迁移
     * 
     * @param string $moduleName
     * @param array $pendingMigrations
     * @return array
     */
    private function executeModuleMigrations(string $moduleName, array $pendingMigrations): array
    {
        $successCount = 0;
        $failCount = 0;
        
        foreach ($pendingMigrations as $migration) {
            try {
                $this->printing->info("  执行迁移: {$migration['filename']}");
                
                $result = $this->migrationService->upgradeMigration(
                    $moduleName,
                    $migration['file']
                );
                
                if ($result) {
                    $successCount++;
                    $this->printing->success("  ✓ 迁移成功: {$migration['filename']}");
                } else {
                    $failCount++;
                    $this->printing->error("  ✗ 迁移失败: {$migration['filename']}");
                }
                
            } catch (\Exception $e) {
                $failCount++;
                $this->printing->error("  ✗ 迁移异常: {$migration['filename']} - " . $e->getMessage());
            }
        }
        
        return [
            'success' => $successCount,
            'failed' => $failCount
        ];
    }
}
