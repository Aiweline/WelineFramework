<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Controller\Index;

class IndexTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Index::class));
    }

    public function testControllerExtendsFrontendInvoiceController(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Invoice\Controller\Frontend\Invoice\Index::class));
    }

    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Index::class);
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
