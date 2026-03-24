<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Controller\Backend\Invoice;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Controller\Backend\Invoice\View;

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
