<?php
declare(strict_types=1);

namespace Weline\ModuleManager\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\ModuleManager\Service\ModuleBackupService;

class RemoveBeforeBackup implements ObserverInterface
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
        $event->setData('result', $this->moduleBackupService->backupModuleTables($moduleName));
    }
}
