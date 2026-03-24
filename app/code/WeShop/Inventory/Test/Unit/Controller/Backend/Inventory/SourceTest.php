<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Controller\Backend\Inventory;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Controller\Backend\Inventory\Source;

class SourceTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Source::class));
    }

    public function testControllerHasExpectedActions(): void
    {
        $reflection = new \ReflectionClass(Source::class);

        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('add'));
        $this->assertTrue($reflection->hasMethod('edit'));
        $this->assertTrue($reflection->hasMethod('postDelete'));
        $this->assertTrue($reflection->hasMethod('getDelete'));
    }
}
