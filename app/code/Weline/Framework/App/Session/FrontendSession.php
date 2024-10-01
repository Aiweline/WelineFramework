<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Session;

use Weline\Framework\Session\Session;

class FrontendSession extends Session
{
    public const login_KEY        = 'WF_FRONTEND_USER';
    public const login_KEY_ID     = 'WF_FRONTEND_USER_ID';
    public const login_USER_MODEL = 'WF_FRONTEND_USER_MODEL';
}
