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
use Weline\Database\Service\VersionService;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Registry\Service\RegistryProgress;

class SetupUpgradeObserver implements ObserverInterface
{
    private ?MigrationService $migrationService = null;
    private ?VersionService $versionService = null;
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

    private function getVersionService(): VersionService
    {
        if ($this->versionService === null) {
            $this->versionService = ObjectManager::getInstance(VersionService::class);
        }
        return $this->versionService;
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
            $activeModules = $this->getActiveModules((array)($eventData['args'] ?? []));
            
            if (empty($activeModules)) {
                $this->printing->info("没有发现激活的模块");
                return;
            }
            
            $this->printing->info("发现 " . count($activeModules) . " 个激活的模块");
            RegistryProgress::section('setup:upgrade database migration observer');
            RegistryProgress::count('Database migration observer active modules', count($activeModules), 'modules');
            
            $totalMigrations = 0;
            $totalSuccess = 0;
            $totalFailed = 0;
            $moduleIndex = 0;

            // 遍历所有模块
            foreach ($activeModules as $moduleName) {
                $moduleIndex++;
                $this->printing->printing('');
                $this->printing->info("检查模块: {$moduleName}");
                RegistryProgress::module('Database migration module check', $moduleIndex, count($activeModules), $moduleName);

                try {
                    // 获取模块的待执行迁移
                    $pendingMigrations = $this->getMigrationService()->getPendingMigrations($moduleName);
                    
                    $lastMigration = '';
                    if (empty($pendingMigrations)) {
                        $this->printing->info("模块 {$moduleName} 没有待执行的迁移");
                    } else {
                        $this->printing->info("模块 {$moduleName} 发现 " . count($pendingMigrations) . " 个待执行的迁移");
                        $count = count($pendingMigrations);
                        $result = $this->executeModuleMigrations($moduleName, $pendingMigrations);
                        $lastMigration = (string)($pendingMigrations[$count - 1]['filename'] ?? '');
                        $totalMigrations += $count;
                        $totalSuccess += $result['success'];
                    }

                    $runtimeVersion = (string)(Env::getInstance()->getModuleInfo($moduleName)['version'] ?? '');
                    $this->getVersionService()->reconcileSuccessfulSetup($moduleName, $runtimeVersion, $lastMigration);
                    unset($pendingMigrations);
                } catch (\Throwable $e) {
                    $this->printing->error("模块 {$moduleName} 迁移执行异常: " . $e->getMessage());
                    RegistryProgress::log('Database migration module exception: ' . $moduleName . ' ' . $e->getMessage());
                    $totalFailed++;
                    throw $e;
                } finally {
                    $compaction = ObjectManager::relieveMemoryPressure(false);
                    $cycles = function_exists('gc_collect_cycles') ? gc_collect_cycles() : 0;
                    RegistryProgress::log(sprintf(
                        'Database migration module finished: %s memory_stores=%d metadata_entries=%d gc_cycles=%d',
                        $moduleName,
                        (int)($compaction['memory_store_clears'] ?? 0),
                        (int)($compaction['metadata_entries_cleared'] ?? 0),
                        (int)$cycles
                    ));
                }
            }
            
            // 输出总体结果
            $this->printing->printing('');
            $this->printing->info("=== 系统升级迁移执行完成 ===");
            $this->printing->info("总迁移数: {$totalMigrations}");
            $this->printing->info("成功: {$totalSuccess}");
            $this->printing->info("失败: {$totalFailed}");
            
            $this->printing->success("所有迁移执行成功");
            
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
    private function getActiveModules(array $args = []): array
    {
        $active = Env::getInstance()->getActiveModules();
        $requested = $args['module'] ?? $args['m'] ?? null;
        $requested = is_array($requested) ? $requested : ($requested ? [$requested] : []);
        $requested = array_fill_keys(array_map('strval', $requested), true);
        $modules = [];
        foreach ($active as $name => $_info) {
            if ($requested !== [] && !isset($requested[$name])) {
                continue;
            }
            $modules[] = $name;
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
                
                if (!$result) {
                    throw new \RuntimeException(__('迁移返回失败状态: %{1}', $migration['filename']));
                }
                $successCount++;
                $this->printing->success("  ✓ 迁移成功: {$migration['filename']}");
                
            } catch (\Throwable $e) {
                $failCount++;
                $this->printing->error("  ✗ 迁移异常: {$migration['filename']} - " . $e->getMessage());
                throw $e;
            }
        }
        
        return [
            'success' => $successCount,
            'failed' => $failCount
        ];
    }
}
