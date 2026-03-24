<?php

declare(strict_types=1);

namespace WeShop\Product\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Product\Controller\List\Index;
use WeShop\Product\Controller\View;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testProductListAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Product\Controller\Frontend\Product\ProductList::class));
    }

    public function testProductViewAliasExists(): void
    {
        $reflection = new \ReflectionClass(View::class);

        $this->assertTrue(class_exists(View::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Product\Controller\Frontend\Product\View::class));
    }
}
