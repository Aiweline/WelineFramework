<?php
declare(strict_types=1);

namespace Weline\ModuleManager\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\ModuleManager\Service\ModuleUninstallOrchestrator;

/**
 * module:remove 卸载前：MDP 文件包 + 表重命名备份。
 */
class RemoveBeforeBackup implements ObserverInterface
{
    public function __construct(
        private readonly ModuleUninstallOrchestrator $uninstallOrchestrator
    ) {
    }

    public function execute(Event &$event): void
    {
        $moduleName = (string) $event->getData('module_name');
        if ($moduleName === '') {
            return;
        }
        $result = $this->uninstallOrchestrator->runBeforeRemove($moduleName);
        $event->setData('result', [
            'success' => $result['success'],
            'message' => $result['message'] ?? '',
            'backup_timestamp' => $result['backup_timestamp'] ?? '',
            'mdp_path' => $result['mdp_path'] ?? '',
            'mdp_row_count' => $result['mdp_row_count'] ?? 0,
        ]);
    }
}
