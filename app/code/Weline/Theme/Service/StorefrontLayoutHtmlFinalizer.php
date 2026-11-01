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

        try {
            $themeId = $this->resolveFrontendThemeId();
            if ($themeId > 0) {
                TemplateRenderRouteTrace::mark('layout_finalizer', 'fill_empty_nested_chrome');
                $html = $this->slotRenderer->fillEmptyNestedChromeSlots($html, $themeId, 'frontend');
            }
        } catch (\Throwable) {
        }

        $html = SlotBoundaryMarkers::strip($html);

        try {
            /** @var RequiredDefaultInjectionRuntimeSafetyNet $requiredNet */
            $requiredNet = ObjectManager::getInstance(RequiredDefaultInjectionRuntimeSafetyNet::class);
            TemplateRenderRouteTrace::mark('layout_finalizer', 'safety_net');
            $html = $requiredNet->ensure($html);
        } catch (\Throwable) {
        }

        $html = ThemePdpBudgetPhases::stampSectionAttributes($html);

        if (empty($options['skip_dev_diagnostics'])) {
            try {
                TemplateRenderRouteTrace::mark('layout_finalizer', 'append_dev_diagnostics');
                $html = $this->slotRenderer->appendDevStorefrontDiagnostics($html);
            } catch (\Throwable) {
            }
        }

        try {
            TemplateRenderRouteTrace::mark('layout_finalizer', 'fpc_dev_auto_pierce_client');
            $html = FpcDevAutoPierceClientScript::injectIntoHtml($html);
        } catch (\Throwable) {
        }

        try {
            TemplateRenderRouteTrace::mark('layout_finalizer', 'ad_attribution_linker_client');
            $html = AdAttributionLinkerClientScript::injectIntoHtml($html);
        } catch (\Throwable) {
        }

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
