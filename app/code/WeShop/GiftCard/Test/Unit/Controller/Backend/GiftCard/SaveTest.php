<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Controller\Backend\GiftCard;

use PHPUnit\Framework\TestCase;
use WeShop\GiftCard\Controller\Backend\GiftCard\Save;

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

    public function testIndexCallsPost(): void
    {
        $controller = $this->getMockBuilder(Save::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['post'])
            ->getMock();

        $controller->expects($this->once())
            ->method('post')
            ->willReturn('');

        $this->assertSame('', $controller->index());
    }
}
