<?php

declare(strict_types=1);

namespace WeShop\Frontend\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Frontend\Controller\Cart\Index;

class CartCleanRouteControllerTest extends TestCase
{
    public function testCartAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Cart\Controller\Frontend\Cart\Index::class));
    }
}
