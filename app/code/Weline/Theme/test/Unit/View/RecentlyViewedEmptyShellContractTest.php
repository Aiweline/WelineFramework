<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * Slot registry must clear between widget renders (PDP nested slots).
 * Empty-shell ownership moved to Weline_RecentlyViewed.
 */
final class RecentlyViewedEmptyShellContractTest extends TestCase
{
    public function testSlotRendererClearsSlotRegistryPerWidgetRender(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/SlotRendererService.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('use Weline\\Theme\\Taglib\\Slot;', $source);
        self::assertStringContainsString('Slot::clearRegisteredSlots();', $source);
        self::assertStringContainsString('product-purchase-actions', $source);
    }

    /**
     * required-default-all-layouts: entity/overlay re-render product-info via
     * ThemeComponentRenderer — must clear Slot registry like SlotRendererService
     * or product-selling-mode duplicate aborts product-main.
     */
    public function testThemeComponentRendererClearsSlotRegistryPerWidgetRender(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/ThemeComponentRenderer.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('use Weline\\Theme\\Taglib\\Slot;', $source);
        self::assertStringContainsString('Slot::clearRegisteredSlots();', $source);
        $clearPos = strpos($source, 'Slot::clearRegisteredSlots();');
        $areaPos = strpos($source, '$area = $definition->area');
        self::assertNotFalse($clearPos);
        self::assertNotFalse($areaPos);
        self::assertLessThan($areaPos, $clearPos, 'clearRegisteredSlots must run before template render');
    }
}
