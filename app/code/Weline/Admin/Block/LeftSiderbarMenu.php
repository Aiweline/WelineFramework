<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block;

class LeftSiderbarMenu extends \Weline\Framework\View\Block
{
    private \Weline\Backend\Model\Menu $menu;

    public function __construct(\Weline\Backend\Model\Menu $menu, array $data = [])
    {
        $this->menu = $menu;
        parent::__construct($data);
    }

    public function getMenuTree()
    {
        return $this->menu->getMenuTree();
    }
}
