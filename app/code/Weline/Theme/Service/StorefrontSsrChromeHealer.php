<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
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
        if ($html === '' || !ThemeLayoutEntityPublishedSlotHost::shellNeedsRuntimeSafetyNetFill($html)) {
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
