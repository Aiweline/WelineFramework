<?php

declare(strict_types=1);

namespace Weline\ModuleManager\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\ModuleManager\Model\Module;

final class ModuleRollbackAuditObserver implements ObserverInterface
{
    public function __construct(private readonly Module $moduleModel)
    {
    }

    public function execute(Event &$event): void
    {
        $moduleName = trim((string)$event->getData('module_name'));
        if ($moduleName === '') {
            return;
        }
        $module = clone $this->moduleModel;
        $module->load(Module::schema_fields_NAME, $moduleName);
        $blockers = (array)$event->getData('blockers');
        if (!$module->getId()) {
            $blockers[] = __('模块 %{1} 缺少 ModuleManager 查询投影', $moduleName);
        } else {
            $projectionVersion = trim((string)$module->getData(Module::schema_fields_VERSION));
            $codeVersion = trim((string)$event->getData('code_version'));
            if ($projectionVersion !== $codeVersion) {
                $blockers[] = __(
                    '模块 %{1} 的 ModuleManager 投影版本 %{2} 与代码版本 %{3} 不一致',
                    [$moduleName, $projectionVersion, $codeVersion]
                );
            }
        }
        $event->setData('blockers', array_values(array_unique($blockers)));
    }
}
