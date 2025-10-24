<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller\System;

use Weline\Admin\Controller\BaseController;
use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Model\SystemConfig;

class Modules extends BaseController
{
    public function getIndex()
    {
        $this->assign('modules', Env::getInstance()->getModuleList());
        return $this->fetch();
    }

    public function router()
    {
        try {
            $routers = include Env::path_FRONTEND_PC_ROUTER_FILE;
        } catch (\Exception $exception) {
            $routers = [];
        }
        $modules_routers = [];
        foreach ($routers as $path => $router) {
            if (str_contains($path, '::GET') or !str_contains($path, '::')) {
                $router['start_page_path'] = str_replace('::GET', '', $path);
            }else{
                $router['start_page_path'] = '';
            }
            $modules_routers[$router['module']][$path] = $router;
        }
        $this->assign('routers', $modules_routers);
        return $this->fetch();
    }
}
