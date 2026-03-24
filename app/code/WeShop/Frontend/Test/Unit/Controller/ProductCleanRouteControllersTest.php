<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\Product\List\Index;

class ProductCleanRouteControllersTest extends TestCase
{
    public function testProductListAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Product\Controller\Frontend\Product\ProductList::class));
    }
}
