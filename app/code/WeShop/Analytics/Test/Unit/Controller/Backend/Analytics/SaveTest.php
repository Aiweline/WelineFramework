<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Controller\Backend\Analytics;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Controller\Backend\Analytics\Save;

class SaveTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        self::assertTrue(class_exists(Save::class));
    }

    public function testControllerHasPostAndIndexMethods(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        self::assertTrue($reflection->hasMethod('post'));
        self::assertTrue($reflection->hasMethod('index'));
    }
}
