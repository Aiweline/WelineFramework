<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Controller\Backend\Affiliate;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Controller\Backend\Affiliate\Save;

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
