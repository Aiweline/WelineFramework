<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Test;

use Weline\Backend\Model\Menu;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Test\TestCore;

class MenuTest extends TestCore
{
    public function testGetMenuTree()
    {
        /**@var Menu $menu */
        $menu = ObjectManager::getInstance(Menu::class);
        // 原为调试输出 p($menu->getMenuTree())：会直接打印并中断 runner，
        // 导致 `--module=Weline_Backend` 一个用例都执行不到。改成真实断言。
        $tree = $menu->getMenuTree();
        self::assertTrue($tree === null || \is_array($tree), 'getMenuTree() 应返回数组或 null');
    }
}
