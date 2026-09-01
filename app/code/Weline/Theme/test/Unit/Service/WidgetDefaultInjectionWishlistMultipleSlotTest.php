<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * user-area 为多部件槽：wishlist-icon 不应被模板直嵌 account/mini-cart 整槽拦截。
 */
final class WidgetDefaultInjectionWishlistMultipleSlotTest extends TestCase
{
    public function testUserAreaSlotIsMultipleWithTemplateInlineWidgets(): void
    {
        $header = (string)file_get_contents(
            dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml'
        );
        self::assertStringContainsString('<w:slot id="user-area"', $header);
        self::assertStringContainsString('multiple="true"', $header);
        self::assertStringContainsString('<w:widget type="header" name="account" />', $header);
    }

    public function testServiceUsesPerWidgetGateForMultipleTemplateInlineSlots(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Service/WidgetDefaultInjectionService.php'
        );
        self::assertStringContainsString("if (!empty(\$slotMeta['multiple']))", $src);
        self::assertStringContainsString('return $this->widgetExistsAsTemplateInline($themeId, $item, $componentArea);', $src);
    }

    public function testWishlistWidgetTargetsUserAreaAsRecommend(): void
    {
        $widgetPhp = dirname(__DIR__, 4) . '/Wishlist/extends/module/Weline_Widget/Weline_Wishlist/widget.php';
        self::assertFileExists($widgetPhp);
        $src = (string)file_get_contents($widgetPhp);
        self::assertStringContainsString("'slot' => 'user-area'", $src);
        self::assertStringContainsString("'required' => false", $src);
    }
}
