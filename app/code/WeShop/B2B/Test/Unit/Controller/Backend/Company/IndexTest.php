<?php

declare(strict_types=1);

namespace WeShop\B2B\Test\Unit\Controller\Backend\Company;

use PHPUnit\Framework\TestCase;
use WeShop\B2B\Controller\Backend\Company\Index;

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
