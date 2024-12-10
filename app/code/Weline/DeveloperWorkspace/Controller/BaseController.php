<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\DeveloperWorkspace\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class BaseController extends FrontendController
{
    public function __construct()
    {
        $this->assign('title', __('WelineFramework开发文档'));
    }

    public function getModel(string $model)
    {
        return ObjectManager::getInstance($model);
    }
}
