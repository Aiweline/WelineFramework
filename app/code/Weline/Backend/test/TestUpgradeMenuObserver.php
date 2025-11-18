<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/3/28 21:28:58
 */

namespace Weline\Backend\test;

use Weline\Backend\Model\Menu;
use Weline\Framework\Manager\ObjectManager;
use function PHPUnit\Framework\assertTrue;

class TestUpgradeMenuObserver extends \Weline\Framework\UnitTest\TestCore
{
    private Menu $menu;

    function setUp(): void
    {
        parent::setUp();
        $this->menu = ObjectManager::getInstance(Menu::class);
    }

    function testAddMenu()
    {
        $menu   = [
            'source'        => 'Weline_Backend::dashboard',
            'name'          => 'system_dashboard',
            'title'         => '面板',
            'action'        => 'admin',
            'icon'          => 'mdi mdi-monitor-dashboard',
            'order'         => '0',
            'is_system'     => 1,
            'is_backend'    => 1,
            'module'        => 'Weline_Backend',
            'parent_source' => '',
            'pid'           => 0,
        ];
        $result = $this->menu->setData($menu)->save(true,'source');
        $result = $this->menu->setData(['path'=>55])->save(true,'source');
        assertTrue($result, '添加菜单');
    }
}