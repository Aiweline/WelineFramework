<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntityPublishedSlotHost;
use Weline\Theme\Service\LayoutEntity\ThemeLayoutEntitySlotFiller;

/**
 * Heal empty Theme chrome on SSR-slim storefront pages that call template()/fetchHtml
 * (skip LayoutSlotRenderer) but still declare showHeader/showFooter Partials.
 *
 * Disk-only splice from chrome.rendered — never LayoutSlot entity fill.
 */
final class StorefrontSsrChromeHealer
{
    public function ensure(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        // 必装永远存在（2026-09-22 架构裁决 + spec/required-default-always-present.md §4）：
        // SSR-slim 页面走 template()/fetchHtml，跳过 LayoutSlotRenderer，因此也不会跑
        // required 默认注入。结果：声明为 required 的**页面槽**会以空壳交付 ——
        // 典型即结账页 `checkout-shipping-address`（契约见
        // Weline_Checkout/test/Unit/View/CheckoutShippingAddressSlotContractTest：
        // 「只走 required injection，禁止 soft fallback 直渲」）。
        // 故在 chrome 修复之前，先补跑一次 required overlay。
        $html = $this->fillRequiredPageDefaults($html);

        if (!ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
            return SlotBoundaryMarkers::strip($html);
        }

        try {
            $themeId = $this->resolveFrontendThemeId();
            if ($themeId < 1) {
                return SlotBoundaryMarkers::strip($html);
            }
            /** @var ThemeLayoutEntitySlotFiller $filler */
            $filler = ObjectManager::getInstance(ThemeLayoutEntitySlotFiller::class);
            $html = $filler->splicePublishedChromeFromDisk($html, $themeId);
        } catch (\Throwable) {
            // soft
        }

        return SlotBoundaryMarkers::strip($html);
    }

    /**
     * 补跑 required 默认注入（页面槽，例如 checkout-shipping-address）。
     *
     * 仅当页面确实存在 published 槽包装（`data-slot-id=`）时才尝试；
     * 失败软降级 —— 注入问题不得让整页 500。
     */
    private function fillRequiredPageDefaults(string $html): string
    {
        if (!\str_contains($html, 'data-slot-id=')) {
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
            /** @var WelineTheme $themes */
            $themes = ObjectManager::getInstance(WelineTheme::class);
            $active = $themes->getActiveTheme('frontend');

            return (int)($active?->getId() ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
