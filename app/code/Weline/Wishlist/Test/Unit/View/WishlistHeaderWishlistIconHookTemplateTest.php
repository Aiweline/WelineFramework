<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WishlistHeaderWishlistIconHookTemplateTest extends TestCase
{
    public function testHeaderWishlistIconHookRendersWidgetTemplate(): void
    {
        $path = dirname(__DIR__, 3) . '/view/hooks/header-wishlist-icon.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('header-wishlist-icon', $source);
        self::assertStringContainsString(
            'Weline_Wishlist::theme/frontend/widgets/header/wishlist-icon/default.phtml',
            $source,
        );
    }

    public function testThemeHeaderPartialUsesHookNotInlineWishlistWidget(): void
    {
        $path = dirname(__DIR__, 3) . '/../Theme/view/theme/frontend/partials/header/default.phtml';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);

        self::assertStringContainsString('<w:hook>header-wishlist-icon</w:hook>', $source);
        self::assertDoesNotMatchRegularExpression(
            '/<w:widget[^>]+name="wishlist-icon"/',
            $source,
        );

        $wishlistPos = strpos($source, '<w:hook>header-wishlist-icon</w:hook>');
        $accountPos = strpos($source, '<w:widget type="header" name="account"');
        self::assertNotFalse($wishlistPos);
        self::assertNotFalse($accountPos);
        // 顶栏顺序：货币槽之后 → 收藏 → 账户 → 订单 → 购物车
        self::assertLessThan($accountPos, $wishlistPos);
    }

    public function testWishlistIconHasNoDefaultInjectionsForAppsTab(): void
    {
        $path = dirname(__DIR__, 3) . '/extends/module/Weline_Widget/Weline_Wishlist/widget.php';
        self::assertFileExists($path);
        $source = (string)file_get_contents($path);
        self::assertStringContainsString("'code' => 'wishlist-icon'", $source);
        self::assertStringNotContainsString("'default_injections'", $source);
    }
}
