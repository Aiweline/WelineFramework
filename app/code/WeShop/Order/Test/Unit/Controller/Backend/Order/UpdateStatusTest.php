<?php

declare(strict_types=1);

namespace WeShop\Order\Test\Unit\Controller\Backend\Order;

use PHPUnit\Framework\TestCase;
use WeShop\Order\Controller\Backend\Order\UpdateStatus;

class UpdateStatusTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(UpdateStatus::class));
    }

    public function testControllerHasPostAndIndexMethods(): void
    {
        $reflection = new \ReflectionClass(UpdateStatus::class);
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
