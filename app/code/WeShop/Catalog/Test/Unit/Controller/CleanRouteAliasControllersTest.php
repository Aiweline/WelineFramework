<?php

declare(strict_types=1);

namespace WeShop\Catalog\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Catalog\Controller\Category\View;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testCategoryViewAliasExists(): void
    {
        $reflection = new \ReflectionClass(View::class);

        $this->assertTrue(class_exists(View::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Catalog\Controller\Frontend\Category\View::class));
    }
}
