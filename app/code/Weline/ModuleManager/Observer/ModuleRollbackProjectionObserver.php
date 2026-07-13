<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\ModuleManager\Model\Module;

final class ModuleRollbackProjectionObserver implements ObserverInterface
{
    public function __construct(private readonly Module $moduleModel)
    {
    }

    public function execute(Event &$event): void
    {
        $eventName = $event->getName();
        foreach ((array)$event->getData('modules') as $item) {
            if (!is_array($item)) {
                continue;
            }
            $moduleName = trim((string)($item['module_name'] ?? ''));
            if ($moduleName === '') {
                continue;
            }
            $module = clone $this->moduleModel;
            $module->load(Module::schema_fields_NAME, $moduleName);
            if (!$module->getId()) {
                continue;
            }
            $recovered = $eventName === 'Weline_Database_ModuleRollback::failed_recovered';
            $version = $recovered ? (string)($item['from_version'] ?? '') : (string)($item['to_version'] ?? '');
            $lastVersion = $recovered ? (string)($item['to_version'] ?? '') : (string)($item['from_version'] ?? '');
            $module->setData([
                Module::schema_fields_LAST_VERSION => $lastVersion,
                Module::schema_fields_VERSION => $version,
                Module::schema_fields_CODE_VERSION => $version,
                Module::schema_fields_DATABASE_VERSION => $version,
                Module::schema_fields_SCHEMA_STATUS => 'consistent',
                Module::schema_fields_DRIFT_WARNING => '',
            ])->save();
        }
    }
}
