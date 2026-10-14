<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * user-area 为多部件槽；语言/货币钉通知条 .header-top-bar-locale，账户/车仍在 user-area。
 */
final class WidgetDefaultInjectionWishlistMultipleSlotTest extends TestCase
{
    public function testUserAreaKeepsAccountCartAndLocalePinnedOnNoticeBar(): void
    {
        $header = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml'
        );
        self::assertStringContainsString('<w:slot id="user-area"', $header);
        self::assertStringContainsString('multiple="true"', $header);
        self::assertStringContainsString('header-top-bar-locale', $header);
        self::assertMatchesRegularExpression(
            '/header-top-bar-locale[\s\S]*?<w:slot id="language"/',
            $header
        );
        self::assertMatchesRegularExpression(
            '/<w:slot id="user-area"[\s\S]*?<w:widget type="header" name="account"/',
            $header
        );
        self::assertMatchesRegularExpression(
            '/<w:slot id="user-area"[\s\S]*?<w:widget type="header" name="mini-cart-icon"/',
            $header
        );
    }

    public function testServiceUsesPerWidgetGateForMultipleTemplateInlineSlots(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetDefaultInjectionService.php'
        );
        self::assertStringContainsString("if (!empty(\$slotMeta['multiple']))", $src);
        self::assertStringContainsString('return $this->widgetExistsAsTemplateInline($themeId, $item, $componentArea);', $src);
    }

    public function testWishlistWidgetTargetsNoticeBarWithoutAppsDefaultInjection(): void
    {
        $widgetPhp = dirname(__DIR__, 4) . '/Wishlist/extends/module/Weline_Widget/Weline_Wishlist/widget.php';
        self::assertFileExists($widgetPhp);
        $src = (string)file_get_contents($widgetPhp);
        self::assertStringNotContainsString("'default_injections'", $src);

        $tpl = dirname(__DIR__, 4) . '/Wishlist/view/theme/frontend/widgets/header/wishlist-icon/default.phtml';
        self::assertFileExists($tpl);
        $tplSrc = (string)file_get_contents($tpl);
        self::assertStringContainsString('@widget.slot {top-bar-rights}', $tplSrc);
    }
}
