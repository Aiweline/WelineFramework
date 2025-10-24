<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller;

use Weline\Framework\App\Controller\BackendController;
use Weline\Admin\Observer\BackendWhitelistUrl;
class BaseController extends BackendController
{
    public function __init()
    {
        parent::__init();
        $this->assign('title', __('欢迎使用WelineFramework框架后台系统！'));
        $this->assign('logo_title', __('WelineFramework'));
    }
}
