<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\View;

use PHPUnit\Framework\TestCase;

final class WishlistHeaderAccountLinksHookTemplateTest extends TestCase
{
    public function testHeaderAccountLinksHookContainsWishlistEntry(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/header-account-links.phtml';
        self::assertFileExists($templateFile);
        $source = (string)file_get_contents($templateFile);
        self::assertStringContainsString('header-account-links', $source);
        self::assertStringContainsString('我的收藏', $source);
        self::assertStringContainsString("customer/account/index'}#wishlist", $source);
        self::assertStringNotContainsString('wishlist/frontend/index', $source);
    }

    public function testAccountSidebarHookContainsWishlistEntry(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/account.sidebar.group.commerce.phtml';
        self::assertFileExists($templateFile);
        $source = (string)file_get_contents($templateFile);
        self::assertStringContainsString('account.sidebar.group.commerce', $source);
        self::assertStringContainsString('account-hook-nav-link', $source);
        self::assertStringContainsString('data-account-nav-parent="commerce"', $source);
        self::assertStringContainsString('我的收藏', $source);
        self::assertStringContainsString("customer/account/index'}#wishlist", $source);
        self::assertStringContainsString('data-section="wishlist"', $source);
        self::assertStringNotContainsString('wishlist/frontend/index', $source);
    }

    public function testAccountSidebarContentHookOwnsWishlistSection(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/account.sidebar.content.phtml';
        self::assertFileExists($templateFile);
        $source = (string)file_get_contents($templateFile);
        self::assertStringContainsString('account.sidebar.content', $source);
        self::assertStringContainsString("accepts('wishlist')", $source);
        self::assertStringContainsString('data-account-section="wishlist"', $source);
        self::assertStringContainsString("fetch('Weline_Wishlist::templates/frontend/wishlist/index.phtml')", $source);
        self::assertStringNotContainsString('include BP', $source);
    }
}
