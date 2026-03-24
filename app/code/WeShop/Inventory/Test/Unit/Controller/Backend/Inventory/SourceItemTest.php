<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Controller\Backend\Inventory;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Controller\Backend\Inventory\SourceItem;

class SourceItemTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(SourceItem::class));
    }

    public function testControllerHasExpectedActions(): void
    {
        $reflection = new \ReflectionClass(SourceItem::class);

        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('edit'));
        $this->assertTrue($reflection->hasMethod('postBatchAdjust'));
        $this->assertTrue($reflection->hasMethod('getProductStock'));
    }
}
