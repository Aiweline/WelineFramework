<?php

declare(strict_types=1);

namespace Weline\Database\Observer;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Registry\Service\RegistryProgress;

class ModuleUpgradeObserver implements ObserverInterface
{
    private MigrationService $migrationService;
    private Printing $printing;

    public function __construct(
        MigrationService $migrationService,
        Printing $printing
    ) {
        $this->migrationService = $migrationService;
        $this->printing         = $printing;
    }

    public function execute(Event &$event): void
    {
        $data       = $event->getData('data') ?? [];
        $moduleName = $data['module_name'] ?? '';
        $oldVersion = $data['old_version'] ?? '';
        $newVersion = $data['new_version'] ?? '';

        if (empty($moduleName)) {
            return;
        }

        try {
            RegistryProgress::section('module upgrade migrations');
            RegistryProgress::log('Module migration pending lookup started: ' . $moduleName);
            $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
            if (empty($pendingMigrations)) {
                RegistryProgress::log('Module migration pending lookup empty: ' . $moduleName);
                return;
            }

            if ($oldVersion === $newVersion) {
                RegistryProgress::log('Module migration skipped unchanged version: ' . $moduleName);
                return;
            }

            $this->printing->info(__("模块 %{1} 升级 %{2} -> %{3}，执行 %{4} 个迁移",
                [$moduleName, $oldVersion, $newVersion, count($pendingMigrations)]));

            $success = 0;
            $failed  = 0;
            $total = count($pendingMigrations);
            $index = 0;
            foreach ($pendingMigrations as $migration) {
                $index++;
                RegistryProgress::module('Module migration execute', $index, $total, $moduleName, (string)($migration['filename'] ?? ''));
                $result = $this->migrationService->upgradeMigration($moduleName, $migration['file']);
                $result ? $success++ : $failed++;
            }
            $compaction = ObjectManager::relieveMemoryPressure(false);
            $cycles = function_exists('gc_collect_cycles') ? gc_collect_cycles() : 0;
            RegistryProgress::log(sprintf(
                'Module migration observer finished: %s success=%d failed=%d memory_stores=%d metadata_entries=%d gc_cycles=%d',
                $moduleName,
                $success,
                $failed,
                (int)($compaction['memory_store_clears'] ?? 0),
                (int)($compaction['metadata_entries_cleared'] ?? 0),
                (int)$cycles
            ));

            $this->printing->info(__("迁移完成: 成功 %{1}，失败 %{2}", [$success, $failed]));
        } catch (\Exception $e) {
            RegistryProgress::log('Module migration observer exception: ' . $moduleName . ' ' . $e->getMessage());
            $this->printing->error(__("模块升级迁移失败: %{1}", $e->getMessage()));
        }
    }
}
