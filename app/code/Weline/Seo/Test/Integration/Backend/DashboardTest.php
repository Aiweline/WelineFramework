<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Test\Integration\Backend;

use PHPUnit\Framework\TestCase;
use Weline\Framework\UnitTest\TestCore;
use Weline\Seo\Controller\Backend\Dashboard;

/**
 * Dashboard 控制器集成测试
 * 
 * @package Weline_Seo
 */
class DashboardTest extends TestCore
{
    /**
     * 测试控制器实例化
     */
    public function testControllerInstantiation(): void
    {
        $controller = \Weline\Framework\Manager\ObjectManager::getInstance(Dashboard::class);
        $this->assertInstanceOf(Dashboard::class, $controller);
    }
}

