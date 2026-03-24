<?php

declare(strict_types=1);

namespace WeShop\Analytics\Test\Unit\Controller\Backend\Analytics;

use PHPUnit\Framework\TestCase;
use WeShop\Analytics\Controller\Backend\Analytics\Index;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        self::assertTrue(class_exists(Index::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        self::assertTrue($reflection->hasMethod('index'));
    }
}
