<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Subscription\Controller\Cancel;
use WeShop\Subscription\Controller\Index;
use WeShop\Subscription\Controller\Pause;
use WeShop\Subscription\Controller\View;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testSubscriptionIndexAliasExists(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue(class_exists(Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Subscription\Controller\Frontend\Subscription\Index::class));
    }

    public function testSubscriptionViewAliasExists(): void
    {
        $reflection = new \ReflectionClass(View::class);

        $this->assertTrue(class_exists(View::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Subscription\Controller\Frontend\Subscription\View::class));
    }

    public function testSubscriptionPauseAliasExists(): void
    {
        $reflection = new \ReflectionClass(Pause::class);

        $this->assertTrue(class_exists(Pause::class));
        $this->assertTrue($reflection->hasMethod('postIndex'));
        $this->assertTrue($reflection->hasMethod('postResume'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Subscription\Controller\Frontend\Subscription\Pause::class));
    }

    public function testSubscriptionCancelAliasExists(): void
    {
        $reflection = new \ReflectionClass(Cancel::class);

        $this->assertTrue(class_exists(Cancel::class));
        $this->assertTrue($reflection->hasMethod('postIndex'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Subscription\Controller\Frontend\Subscription\Cancel::class));
    }
}
