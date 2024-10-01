<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Module\Dependency;

use \Weline\Framework\UnitTest\TestCore;

class SortTest extends TestCore
{
    public function testDependenciesSort()
    {
        /**@var Sort $sort */
        $sort = $this->getInstance(Sort::class);
        $ds   = [
            /*['id' => 'top', 'dependencies' => ['docBody']],
            ['id' => 'header_border_container', 'dependencies' => ['top']],
            ['id' => 'header_left', 'dependencies' => ['header_border_container']],
            ['id' => 'header_center', 'dependencies' => ['header_border_container']],
            ['id' => 'system_toolbar', 'dependencies' => ['header_center']],
            ['id' => 'Notifications_Button', 'dependencies' => ['Notification_Dialog', 'system_toolbar']],
            ['id' => 'User_Menu_Button', 'dependencies' => ['system_toolbar', 'User_Menu']],
            ['id' => 'User_Menu', 'dependencies' => ['header_center']],
            ['id' => 'User_Menu_LogOut', 'dependencies' => ['User_Menu']],
            ['id' => 'User_Menu_Change_Password', 'dependencies' => ['User_Menu', 'User_Menu_LogOut']],
            ['id' => 'Notifications_Store', 'dependencies' => ['header_center']],
            ['id' => 'left', 'dependencies' => ['docBody']],
            ['id' => 'menu_accordian', 'dependencies' => ['left']],
            ['id' => 'ScreenContainer', 'dependencies' => ['docBody']],
            ['id' => 'InfoDialog', 'dependencies' => ['docBody']],
            ['id' => 'ID_BC', 'dependencies' => ['InfoDialog']],
            ['id' => 'InfoDialogContent', 'dependencies' => ['ID_BC']],
            ['id' => 'change_password_dialog', 'dependencies' => ['docBody']],
            ['id' => 'toaster', 'dependencies' => []],
            ['id' => 'Notification_Dialog', 'dependencies' => []],
            ['id' => 'Notifications_Grid', 'dependencies' => ['Notification_Dialog', 'Notifications_Store']],
            ['id' => 'docBody', 'dependencies' => []],*/
            'test2'=> ['id' => 'test2', 'parents' => ['test']],
            'test'=>['id' => 'test', 'parents' => []],
            'Weline_Backend'=>[
                'id' => 'Weline_Backend', 'parents' => ['Weline_SystemConfig']
            ],
            'Weline_SystemConfig'=>[
                'id' => 'Weline_SystemConfig', 'parents' => []
            ],
        ];
        p($sort->dependenciesSort($ds,'id','parents'));
    }
}
