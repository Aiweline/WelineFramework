<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Seo\Test\Integration\Backend;

use Weline\Framework\App\Controller\BackendPageController;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;
use Weline\Seo\Controller\Backend\Dashboard;

/**
 * Dashboard 控制器集成测试
 * 
 * @package Weline_Seo
 */
class DashboardTest extends TestCore
{
    /**
     * 后台控制器在无活动路由的测试进程中不应通过 ObjectManager 装配。
     * 直接构造仅验证本控制器的依赖契约；真实路由由 HTTP/E2E 门禁覆盖。
     */
    public function testControllerCanBeConstructedWithoutActiveRouter(): void
    {
        $controller = new Dashboard(ObjectManager::getInstance());

        self::assertInstanceOf(Dashboard::class, $controller);
        self::assertInstanceOf(BackendPageController::class, $controller);
    }
}

