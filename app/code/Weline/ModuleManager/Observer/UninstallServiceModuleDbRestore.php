<?php
declare(strict_types=1);

namespace Weline\ModuleManager\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\ModuleManager\Service\ModuleBackupService;

class UninstallServiceModuleDbRestore implements ObserverInterface
{
    public function __construct(
        private ModuleBackupService $moduleBackupService
    ) {
    }

    public function execute(Event &$event): void
    {
        $moduleName = (string)$event->getData('module_name');
        if ($moduleName === '') {
            return;
        }
        $backupTimestamp = $event->getData('backup_timestamp');
        if (!is_string($backupTimestamp) || $backupTimestamp === '') {
            $backupTimestamp = null;
        }
        $event->setData('result', $this->moduleBackupService->restoreModuleTables($moduleName, $backupTimestamp));
    }
}
