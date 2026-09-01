<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

/**
 * recently-viewed must never return blank HTML (DEV health toast).
 */
final class RecentlyViewedEmptyShellContractTest extends TestCase
{
    public function testEmptyPathEmitsNonEmptyShellWithTestId(): void
    {
        $path = dirname(__DIR__, 3) . '/view/theme/frontend/widgets/product/recently-viewed/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('data-testid="recently-viewed-empty"', $source);
        self::assertStringContainsString('暂无浏览记录', $source);
        self::assertStringContainsString('Never return a blank string', $source);
        self::assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*empty\(\s*\$products\s*\)\s*\)\s*\{\s*return\s*;\s*\}/s',
            $source,
            'Empty product list must render a shell, not bare return.',
        );
    }

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
