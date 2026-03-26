<?php

declare(strict_types=1);

namespace Weline\Component\Test\Unit\Controller\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Component\Controller\Backend\Components;
use Weline\Component\Service\ComponentScanner;
use Weline\Framework\Manager\ObjectManager;

/**
 * Components 控制器单元测试
 * 
 * 测试组件库控制器的核心功能
 */
class ComponentsTest extends TestCase
{
    /**
     * 测试：控制器类存在
     */
    public function testControllerClassExists(): void
    {
        $this->assertTrue(class_exists(Components::class));
    }

    /**
     * 测试：控制器继承 BaseController
     */
    public function testControllerExtendsBaseController(): void
    {
        $reflection = new \ReflectionClass(Components::class);
        $this->assertTrue($reflection->isSubclassOf(\Weline\Admin\Controller\BaseController::class));
    }

    /**
     * 测试：控制器有 index 方法
     */
    public function testControllerHasIndexMethod(): void
    {
        $reflection = new \ReflectionClass(Components::class);
        $this->assertTrue($reflection->hasMethod('getIndex'));
    }

    /**
     * 测试：控制器有 detail 方法
     */
    public function testControllerHasDetailMethod(): void
    {
        $reflection = new \ReflectionClass(Components::class);
        $this->assertTrue($reflection->hasMethod('getDetail'));
    }
}

