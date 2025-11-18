<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Sticker\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;
/**
 * Sticker 管理后台控制器
 * 
 * @package Weline_Sticker
 */
#[Acl('Weline_Sticker::sticker_manager_test', 'Sticker测试', 'mdi-sticker', 'Sticker测试', '')]
class Test extends BackendController
{
    public function index(){
        return $this->fetch();
    }
}