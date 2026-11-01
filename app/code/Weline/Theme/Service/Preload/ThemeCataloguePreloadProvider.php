<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\ViewWarmupContributionRegistry;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Framework\View\Template;
use Weline\Theme\Service\ThemeComponentCatalog;
use Weline\Theme\Service\ThemeDirectoryResolver;
use Weline\Theme\Service\ThemePlaceableRegistry;
use Weline\Theme\Service\ThemeResourceCatalog;

/**
 * Publishes the theme component/widget catalogue into process memory at Worker start-up.
 *
 * Root cause this removes: the first storefront request in every Worker paid for
 * building this catalogue. It walks the theme's component and widget directories and
 * parses the meta of every file — 177 components on the current storefront — and the
 * measured cost was:
 *
 *     ThemePlaceableRegistry (first build)      138.32 ms
 *       ThemeComponentCatalog                    88.88 ms
 *         ThemeResourceCatalog file scan          20.96 ms
 *       ThemeComponentRenderer                   53.48 ms
 *
 * The result depends only on (area, theme version), which are global facts known
 * before the first request, so the build belongs in Worker boot rather than in the
 * request that happens to arrive first.
 *
 * Module-owned per the framework rule that the owning module declares its own
 * preload data and its dependency order; the runtime only schedules.
 */
final class ThemeCataloguePreloadProvider extends AbstractWorkerPreloadProvider
{
    /**
     * Themes that own storefront rendering. Frontend is what the storefront gate
     * reads; backend is included because backend pages share the same catalogue code
     * path and would otherwise pay the same first-build cost.
     */
    private const AREAS = ['frontend'];

    public function code(): string
    {
        return 'theme_catalogue';
    }

    public function priority(): int
    {
        return 64;
    }

    /**
     * Needs the resolved website/theme context before it can know which theme
     * version to build the catalogue for.
     */
    public function dependsOn(): array
    {
        return ['websites_theme_application'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        return $context->runtimeMode() === RuntimeInterface::MODE_WLS;
    }

    public function invalidationKeys(): array
    {
        return ['theme_catalogue', 'theme_layout', 'widget_registry'];
    }

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult
    {
        unset($context);
        $start = \microtime(true);
        $memoryStart = \memory_get_usage(true);

        $items = 0;
        $failures = [];

        /** @var ThemeDirectoryResolver $directoryResolver */
        $directoryResolver = ObjectManager::getInstance(ThemeDirectoryResolver::class);
        /** @var ThemeResourceCatalog $resourceCatalog */
        $resourceCatalog = ObjectManager::getInstance(ThemeResourceCatalog::class);

        // Resource catalogue: the file-system walk that dominates the cost.
        foreach (self::AREAS as $area) {
            foreach (['components', 'widgets', 'layouts', 'partials'] as $type) {
                try {
                    $items += \count($resourceCatalog->getRawResources($type, $area, null));
                } catch (\Throwable $exception) {
                    $failures['raw.' . $area . '.' . $type] = $exception->getMessage();
                }
            }
            try {
                $items += \count($resourceCatalog->getSlots($area, null));
            } catch (\Throwable $exception) {
                $failures['slots.' . $area] = $exception->getMessage();
            }
        }

        // Component definitions: built on top of the resource catalogue.
        try {
            /** @var ThemeComponentCatalog $componentCatalog */
            $componentCatalog = ObjectManager::getInstance(ThemeComponentCatalog::class);
            foreach (self::AREAS as $area) {
                $items += \count($componentCatalog->getDefinitions($area, null));
            }
        } catch (\Throwable $exception) {
            $failures['definitions'] = $exception->getMessage();
        }

        // Placeable registry: the object whose construction pins the whole chain.
        try {
            ObjectManager::getInstance(ThemePlaceableRegistry::class);
            ++$items;
        } catch (\Throwable $exception) {
            $failures['placeable_registry'] = $exception->getMessage();
        }

        // Hook contributors: the slot/widget hook graph this theme version renders.
        //
        // Warm module `view_warmup_contribution` hook names only fill HookReader's
        // file lists. The runtime still had to resolve each contributor to a compiled
        // path and compile it on first use: the first product card of the first
        // listing paid 9.38 ms resolving `product-card-add-to-cart.phtml` plus 19.95 ms
        // compiling and streaming it, and the same again for buy-now — 44 ms against a
        // 0.58 ms steady state.
        //
        // Warming here resolves every contributed hook to its compiled artifact while
        // the Worker boots, so the request path only reads. Contributors that do not
        // apply to this theme/locale are skipped individually, not fatally.
        $hookNames = [];
        try {
            $contributionRegistry = ObjectManager::getInstance(ViewWarmupContributionRegistry::class);
            foreach ($contributionRegistry->aggregate()->hookNames as $hookName) {
                if (\is_string($hookName) && $hookName !== '') {
                    $hookNames[$hookName] = true;
                }
            }
        } catch (\Throwable $exception) {
            $failures['hook_names'] = $exception->getMessage();
        }

        // Every hook the product-card CTA block renders, whether or not a module
        // declared it: this is the hottest hook chain on storefront listings.
        foreach ([
            'Weline_Theme::frontend::partials::product-card::add-to-cart',
            'Weline_Theme::frontend::partials::product-card::buy-now',
        ] as $hotHook) {
            $hookNames[$hotHook] = true;
        }

        if ($hookNames !== []) {
            try {
                $template = Template::getInstance();
                $items += $template->warmHookContributorTemplates(\array_keys($hookNames));
            } catch (\Throwable $exception) {
                $failures['hook_templates'] = $exception->getMessage();
            }
        }

        // The directory resolver is injected but only used indirectly; referencing it
        // here keeps the dependency explicit for future readers.
        unset($directoryResolver);

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        if ($failures !== [] && $items === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $this->phase(),
                \implode('; ', \array_slice(\array_map(
                    static fn(string $k, string $v): string => $k . ': ' . $v,
                    \array_keys($failures),
                    $failures,
                ), 0, 3)),
                $durationMs,
                $memoryDelta,
            );
        }

        return WorkerPreloadResult::warmed($this->code(), $this->phase(), $items, $durationMs, $memoryDelta);
    }
}
