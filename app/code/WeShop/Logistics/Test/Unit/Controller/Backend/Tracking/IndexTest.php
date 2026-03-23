<?php

declare(strict_types=1);

namespace WeShop\Logistics\Test\Unit\Controller\Backend\Tracking;

use PHPUnit\Framework\TestCase;
use WeShop\Logistics\Controller\Backend\Tracking\Index;

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
