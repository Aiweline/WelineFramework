<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\AdAttributionLinkerClientScript;
use Weline\Framework\Http\Fpc\FpcDevAutoPierceClientScript;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\TemplateRenderRouteTrace;
use Weline\Theme\Service\LayoutEntity\RequiredDefaultInjectionRuntimeSafetyNet;

/**
 * Storefront hard-cut finalize shared by LayoutSlotRenderer and homepage layout bag.
 *
 * Homepage bag must remember post-finalize HTML so Bag HIT does not re-SSR
 * required default_injections via safety_net.
 */
final class StorefrontLayoutHtmlFinalizer
{
    public function __construct(
        private readonly SlotRendererService $slotRenderer,
    ) {
    }

    /**
     * @param array{skip_dev_diagnostics?:bool} $options
     */
    public function finalizeHardCut(string $html, string $area = 'frontend', array $options = []): string
    {
        if ($area !== 'frontend' || $html === '') {
            return $html;
        }
        $__t0 = \microtime(true);

        try {
            $themeId = $this->resolveFrontendThemeId();
            if ($themeId > 0) {
                TemplateRenderRouteTrace::mark('layout_finalizer', 'fill_empty_nested_chrome');
                $html = $this->slotRenderer->fillEmptyNestedChromeSlots($html, $themeId, 'frontend');
            }
        } catch (\Throwable) {
        }
        $__t1 = \microtime(true);

        $html = SlotBoundaryMarkers::strip($html);
        $__t2 = \microtime(true);

        try {
            /** @var RequiredDefaultInjectionRuntimeSafetyNet $requiredNet */
            $requiredNet = ObjectManager::getInstance(RequiredDefaultInjectionRuntimeSafetyNet::class);
            TemplateRenderRouteTrace::mark('layout_finalizer', 'safety_net');
            $html = $requiredNet->ensure($html);
        } catch (\Throwable) {
        }
        $__t3 = \microtime(true);

        $html = ThemePdpBudgetPhases::stampSectionAttributes($html);
        $__t4 = \microtime(true);

        if (empty($options['skip_dev_diagnostics'])) {
            try {
                TemplateRenderRouteTrace::mark('layout_finalizer', 'append_dev_diagnostics');
                $html = $this->slotRenderer->appendDevStorefrontDiagnostics($html);
            } catch (\Throwable) {
            }
        }
        $__t5 = \microtime(true);

        try {
            TemplateRenderRouteTrace::mark('layout_finalizer', 'fpc_dev_auto_pierce_client');
            $html = FpcDevAutoPierceClientScript::injectIntoHtml($html);
        } catch (\Throwable) {
        }
        $__t6 = \microtime(true);

        try {
            TemplateRenderRouteTrace::mark('layout_finalizer', 'ad_attribution_linker_client');
            $html = AdAttributionLinkerClientScript::injectIntoHtml($html);
        } catch (\Throwable) {
        }
        $__t7 = \microtime(true);

        \Weline\Framework\Runtime\TraceGate::measurePhase('hcf.fill_nested', fn() => ($__t1 - $__t0) * 1000);
        \Weline\Framework\Runtime\TraceGate::measurePhase('hcf.strip', fn() => ($__t2 - $__t1) * 1000);
        \Weline\Framework\Runtime\TraceGate::measurePhase('hcf.safety_net', fn() => ($__t3 - $__t2) * 1000);
        \Weline\Framework\Runtime\TraceGate::measurePhase('hcf.stamp', fn() => ($__t4 - $__t3) * 1000);
        \Weline\Framework\Runtime\TraceGate::measurePhase('hcf.devdiag', fn() => ($__t5 - $__t4) * 1000);
        \Weline\Framework\Runtime\TraceGate::measurePhase('hcf.fpcpierce', fn() => ($__t6 - $__t5) * 1000);
        \Weline\Framework\Runtime\TraceGate::measurePhase('hcf.adattr', fn() => ($__t7 - $__t6) * 1000);

        return $html;
    }

    private function resolveFrontendThemeId(): int
    {
        try {
            /** @var ThemeContextService $ctx */
            $ctx = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $ctx->resolveTheme('frontend', null, false);
            if (\is_object($theme) && \method_exists($theme, 'getId')) {
                return max(0, (int)$theme->getId());
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
