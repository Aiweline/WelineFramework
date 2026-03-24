<?php

declare(strict_types=1);

namespace WeShop\Subscription\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Subscription\Controller\Index;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testControllerExtendsFrontendSubscriptionIndex(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Subscription\Controller\Frontend\Subscription\Index::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
