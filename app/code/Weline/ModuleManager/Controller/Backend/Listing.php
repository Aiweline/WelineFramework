<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\ModuleManager\Controller\Backend;

use Weline\Framework\Manager\ObjectManager;
use Weline\ModuleManager\Model\Module;

class Listing extends \Weline\Framework\App\Controller\BackendController
{
    public function index()
    {
        /**@var Module $module */
        $module = ObjectManager::getInstance(Module::class);
        $module->pagination(
            $this->request->getParam('page', 1),
            $this->request->getParam('pageSize', 10),
            $this->request->getParams()
        )->select()->fetch();
        $this->assign('modules', $module->getItems());
        $this->assign('pagination', $module->getPagination());
        $this->assign('total', $module->getPaginationData()['totalSize']);
        return $this->fetch();
    }
}
