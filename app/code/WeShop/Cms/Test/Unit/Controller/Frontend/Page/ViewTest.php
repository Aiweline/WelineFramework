<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Controller\Frontend\Page;

use PHPUnit\Framework\TestCase;
use WeShop\Cms\Controller\Frontend\Page\View;

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

    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(View::class);
        $parent = $reflection->getParentClass();
        $this->assertNotFalse($parent);
        $this->assertStringContainsString('BaseController', $parent->getName());
    }
}
