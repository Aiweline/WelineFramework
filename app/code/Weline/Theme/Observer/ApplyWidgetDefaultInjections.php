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
            // 注入声明变化时，按变更前后的目标并集为所有主题重生成派生 PHTML。
            // 生成后失效对应 owner 的展示缓存；访问时不动态补播种或生成 HTML 快照。
            // 已有安装决定不代表产物完整，不能以新增安装数量为零跳过重固化。
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
