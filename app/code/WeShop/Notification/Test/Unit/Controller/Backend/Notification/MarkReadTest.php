<?php

declare(strict_types=1);

namespace WeShop\Notification\Test\Unit\Controller\Backend\Notification;

use PHPUnit\Framework\TestCase;
use WeShop\Notification\Controller\Backend\Notification\MarkRead;

class MarkReadTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(MarkRead::class));
    }

    public function testControllerHasIndexAndPostMethods(): void
    {
        $reflection = new \ReflectionClass(MarkRead::class);
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
    }
}
