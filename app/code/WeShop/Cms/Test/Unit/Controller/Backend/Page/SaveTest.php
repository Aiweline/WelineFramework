<?php

declare(strict_types=1);

namespace WeShop\Cms\Test\Unit\Controller\Backend\Page;

use PHPUnit\Framework\TestCase;
use WeShop\Cms\Controller\Backend\Page\Save;

class SaveTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Save::class));
    }

    public function testControllerHasPostMethod(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $this->assertTrue($reflection->hasMethod('post'));
    }

    public function testControllerHasDeleteMethod(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $this->assertTrue($reflection->hasMethod('delete'));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Save::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
