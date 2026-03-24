<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Controller\Backend\Notification;

use PHPUnit\Framework\TestCase;
use WeShop\Notification\Controller\Backend\Notification\Index;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
