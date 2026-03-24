<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Controller\Backend\Company;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Controller\Backend\Company\Save;

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
