<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Backend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Controller\Backend\Order\Index;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
