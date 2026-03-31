<?php

declare(strict_types=1);

namespace WeShop\Price\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;

class PriceTest extends TestCase
{
    public function testBackendControllerExists(): void
    {
        $this->assertTrue(class_exists(\WeShop\Price\Controller\Backend\Price::class));
    }

    public function testBackendControllerHasRequiredMethods(): void
    {
        $controller = new \ReflectionClass(\WeShop\Price\Controller\Backend\Price::class);

        $this->assertTrue($controller->hasMethod('index'));
        $this->assertTrue($controller->hasMethod('config'));
        $this->assertTrue($controller->hasMethod('save'));
        $this->assertTrue($controller->hasMethod('calculate'));
        $this->assertTrue($controller->hasMethod('reset'));
    }

    public function testBackendControllerExtendsBaseController(): void
    {
        $controller = new \ReflectionClass(\WeShop\Price\Controller\Backend\Price::class);
        $this->assertTrue($controller->isSubclassOf(\Weline\Admin\Controller\BaseController::class));
    }
}
