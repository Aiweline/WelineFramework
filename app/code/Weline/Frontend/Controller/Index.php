<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Controller;

use Weline\Framework\App\Controller\FrontendController;

/**
 * 前端首页控制器
 */
class Index extends FrontendController
{
    /**
     * 布局类型，用于 Theme 模块自动加载对应的布局模板
     * 
     * @var string|null
     */
    protected ?string $layoutType = 'homepage';

    /**
     * 首页
     */
    public function getIndex()
    {
        // 设置页面标题
        $this->assign('title', __('首页'));
        
        // 设置页面数据
        $this->assign('welcomeMessage', __('欢迎访问'));
        $this->assign('description', __('这是 WelineFramework 的默认首页'));
        
        // 渲染首页模板
        return $this->fetch('Weline_Frontend::templates/frontend/index.phtml');
    }
}

