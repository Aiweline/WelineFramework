<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\PreviewContextService;
use Weline\Theme\Service\SlotRendererService;
use Weline\Theme\Service\WidgetDefaultInjectionService;

/**
 * Apply Dashboard default_injections that declare default_view for a ready layout identity.
 */
class ApplyDashboardViewDefaultInjections implements ObserverInterface
{
    public function __construct(
        private readonly WidgetDefaultInjectionService $defaultInjectionService,
        private readonly SlotRendererService $slotRendererService,
    ) {
    }

    public function execute(Event &$event): void
    {
        try {
            $themeId = (int)($event->getData('theme_id') ?? 0);
            $viewCode = trim((string)($event->getData('view_code') ?? ''));
            $pageType = trim((string)($event->getData('page_type') ?? ''));
            if ($themeId <= 0 || $viewCode === '' || ($pageType !== '' && $pageType !== 'dashboard')) {
                return;
            }

            $identity = $event->getData('identity');
            if (!is_array($identity)) {
                $identity = $event->getData('layout_identity');
            }
            if (!is_array($identity)) {
                return;
            }

            $componentArea = trim((string)($event->getData('component_area') ?? PreviewContextService::AREA_BACKEND));
            if ($componentArea === '') {
                $componentArea = PreviewContextService::AREA_BACKEND;
            }

            $applied = $this->defaultInjectionService->applyDashboardViewDefaultInjections(
                $themeId,
                $viewCode,
                $identity,
                $componentArea
            );
            if ($applied > 0) {
                $this->slotRendererService->clearCache();
            }
        } catch (\Throwable $e) {
            w_log_error('应用 Dashboard 视图默认注入失败: ' . $e->getMessage(), [], 'ThemeWidgetDefaultInjection');
        }
    }
}
