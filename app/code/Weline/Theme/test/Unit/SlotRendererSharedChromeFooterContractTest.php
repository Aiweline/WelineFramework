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

    public function testSharedChromeMergeFillsEmptyHeaderAndFooterFromGlobalCarrier(): void
    {
        $src = $this->readService();

        self::assertMatchesRegularExpression(
            '/function mergeSharedChromeSlotWidgets\([\s\S]*?\$chromeSlots\s*=\s*\[\s*[\'"]header[\'"]\s*,\s*[\'"]footer[\'"]\s*\]/',
            $src
        );
        self::assertStringContainsString('if ($pageType === ThemeLayout::PAGE_TYPE_HOME)', $src);
        self::assertStringContainsString('if (empty($slotWidgets[$slotId]))', $src);
        self::assertStringContainsString(
            '$globalChromeLayout = $this->getLayoutData($themeId, ThemeLayout::PAGE_TYPE_HOME, $status, $area);',
            $src
        );
        self::assertStringContainsString('存储载体，不是归属', $src);
    }
}
