<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

class ApplyWidgetDefaultInjections implements ObserverInterface
{
    public function __construct(
        private readonly WidgetDefaultInjectionService $defaultInjectionService,
        private readonly SlotRendererService $slotRendererService,
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            $source = (string)$event->getData('source');
            if (!in_array($source, ['module_install_after', 'module_upgrade_after'], true)) {
                return;
            }

            $modules = $this->modulesFromEvent($event);
            if ($modules === []) {
                return;
            }

            $applied = $this->defaultInjectionService->applyAllForAvailableThemes($modules);
            if ($applied > 0) {
                $this->slotRendererService->clearCache();
            }
        } catch (\Throwable $e) {
            w_log_error('应用部件默认注入失败: ' . $e->getMessage(), [], 'ThemeWidgetDefaultInjection');
        }
    }

    /**
     * @return list<string>
     */
    private function modulesFromEvent(Event $event): array
    {
        $modules = $event->getData('modules');
        if (is_string($modules)) {
            $modules = preg_split('/[,\s]+/', $modules) ?: [];
        }
        if (!is_array($modules)) {
            $modules = [];
        }

        $module = $event->getData('module');
        if (is_string($module) && trim($module) !== '') {
            $modules[] = $module;
        }

        $result = [];
        foreach ($modules as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $result[$item] = $item;
            }
        }

        return array_values($result);
    }
}
