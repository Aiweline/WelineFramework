<?php

declare(strict_types=1);

namespace Weline\Product\Service\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\StorefrontWebsiteDataProviderInterface;
use Weline\Product\Service\StorefrontCategoryTreeIndex;

/**
 * Warms the storefront category tree at Worker start-up.
 *
 * CLI 冷调用实测 47ms（listAll 12.1ms + 多语言呈现 10.1ms）；热调用 6–7ms。
 * 它挂在每个店铺页的导航/筛选上，放在 Worker 启动阶段建好，让第一批请求不用各付一次。
 *
 * Module-owned per the framework rule that the owning module declares its own
 * preload data and its dependency order; the runtime only schedules.
 */
final class CategoryTreeWarmupPreloadProvider extends AbstractWorkerPreloadProvider
{
    public function code(): string
    {
        return 'storefront_category_tree';
    }

    public function priority(): int
    {
        return 70;
    }

    public function dependsOn(): array
    {
        return ['critical_runtime_classes'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return \class_exists(StorefrontCategoryTreeIndex::class);
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);
        $detail = [];

        $ok = $this->step('category_tree_warm', static function (): void {
            $tree = ObjectManager::getInstance(StorefrontCategoryTreeIndex::class);
            $locales = [''];
            try {
                $provider = ObjectManager::getInstance(StorefrontWebsiteDataProviderInterface::class);
                $codes = $provider->languageCodesFor(0);
                foreach ((array)$codes as $code) {
                    $code = \trim((string)$code);
                    if ($code !== '') {
                        $locales[] = $code;
                    }
                }
            } catch (\Throwable) {
                // 只预热默认语种
            }
            foreach (\array_values(\array_unique($locales)) as $locale) {
                $tree->forWebsite(0, $locale);
            }
        }, $detail);

        $items = $ok ? 1 : 0;
        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        if ($items === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $context->phase(),
                'all_steps_failed:category_tree_warm',
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
            // A warm-up step must never break worker READY; the request path can
            // still load the class lazily as before.
            $detail[$name] = false;

            return false;
        }
    }
}
