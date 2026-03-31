<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Test\Unit\Controller\Backend\Affiliate;

use PHPUnit\Framework\TestCase;
use WeShop\Affiliate\Controller\Backend\Affiliate\View;

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
