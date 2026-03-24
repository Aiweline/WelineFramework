<?php

declare(strict_types=1);

namespace WeShop\Wishlist\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Wishlist\Controller\Add;
use WeShop\Wishlist\Controller\Index;
use WeShop\Wishlist\Controller\Remove;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testWishlistIndexAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Wishlist\Controller\Frontend\Wishlist\Index::class));
    }

    public function testWishlistAddAliasExists(): void
    {
        $reflection = new \ReflectionClass(Add::class);

        $this->assertTrue(class_exists(Add::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Wishlist\Controller\Frontend\Wishlist\Add::class));
    }

    public function testWishlistRemoveAliasExists(): void
    {
        $reflection = new \ReflectionClass(Remove::class);

        $this->assertTrue(class_exists(Remove::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Wishlist\Controller\Frontend\Wishlist\Remove::class));
    }
}
