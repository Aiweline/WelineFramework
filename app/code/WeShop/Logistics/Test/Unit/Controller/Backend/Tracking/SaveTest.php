<?php

declare(strict_types=1);

namespace WeShop\Logistics\Test\Unit\Controller\Backend\Tracking;

use PHPUnit\Framework\TestCase;
use WeShop\Logistics\Controller\Backend\Tracking\Save;

class SaveTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Save::class));
    }

    public function testControllerHasPostAndIndexMethods(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
