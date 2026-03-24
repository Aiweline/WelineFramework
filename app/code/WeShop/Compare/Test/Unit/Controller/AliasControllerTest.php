<?php

declare(strict_types=1);

namespace WeShop\Compare\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use WeShop\Compare\Controller\Add;
use WeShop\Compare\Controller\Index;
use WeShop\Compare\Controller\Remove;

class AliasControllerTest extends TestCase
{
    public function testIndexAliasExtendsFrontendCompareIndexController(): void
    {
        $reflection = new \ReflectionClass(Index::class);

        $this->assertTrue($reflection->isSubclassOf(\WeShop\Compare\Controller\Frontend\Compare\Index::class));
        $this->assertTrue($reflection->hasMethod('index'));
    }

    public function testAddAliasExtendsFrontendCompareAddController(): void
    {
        $reflection = new \ReflectionClass(Add::class);

        $this->assertTrue($reflection->isSubclassOf(\WeShop\Compare\Controller\Frontend\Compare\Add::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
    }

    public function testRemoveAliasExtendsFrontendCompareRemoveController(): void
    {
        $reflection = new \ReflectionClass(Remove::class);

        $this->assertTrue($reflection->isSubclassOf(\WeShop\Compare\Controller\Frontend\Compare\Remove::class));
        $this->assertTrue($reflection->hasMethod('index'));
        $this->assertTrue($reflection->hasMethod('post'));
    }
}
