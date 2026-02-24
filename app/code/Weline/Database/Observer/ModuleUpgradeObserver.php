<?php

declare(strict_types=1);

namespace Weline\Database\Observer;

use Weline\Database\Service\MigrationService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;

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
            $pendingMigrations = $this->migrationService->getPendingMigrations($moduleName);
            if (empty($pendingMigrations)) {
                return;
            }

            if ($oldVersion === $newVersion) {
                return;
            }

            $this->printing->info(__("模块 %{1} 升级 %{2} -> %{3}，执行 %{4} 个迁移",
                [$moduleName, $oldVersion, $newVersion, count($pendingMigrations)]));

            $success = 0;
            $failed  = 0;
            foreach ($pendingMigrations as $migration) {
                $result = $this->migrationService->upgradeMigration($moduleName, $migration['file']);
                $result ? $success++ : $failed++;
            }

            $this->printing->info(__("迁移完成: 成功 %{1}，失败 %{2}", [$success, $failed]));
        } catch (\Exception $e) {
            $this->printing->error(__("模块升级迁移失败: %{1}", $e->getMessage()));
        }
    }
}
