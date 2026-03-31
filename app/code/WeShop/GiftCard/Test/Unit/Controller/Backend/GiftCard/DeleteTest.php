<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Controller\Backend\GiftCard;

use PHPUnit\Framework\TestCase;
use WeShop\GiftCard\Controller\Backend\GiftCard\Delete;

class DeleteTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Delete::class));
    }

    public function testControllerHasDeleteMethod(): void
    {
        $reflection = new \ReflectionClass(Delete::class);
        $this->assertTrue($reflection->hasMethod('delete'));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Delete::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testIndexCallsDelete(): void
    {
        $controller = $this->getMockBuilder(Delete::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['delete'])
            ->getMock();

        $controller->expects($this->once())
            ->method('delete')
            ->willReturn('');

        $this->assertSame('', $controller->index());
    }
}
