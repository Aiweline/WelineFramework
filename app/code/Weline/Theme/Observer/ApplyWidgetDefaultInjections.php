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
            $widgets = $this->widgetsFromEvent($event);
            if ($widgets === []) {
                return;
            }

            $applied = $this->defaultInjectionService->applyInstalledWidgetsForAvailableThemes($widgets);
            if ($applied > 0) {
                $this->slotRendererService->clearCache();
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
