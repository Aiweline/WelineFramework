<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller;

/**
 * 后台仪表盘控制器
 * 
 * 路由 admin/dashboard 的入口，与 admin (Index::index) 渲染同一个仪表盘页面。
 * 解决 admin/dashboard 404 问题——多处代码引用此路由，需确保它能正常响应。
 */
class Dashboard extends BaseController
{
    public function index()
    {
        return $this->fetch('Index/index');
    }
}
