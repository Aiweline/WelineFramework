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
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

class SetupUpgradeObserver implements ObserverInterface
{
    private ?MigrationService $migrationService = null;
    private Printing $printing;

    public function __construct(Printing $printing)
    {
        $this->printing = $printing;
    }

    private function getMigrationService(): MigrationService
    {
        if ($this->migrationService === null) {
            $this->migrationService = ObjectManager::getInstance(MigrationService::class);
        }
        return $this->migrationService;
    }
    
    /**
     * 处理系统升级事件
     * 
     * @param Event &$event
     * @return void
     */
    public function execute(Event &$event): void
    {
        // 检查是否是部分更新模式（仅更新路由或模型）
        $eventData = $event->getData();
        $isPartialUpgrade = $eventData['is_partial_upgrade'] ?? false;
        $routeOnly = $eventData['route_only'] ?? false;
        $modelOnly = $eventData['model_only'] ?? false;
        
        // 如果是仅更新路由模式，跳过数据库迁移（数据库迁移应该在完整升级或仅更新模型时执行）
        if ($routeOnly) {
            $this->printing->info("检测到仅更新路由模式，跳过数据库迁移执行");
            return;
        }
        
        // 如果是仅更新模型模式，可以执行数据库迁移
        // 完整升级模式也会执行数据库迁移
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
                $this->printing->printing('');
                $this->printing->info("检查模块: {$moduleName}");
                
                try {
                    // 获取模块的待执行迁移
                    $pendingMigrations = $this->getMigrationService()->getPendingMigrations($moduleName);
                    
                    if (empty($pendingMigrations)) {
                        $this->printing->info("模块 {$moduleName} 没有待执行的迁移");
                        continue;
                    }
                    
                    $this->printing->info("模块 {$moduleName} 发现 " . count($pendingMigrations) . " 个待执行的迁移");
                    
                    $count = count($pendingMigrations);
                    // 执行模块迁移
                    $result = $this->executeModuleMigrations($moduleName, $pendingMigrations);
                    unset($pendingMigrations);
                    $totalMigrations += $count;
                    $totalSuccess += $result['success'];
                    $totalFailed += $result['failed'];
                    gc_collect_cycles();
                } catch (\Exception $e) {
                    $this->printing->error("模块 {$moduleName} 迁移执行异常: " . $e->getMessage());
                    $totalFailed++;
                }
            }
            
            // 输出总体结果
            $this->printing->printing('');
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
     * 从已注册的模块列表中获取带迁移目录的激活模块（不扫描磁盘，避免大量 glob 与内存占用）
     *
     * @return array<string>
     */
    private function getActiveModules(): array
    {
        $active = Env::getInstance()->getActiveModules();
        $modules = [];
        foreach ($active as $name => $info) {
            $basePath = $info['base_path'] ?? '';
            if ($basePath !== '' && is_dir($basePath . 'Setup/Db/Migration/')) {
                $modules[] = $name;
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
                
                $result = $this->getMigrationService()->upgradeMigration(
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
