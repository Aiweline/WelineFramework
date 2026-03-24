<?php

declare(strict_types=1);

namespace WeShop\Membership\Test\Unit\Controller\Backend\Membership;

use PHPUnit\Framework\TestCase;
use WeShop\Membership\Controller\Backend\Membership\Index;

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
