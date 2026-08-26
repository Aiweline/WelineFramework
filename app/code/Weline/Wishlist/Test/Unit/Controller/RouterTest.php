<?php

declare(strict_types=1);

namespace Weline\Wishlist\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\Wishlist\Controller\Router;

final class RouterTest extends TestCase
{
    public function testPublicWishlistPathRoutesToWishlistIndexController(): void
    {
        $path = '/wishlist/';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('weline_wishlist/frontend', $path);
        self::assertSame('Weline_Wishlist', $rule['module'] ?? null);
    }

    public function testUnrelatedPathsAreNotRewritten(): void
    {
        $path = 'products';
        $rule = [];

        Router::process($path, $rule);

        self::assertSame('products', $path);
        self::assertSame([], $rule);
    }

    public function testAnExistingModuleMatchAlwaysWins(): void
    {
        $path = 'wishlist';
        $rule = ['module' => 'Existing_Module'];

        Router::process($path, $rule);

        self::assertSame('wishlist', $path);
        self::assertSame(['module' => 'Existing_Module'], $rule);
    }
}
