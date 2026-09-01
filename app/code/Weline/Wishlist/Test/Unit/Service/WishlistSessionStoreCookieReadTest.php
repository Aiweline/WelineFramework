<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Wishlist\Service\WishlistSessionStore;

final class WishlistSessionStoreCookieReadTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_COOKIE['weline_wishlist_w0'], $_COOKIE['weline_wishlist']);
        parent::tearDown();
    }

    public function testListIdsReadsWebsiteScopedCookieFromSuperglobal(): void
    {
        $_COOKIE['weline_wishlist_w0'] = '[12,15]';

        $store = new WishlistSessionStore();

        self::assertSame([12, 15], $store->listIds());
    }
}
