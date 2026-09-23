<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 页头/页脚是全局 chrome：不属于某一业务布局；缺槽时从全局 chrome 载体合并。
 */
final class SlotRendererSharedChromeFooterContractTest extends TestCase
{
    private function readService(): string
    {
        $path = dirname(__DIR__, 2) . '/Service/SlotRendererService.php';
        $src = file_get_contents($path);
        self::assertIsString($src);

        return $src;
    }

    public function testDoProcessSlotsMergesSharedChromeAfterLayoutScopedMerge(): void
    {
        $src = $this->readService();

        self::assertStringContainsString('function mergeSharedChromeSlotWidgets(', $src);
        self::assertStringContainsString("slot_renderer::mergeSharedChromeSlotWidgets", $src);
        self::assertStringContainsString("ThemeLayout::PAGE_TYPE_HOME", $src);
        self::assertStringContainsString('页头/页脚是全局 chrome', $src);

        $mergeLayoutPos = strpos($src, 'slot_renderer::mergeLayoutScopedSlotWidgets');
        $mergeChromePos = strpos($src, 'slot_renderer::mergeSharedChromeSlotWidgets');
        self::assertNotFalse($mergeLayoutPos);
        self::assertNotFalse($mergeChromePos);
        self::assertGreaterThan($mergeLayoutPos, $mergeChromePos);
    }

    public function testSharedChromeMergeOverwritesLocalChromeSlotsFromGlobalCarrier(): void
    {
        $src = $this->readService();

        self::assertStringContainsString('function mergeSharedChromeSlotWidgets(', $src);
        self::assertStringContainsString('function slotWidgetsBelongToSharedChrome(', $src);
        self::assertStringContainsString('function loadSharedChromeSlotWidgetsFromEntity(', $src);
        self::assertStringContainsString('if ($pageType === ThemeLayout::PAGE_TYPE_HOME', $src);
        self::assertStringContainsString('SharedChromeService::CHROME_SLOTS', $src);
        // Global chrome is authoritative: local business-layout copies must not win.
        self::assertStringContainsString('$slotWidgets[$slotId] = $widgets;', $src);
        // Version selection is shared with the root; behavior is covered by SlotRendererBindingAndDiagnosticsTest.
        self::assertStringContainsString('resolveRenderSources(', $src);
        self::assertStringContainsString("\$widgetArea === 'header' || \$widgetArea === 'footer'", $src);
        self::assertStringContainsString('本页本地 chrome 副本不得覆盖全局', $src);
    }

    public function testProcessSlotsWithLayoutAlsoMergesSharedChrome(): void
    {
        $src = $this->readService();
        $fnPos = strpos($src, 'public function processSlotsWithLayout(');
        self::assertNotFalse($fnPos);
        $nextFn = strpos($src, 'private function doProcessSlots(', $fnPos);
        self::assertNotFalse($nextFn);
        $body = substr($src, $fnPos, $nextFn - $fnPos);
        self::assertStringContainsString('mergeSharedChromeSlotWidgets(', $body);
        self::assertStringContainsString('mergeLayoutScopedSlotWidgets(', $body);
    }
}
