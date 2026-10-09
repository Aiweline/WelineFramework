<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class HeaderScopeSwitcherSlotContractTest extends TestCase
{
    public function testDefaultHeaderExposesEmptyScopeSwitcherSlotWithoutEmbeddingWidget(): void
    {
        $header = dirname(__DIR__, 3) . '/view/theme/frontend/partials/header/default.phtml';
        $widget = dirname(__DIR__, 4) . '/Websites/extends/module/Weline_Widget/Weline_Websites/widget.php';
        self::assertFileExists($header);
        self::assertFileExists($widget);

        $headerSrc = (string)file_get_contents($header);
        $widgetSrc = (string)file_get_contents($widget);

        self::assertStringContainsString('id="scope-switcher"', $headerSrc);
        self::assertStringContainsString('accept="layout-header-scope-switcher,scope-switcher"', $headerSrc);
        self::assertStringContainsString('id="top-bar-rights"', $headerSrc);
        self::assertStringContainsString('header-top-bar-scope', $headerSrc);
        self::assertStringContainsString('<w:hook>header-wishlist-icon</w:hook>', $headerSrc);
        self::assertStringNotContainsString('name="scope-switcher"', $headerSrc);
        // 渠道 + 收藏落在通知条右侧，不在主栏 user-area
        $topBarPos = strpos($headerSrc, 'id="top-bar-rights"');
        $scopePos = strpos($headerSrc, 'id="scope-switcher"');
        $wishlistPos = strpos($headerSrc, '<w:hook>header-wishlist-icon</w:hook>');
        $userAreaPos = strpos($headerSrc, 'id="user-area"');
        self::assertNotFalse($topBarPos);
        self::assertNotFalse($scopePos);
        self::assertNotFalse($wishlistPos);
        self::assertNotFalse($userAreaPos);
        // PHPUnit assertLessThan($expected, $actual) ⇒ actual < expected
        self::assertLessThan($scopePos, $topBarPos);
        self::assertLessThan($wishlistPos, $scopePos);
        self::assertLessThan($userAreaPos, $wishlistPos);
        self::assertStringContainsString("'slot' => 'scope-switcher'", $widgetSrc);
        self::assertStringContainsString("'layout_type' => 'homepage'", $widgetSrc);
        self::assertStringContainsString("'source' => 'Weline_Websites::css/widgets/scope-switcher.css'", $widgetSrc);

        $themeCss = dirname(__DIR__, 3) . '/view/theme/frontend/assets/css/theme.css';
        self::assertFileExists($themeCss);
        $themeCssBody = (string)file_get_contents($themeCss);
        self::assertStringContainsString('.w-scope-switcher__menu', $themeCssBody);
    }
}
