<?php

declare(strict_types=1);

namespace WeShop\Filters\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Filters\Controller\Counts;
use WeShop\Filters\Controller\Filter;
use WeShop\Filters\Controller\Options;

class CleanRouteAliasControllersTest extends TestCase
{
    public function testFilterAliasExists(): void
    {
        $reflection = new \ReflectionClass(Filter::class);

        $this->assertTrue(class_exists(Filter::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Filters\Controller\Frontend\Ajax::class));
    }

    public function testOptionsAliasExists(): void
    {
        $reflection = new \ReflectionClass(Options::class);

        $this->assertTrue(class_exists(Options::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Filters\Controller\Frontend\Ajax::class));
    }

    public function testCountsAliasExists(): void
    {
        $reflection = new \ReflectionClass(Counts::class);

        $this->assertTrue(class_exists(Counts::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->isSubclassOf(\WeShop\Filters\Controller\Frontend\Ajax::class));
    }
}
