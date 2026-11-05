<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Preload;

use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\Preload\AbstractWorkerPreloadProvider;
use Weline\Framework\Runtime\Preload\WorkerPreloadContext;
use Weline\Framework\Runtime\Preload\WorkerPreloadResult;
use Weline\Framework\Runtime\RuntimeInterface;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionRuntimeSafetyNet;

/**
 * Publishes the default-injection render inputs into process memory before READY.
 *
 * Root cause this removes: `RequiredDefaultInjectionRuntimeSafetyNet::ensure()`
 * runs on every storefront document and reads three global publication facts —
 * the published declaration plans, the published theme version id, and the
 * per-version uninstall rows. Measured in a fresh CLI process:
 *
 *     DefaultInjectionPlanRepository::listDeclarations('frontend')  3.28 ms
 *     ThemePublishedVersionRuntimeResolver::resolve…(theme, page)  10.42 ms (first)
 *                                                                  0.56 ms (repeat)
 *     ledger query `WHERE theme_id AND page_type AND source`        0.64-1.84 ms
 *
 * Every one of those was paid inside the FIRST render of each page type in each
 * Worker. They depend only on (theme, page type), never on the request, so per
 * the project rule that global data belongs in process memory before READY they
 * are warmed here instead.
 *
 * Invalidation: the warm entries live in the safety net, which drops them from
 * {@see \Weline\Theme\Integration\Framework\ThemeRuntimeCacheReset::clearRuntimeCaches()}
 * (theme publish / layout change) and from the ledger models' `save_after` hooks.
 *
 * Registered by the Theme module itself:
 *   'worker_preload_provider.Weline_Theme_DefaultInjection' => this class
 */
final class DefaultInjectionLedgerPreloadProvider extends AbstractWorkerPreloadProvider
{
    /** Control-plane / non-serving roles never serve storefront requests. */
    private const EXCLUDED_ROLES = ['dispatcher', 'master', 'session', 'memory', 'supervisor', 'maintenance'];

    /**
     * Page types the storefront render actually resolves.
     *
     * Bounded on purpose: `resolvePageType()` maps a request onto one of these,
     * so warming them covers the first render of every storefront route without
     * walking the whole ThemeLayout page-type catalogue.
     */
    private const PAGE_TYPES = [
        ThemeLayout::PAGE_TYPE_HOME,
        ThemeLayout::PAGE_TYPE_PRODUCT,
        ThemeLayout::PAGE_TYPE_PRODUCT_LIST,
        ThemeLayout::PAGE_TYPE_CATEGORY,
        ThemeLayout::PAGE_TYPE_CART,
        ThemeLayout::PAGE_TYPE_CHECKOUT,
        ThemeLayout::PAGE_TYPE_SEARCH,
        ThemeLayout::PAGE_TYPE_BLOG,
        ThemeLayout::PAGE_TYPE_CMS,
        ThemeLayout::PAGE_TYPE_ACCOUNT,
        ThemeLayout::PAGE_TYPE_FAQ,
        ThemeLayout::PAGE_TYPE_ABOUT,
        ThemeLayout::PAGE_TYPE_CONTACT,
        ThemeLayout::PAGE_TYPE_DEFAULT,
    ];

    public function code(): string
    {
        return 'default_injection_ledger';
    }

    /**
     * After the theme catalogue and the site → theme application context: the
     * applied theme id and its published version both come from those providers.
     */
    public function priority(): int
    {
        return 70;
    }

    public function dependsOn(): array
    {
        return ['theme_catalogue', 'websites_theme_application', 'website_directory'];
    }

    public function isEnabled(WorkerPreloadContext $context): bool
    {
        if ($context->runtimeMode() !== RuntimeInterface::MODE_WLS) {
            return false;
        }

        return !\in_array(\strtolower(\trim($context->role())), self::EXCLUDED_ROLES, true);
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

        try {
            /** @var RequiredDefaultInjectionRuntimeSafetyNet $safetyNet */
            $safetyNet = ObjectManager::getInstance(RequiredDefaultInjectionRuntimeSafetyNet::class);
            $warmed = $safetyNet->warmRenderInputs(self::PAGE_TYPES);
        } catch (\Throwable $exception) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $this->phase(),
                $exception->getMessage(),
                \round((\microtime(true) - $start) * 1000, 2),
                \memory_get_usage(true) - $memoryStart,
            );
        }

        $durationMs = \round((\microtime(true) - $start) * 1000, 2);
        $memoryDelta = \memory_get_usage(true) - $memoryStart;

        if ($warmed['declarations'] === 0) {
            return WorkerPreloadResult::failed(
                $this->code(),
                $this->phase(),
                'no published declaration plan (theme_id=' . $warmed['theme_id'] . ')',
                $durationMs,
                $memoryDelta,
            );
        }

        return WorkerPreloadResult::warmed(
            $this->code(),
            $this->phase(),
            $warmed['declarations'] + $warmed['page_types'],
            $durationMs,
            $memoryDelta,
            $warmed,
        );
    }
}
