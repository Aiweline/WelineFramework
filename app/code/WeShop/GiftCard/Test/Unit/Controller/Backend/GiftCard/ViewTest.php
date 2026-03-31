<?php

declare(strict_types=1);

namespace WeShop\GiftCard\Test\Unit\Controller\Backend\GiftCard;

use PHPUnit\Framework\TestCase;
use WeShop\GiftCard\Controller\Backend\GiftCard\View;

class ViewTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(View::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
