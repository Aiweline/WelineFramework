<?php

declare(strict_types=1);

namespace WeShop\Invoice\Test\Unit\Controller\Backend\Invoice;

use PHPUnit\Framework\TestCase;
use WeShop\Invoice\Controller\Backend\Invoice\Issue;

class IssueTest extends TestCase
{
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Issue::class));
    }

    public function testControllerHasPostAndIndexMethods(): void
    {
        $reflection = new \ReflectionClass(Issue::class);
        $this->assertTrue($reflection->hasMethod('post'));
        $this->assertTrue($reflection->hasMethod('index'));
    }
}
