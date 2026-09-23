<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityBakeCoordinator;
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
            $widgets = $this->widgetsFromEvent($event);
            $changes = $event->getData('injection_structure_changes');
            $changes = is_array($changes) ? $changes : [];
            if ($widgets === [] && $changes === []) {
                return;
            }

            if ($widgets !== []) {
                $this->defaultInjectionService->applyInstalledWidgetsForAvailableThemes($widgets);
            }
            if ($changes === []) {
                return;
            }
            ObjectManager::getInstance(\Weline\Widget\Service\DefaultInjectionPlanRepository::class)->clearMemo();
            $this->slotRendererService->clearCache();
            // Plugin/registry install with JSON default_injections: always re-solidify
            // involved published shells + drop chrome.rendered across ALL themes so the
            // next hit dynamically re-bakes required widgets (minus user_deleted only).
            // Do not gate on applied>0 — catalog may already have decisions while
            // durable snapshots are still incomplete.
            try {
                ObjectManager::getInstance(ThemeLayoutEntityBakeCoordinator::class)
                    ->rebakeAfterInjectionCollect(null, $changes);
            } catch (\Throwable $bakeError) {
                w_log_error(
                    '注入收集后固化整壳重生失败: ' . $bakeError->getMessage(),
                    [],
                    'ThemeWidgetDefaultInjection',
                );
            }
        } catch (\Throwable $e) {
            w_log_error('应用部件默认注入失败: ' . $e->getMessage(), [], 'ThemeWidgetDefaultInjection');
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function widgetsFromEvent(Event $event): array
    {
        $widgets = $event->getData('widgets');
        if (!is_array($widgets)) {
            return [];
        }

        $result = [];
        foreach ($widgets as $widget) {
            if (!is_array($widget)) {
                continue;
            }
            $module = trim((string)($widget['module'] ?? $widget['widget_module'] ?? ''));
            $type = trim((string)($widget['type'] ?? $widget['widget_type'] ?? ''));
            $code = trim((string)($widget['code'] ?? $widget['widget_code'] ?? ''));
            if ($module === '' || $type === '' || $code === '') {
                continue;
            }
            $area = trim((string)($widget['area'] ?? $widget['widget_area'] ?? ''));
            $key = implode('|', [$area, $module, $type, $code]);
            $result[$key] = [
                'area' => $area,
                'widget_area' => $area,
                'module' => $module,
                'widget_module' => $module,
                'type' => $type,
                'widget_type' => $type,
                'code' => $code,
                'widget_code' => $code,
            ];
        }

        return array_values($result);
    }
}
