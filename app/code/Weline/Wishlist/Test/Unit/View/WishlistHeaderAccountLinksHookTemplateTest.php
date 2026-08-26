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
        self::assertStringContainsString('wishlist/frontend/index', $source);
    }

    public function testAccountSidebarHookContainsWishlistEntry(): void
    {
        $templateFile = dirname(__DIR__, 3) . '/view/hooks/account.sidebar.phtml';
        self::assertFileExists($templateFile);
        $source = (string)file_get_contents($templateFile);
        self::assertStringContainsString('account.sidebar', $source);
        self::assertStringContainsString('account-hook-nav-link', $source);
        self::assertStringContainsString('我的收藏', $source);
    }
}
