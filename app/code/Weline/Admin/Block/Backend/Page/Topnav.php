<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block\Backend\Page;

class Topnav extends \Weline\Framework\View\Block
{
    public string $_template = 'Weline_Admin::backend/public/topnav.phtml';
    private \Weline\Backend\Model\Menu $menu;
    private \Weline\Backend\Block\ThemeConfig $themeConfig;

    public function __construct(
        \Weline\Backend\Model\Menu        $menu,
        \Weline\Backend\Block\ThemeConfig $themeConfig,
        array                             $data = []
    ) {
        $this->menu        = $menu;
        $this->themeConfig = $themeConfig;
        parent::__construct($data);
    }

    public function __init()
    {
        parent::__init();
        # 检测主题配置
        if ($this->themeConfig->getThemeConfig('topnav')) {
            $this->processMenu();
        }
    }

    public function render():string
    {
        if ($this->themeConfig->getThemeConfig('topnav')) {
            return parent::render();
        }
        return '';
    }

    public function processMenu()
    {
        $this->assign('menus', $this->menu->getMenuTree());
    }
}
