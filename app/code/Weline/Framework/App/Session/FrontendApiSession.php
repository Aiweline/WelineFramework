<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Session;

use Weline\Framework\Session\Session;

class FrontendApiSession extends Session
{
    public const login_KEY = 'WL_FRONTEND_API';

    public function __init()
    {
        parent::__init();
        $this->setType('frontend_api');
    }
}
