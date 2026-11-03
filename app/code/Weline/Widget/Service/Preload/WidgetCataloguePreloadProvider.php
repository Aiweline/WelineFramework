<?php

declare(strict_types=1);

namespace Weline\Widget\Service\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Widget\Service\WidgetData;

/**
 * Warms the widget catalogue at Worker start-up.
 *
 * Slot rendering resolves the widget catalogue on first use; measured cold
 * 136 ms vs 5 ms warm (~27x).
 *
 * Module-owned per the framework rule that the owning module declares its own
 * preload data and its dependency order; the runtime only schedules.
 */
final class WidgetCataloguePreloadProvider extends AbstractWorkerPreloadProvider
{
    public function code(): string
    {
        return 'widgets';
    }

    public function priority(): int
    {
        return 69;
    }

    public function dependsOn(): array
    {
        return ['critical_runtime_classes'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return \class_exists(WidgetData::class);
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $detail = [];

        $ok = $this->step('widgets', static function (): void {
            ObjectManager::getInstance(WidgetData::class)->getAllWidgets();
        }, $detail);

        $items = $ok ? 1 : 0;
        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        if ($items === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $context->phase(),
                'all_steps_failed:widgets',
                $durationMs,
                $memoryDelta
            );
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $context->phase(),
            $items,
            $durationMs,
            $memoryDelta,
            $detail
        );
    }

    private function step(string $name, callable $loader, array &$detail): bool
    {
        try {
            $loader();
            $detail[$name] = true;

            return true;
        } catch (\Throwable) {
            $detail[$name] = false;

            return false;
        }
    }
}
