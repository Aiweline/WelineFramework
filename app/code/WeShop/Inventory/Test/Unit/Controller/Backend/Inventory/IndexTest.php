<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Controller\Backend\Inventory;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Controller\Backend\Inventory\Index;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testControllerHasIndexAction(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
