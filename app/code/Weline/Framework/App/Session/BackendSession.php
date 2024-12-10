<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Session;

class BackendSession extends \Weline\Framework\Session\Session
{
    public const login_KEY        = 'WF_BACKEND_USER';
    public const login_KEY_ID     = 'WF_BACKEND_USER_ID';
    public const login_USER_MODEL = 'WF_BACKEND_USER_MODEL';

    public function __init()
    {
        parent::__init();
        $this->setType('backend');
    }
}
