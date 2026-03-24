<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Subscription\Controller\View;

class ViewTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(View::class));
    }

    public function testControllerExtendsFrontendSubscriptionView(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Subscription\Controller\Frontend\Subscription\View::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
