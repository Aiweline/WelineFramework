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
        $operationId = trim((string)($eventData['operation_id'] ?? ''));
        if (strlen($operationId) > 64) {
            throw new \InvalidArgumentException(__('operation_id 长度不能超过 64 字符'));
        }
        $isPartialUpgrade = $eventData['is_partial_upgrade'] ?? false;
        $routeOnly = $eventData['route_only'] ?? false;
        $modelOnly = $eventData['model_only'] ?? false;
        $migrationsAlreadyRun = !empty($eventData['migrations_already_run']);
        if ($migrationsAlreadyRun) {
            $this->printing->info("文件迁移已在 ModuleSetup 前执行，跳过 upgrade_after 二次迁移，仅提交版本游标");
        }
        
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
                    $lastSuccessfulMigration = null;
                    if ($migrationsAlreadyRun) {
                        $this->printing->info("模块 {$moduleName} 文件迁移已执行，跳过二次扫描");
                    } else {
                        // 获取模块的待执行迁移
                        $pendingMigrations = $this->getMigrationService()->getPendingMigrations($moduleName);
                        if (empty($pendingMigrations)) {
                            $this->printing->info("模块 {$moduleName} 没有待执行的迁移");
                        } else {
                            $this->printing->info("模块 {$moduleName} 发现 " . count($pendingMigrations) . " 个待执行的迁移");
                            $count = count($pendingMigrations);
                            $result = $this->executeModuleMigrations(
                                $moduleName,
                                $pendingMigrations,
                                $operationId,
                            );
                            $lastSuccessfulMigration = $result['last_migration'];
                            $totalMigrations += $count;
                            $totalSuccess += $result['success'];
                        }
                    }

                    $moduleInfo = Env::getInstance()->getModuleInfo($moduleName) ?? [];
                    // 仅用已完成脚本版本推进 DB 游标，避免 version 超前于 setup_version。
                    // upgrade_migrations 位于 ModuleSetup 之前；存在 pending 标志时必须延后到
                    // upgrade_after，再用 ModuleSetup 已提交的 setup_version 完成 reconcile。
                    $targetVersion = (string)($moduleInfo['version'] ?? '');
                    $runtimeVersion = $this->resolveCompletedSetupVersion($moduleInfo);
                    if ($runtimeVersion === null) {
                        $databaseVersion = $this->getVersionService()->getModuleVersionString($moduleName);
                        if (
                            $targetVersion !== ''
                            && $databaseVersion !== null
                            && version_compare($databaseVersion, $targetVersion, '>')
                        ) {
                            throw new \RuntimeException(__(
                                '模块 %{1} 数据库版本游标 %{2} 高于目标代码版本 %{3}，已阻止继续升级',
                                [$moduleName, $databaseVersion, $targetVersion]
                            ));
                        }
                        $this->printing->info("模块 {$moduleName} Setup 脚本尚未完成，版本游标延后至 upgrade_after 提交");
                        unset($pendingMigrations);
                        continue;
                    }
                    $this->getVersionService()->reconcileSuccessfulSetup(
                        $moduleName,
                        $runtimeVersion,
                        $lastSuccessfulMigration,
                    );
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
     * 返回已经由 ModuleSetup 成功提交的版本；pending/installing 状态不得推进 DB 游标。
     */
    private function resolveCompletedSetupVersion(array $moduleInfo): ?string
    {
        if (
            !empty($moduleInfo['installing'])
            || !empty($moduleInfo['upgrading'])
            || !empty($moduleInfo['pending_setup_upgrade'])
        ) {
            return null;
        }

        $setupVersion = trim((string)($moduleInfo['setup_version'] ?? ''));
        if ($setupVersion !== '') {
            return $setupVersion;
        }

        $targetVersion = trim((string)($moduleInfo['version'] ?? ''));
        return $targetVersion !== '' ? $targetVersion : null;
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
        $requestedValues = is_array($requested) ? $requested : ($requested ? [$requested] : []);
        $requestedModules = [];
        foreach ($requestedValues as $requestedValue) {
            if (!is_scalar($requestedValue)) {
                continue;
            }
            foreach (preg_split('/[\s,]+/', trim((string)$requestedValue)) ?: [] as $moduleName) {
                if ($moduleName !== '') {
                    $requestedModules[$moduleName] = true;
                }
            }
        }
        $modules = [];
        foreach ($active as $name => $_info) {
            if ($requestedModules !== [] && !isset($requestedModules[$name])) {
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
     * @return array{success: int, failed: int, last_migration: ?string}
     */
    private function executeModuleMigrations(
        string $moduleName,
        array $pendingMigrations,
        string $operationId = '',
    ): array {
        $successCount = 0;
        $failCount = 0;
        $lastSuccessfulMigration = null;
        
        foreach ($pendingMigrations as $migration) {
            try {
                $this->printing->info("  执行迁移: {$migration['filename']}");
                
                $result = $this->getMigrationService()->upgradeMigration(
                    $moduleName,
                    $migration['file'],
                    $operationId,
                );
                
                if (!$result) {
                    throw new \RuntimeException(__('迁移返回失败状态: %{1}', $migration['filename']));
                }
                $successCount++;
                $lastSuccessfulMigration = (string)$migration['filename'];
                $this->printing->success("  ✓ 迁移成功: {$migration['filename']}");
                
            } catch (\Throwable $e) {
                $failCount++;
                $this->printing->error("  ✗ 迁移异常: {$migration['filename']} - " . $e->getMessage());
                throw $e;
            }
        }
        
        return [
            'success' => $successCount,
            'failed' => $failCount,
            'last_migration' => $lastSuccessfulMigration,
        ];
    }
}
