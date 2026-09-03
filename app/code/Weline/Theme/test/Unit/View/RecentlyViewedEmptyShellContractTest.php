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
}
