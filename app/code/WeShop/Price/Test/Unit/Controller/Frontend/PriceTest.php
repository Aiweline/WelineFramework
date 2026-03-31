<?php

declare(strict_types=1);

namespace WeShop\Price\Test\Unit\Controller\Frontend;

use PHPUnit\Framework\TestCase;

class PriceTest extends TestCase
{
    public function testFrontendControllerExists(): void
    {
        $this->assertTrue(class_exists(\WeShop\Price\Controller\Frontend\Price::class));
    }

    public function testFrontendControllerHasRequiredMethods(): void
    {
        $controller = new \ReflectionClass(\WeShop\Price\Controller\Frontend\Price::class);

        $this->assertTrue($controller->hasMethod('index'));
        $this->assertTrue($controller->hasMethod('calculate'));
        $this->assertTrue($controller->hasMethod('batch'));
    }

    public function testFrontendControllerExtendsFrontendController(): void
    {
        $controller = new \ReflectionClass(\WeShop\Price\Controller\Frontend\Price::class);
        $this->assertTrue($controller->isSubclassOf(\Weline\Framework\App\Controller\FrontendController::class));
    }
}
