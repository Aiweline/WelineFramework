<?php

declare(strict_types=1);

namespace WeShop\RMA\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\RMA\Controller\Create;
use WeShop\RMA\Controller\Index;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testRmaIndexAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\RMA\Controller\Frontend\RMA\Index::class));
    }

    public function testRmaCreateAliasExists(): void
    {
        $reflection = new \ReflectionClass(Create::class);

        $this->assertTrue(class_exists(Create::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\RMA\Controller\Frontend\RMA\Create::class));
    }
}
