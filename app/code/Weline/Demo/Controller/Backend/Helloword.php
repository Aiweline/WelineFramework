<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Demo\Controller\Backend;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\Acl\Acl;

#[Acl('Weline_Demo::Helloword', 'Helloword', '', '')]
class Helloword extends BackendController
{
    #[Acl('Weline_Demo::Helloword::index', '哈喽', '', '')]
    public function index()
    {
        $this->assign('message', 'Hello from Helloword');
        return $this->fetch();
    }
}