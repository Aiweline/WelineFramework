<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Framework\Event\EventsManager;

class WidgetRegistryRefreshService
{
    public function __construct(
        private readonly WidgetRegistry $widgetRegistry,
        private readonly ParamSchemaRegistry $paramSchemaRegistry,
        private readonly EventsManager $eventsManager,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function refresh(string $source = 'runtime'): array
    {
        $report = $this->widgetRegistry->refreshWithReport(['source' => $source]);
        $schemaOk = $this->paramSchemaRegistry->refresh();
        $report['param_schema_success'] = $schemaOk;

        $widgets = $report['created_default_injection_widgets'] ?? [];
        if (($report['success'] ?? false) && is_array($widgets) && $widgets !== []) {
            $eventData = [
                'source' => $source,
                'widgets' => array_values($widgets),
                'registry_report' => $report,
            ];
            $this->eventsManager->dispatch('Weline_Widget::widget_install_after', $eventData);
            $report['widget_install_event_dispatched'] = true;
        } else {
            $report['widget_install_event_dispatched'] = false;
        }

        return $report;
    }
}
