<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller;
use Weline\Theme\Service\ThemeContextService;

/**
 * Heal empty Theme chrome on SSR-slim storefront pages that call template()/fetchHtml
 * (skip LayoutSlotRenderer) but still declare showHeader/showFooter Partials.
 *
 * Call site: FrontendController::template() (soft-dep). Business controllers must not
 * invoke this healer themselves.
 *
 * W4: does NOT splice chrome.rendered locale snapshots. Chrome comes from relationship
 * chrome.phtml include + hydrate + HotCache/FPC / solidified shell. Required page slots
 * (e.g. checkout-shipping-address): Overlay when no solidified page_html; presence gate
 * aligns with Overlay (data-wslot / data-slot-id / markers).
 */
final class StorefrontSsrChromeHealer
{
    public function ensure(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        // W3：已选固化控制器模板 → 完全跳过 Overlay/fill/Healer。
        if (ThemeLayoutEntityPublishedSlotHost::solidifiedControllerTemplateSelected()) {
            return SlotBoundaryMarkers::strip($html);
        }

        // 必装永远存在（2026-09-22 架构裁决 + spec/required-default-always-present.md §4）：
        // SSR-slim 页面走 template()/fetchHtml，跳过 LayoutSlotRenderer，因此也不会跑
        // required 默认注入。结果：声明为 required 的**页面槽**会以空壳交付 ——
        // 典型即结账页 `checkout-shipping-address`（契约见
        // Weline_Checkout/test/Unit/View/CheckoutShippingAddressSlotContractTest：
        // 「只走 required injection，禁止 soft fallback 直渲」）。
        //
        // 2026-09-26 用户纠偏（严格档「有固化就完全不注」）：
        // 本请求已装载页面固化产物 ⇒ 运行时不得再注入 / 查部件声明，固化模板直接交付；
        // 缺槽位 = 固化缺陷，须重固化（rebake）修复。仅**无**固化产物时才补跑 required overlay。
        $solidified = ThemeLayoutEntityPublishedSlotHost::publishedSolidifiedArtifactLoaded();
        if (!$solidified) {
            $html = $this->fillRequiredPageDefaults($html);
            $this->tryDynamicSolidifyMissingPage($html);
        }

        // W4: empty chrome shells are not healed from chrome.rendered disk snapshots.
        // Safety-net fill for incomplete shells still runs when gate says so — via
        // healPublishedPlaceholderShell (fill / Overlay), never locale rendered HTML.
        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return SlotBoundaryMarkers::strip($html);
        }

        try {
            $themeId = $this->resolveFrontendThemeId();
            if ($themeId < 1) {
                return SlotBoundaryMarkers::strip($html);
            }
            $pageType = $this->resolvePageType();
            if ($pageType === '') {
                $pageType = ThemeLayout::PAGE_TYPE_HOME;
            }
            /** @var ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class);
            $html = $filler->healPublishedPlaceholderShell($html, $themeId, $pageType, 'frontend');
        } catch (\Throwable) {
            // soft
        }

        return SlotBoundaryMarkers::strip($html);
    }

    /**
     * 补跑 required 默认注入（页面槽，例如 checkout-shipping-address）。
     *
     * Reactive Taglib 壳只有 data-wslot；与 Overlay 门闩对齐后再注入。
     * 失败软降级 —— 注入问题不得让整页 500。
     */
    private function fillRequiredPageDefaults(string $html): string
    {
        if (!SlotBoundaryMarkers::htmlHasInjectableSlotDestinations($html)) {
            return $html;
        }

        $themeId = $this->resolveFrontendThemeId();
        if ($themeId < 1) {
            return $html;
        }
        $pageType = $this->resolvePageType();
        if ($pageType === '') {
            return $html;
        }

        try {
            /** @var ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class);

            return $filler->fillRequiredDefaultsOnShell(
                $html,
                $themeId,
                $pageType,
                ThemeLayout::STATUS_PUBLISHED,
            );
        } catch (\Throwable) {
            return $html;
        }
    }

    /**
     * SSR-slim skips LayoutSlot → never hits fill()-path dynamicSolidify.
     * Soft-trigger once when page bake is missing so next request can strict-deliver.
     */
    private function tryDynamicSolidifyMissingPage(string $html): void
    {
        if (!SlotBoundaryMarkers::htmlHasInjectableSlotDestinations($html)) {
            return;
        }
        $themeId = $this->resolveFrontendThemeId();
        if ($themeId < 1) {
            return;
        }
        $pageType = $this->resolvePageType();
        if ($pageType === '') {
            return;
        }
        try {
            /** @var ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class);
            $filler->solidifyMissingPublishedPageIfNeeded($themeId, $pageType, 'frontend');
        } catch (\Throwable) {
            // soft — request HTML already Overlay-healed above
        }
    }

    /**
     * 页面类型来自控制器写入的 `layout_type`（结账页为 `checkout`）。
     * 解析不到时返回空串，交由调用方跳过注入（不做猜测）。
     */
    private function resolvePageType(): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);
            $layoutType = \trim((string)$request->getParam('layout_type', ''));
            if ($layoutType === '') {
                return '';
            }
            /** @var ThemePageTypeResolver $resolver */
            $resolver = ObjectManager::getInstance(ThemePageTypeResolver::class);

            return \trim((string)$resolver->resolvePageType($layoutType, null, $request, ''));
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveFrontendThemeId(): int
    {
        try {
            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $theme = $themeContext->resolveTheme('frontend', null, true);
            $id = (int)($theme?->getId() ?? 0);
            if ($id > 0) {
                return $id;
            }
        } catch (\Throwable) {
            // fall through to Theme registered Default
        }
        try {
            /** @var ThemeContextService $themeContext */
            $themeContext = ObjectManager::getInstance(ThemeContextService::class);
            $default = $themeContext->resolveRegisteredDefaultTheme('frontend');

            return (int)($default?->getId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
