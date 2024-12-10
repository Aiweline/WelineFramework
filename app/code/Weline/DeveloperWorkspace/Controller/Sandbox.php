<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：22/3/2024 13:35:57
 */

namespace Weline\DeveloperWorkspace\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;

class Sandbox extends FrontendController
{
    function close()
    {
        if($this->request->getGet('close')=='on'){
            setcookie('w_sandbox', '', 0, '/', '', false, false);
            setcookie('w_sandbox', '', 0, '/' . Env::getInstance()->getConfig('admin'), '', false, false);
        }
        $this->getMessageManager()->addSuccess(__('沙盒环境已关闭'));
        $this->redirect($this->request->getServer('HTTP_REFERER'));
    }

    function enable()
    {
        if($this->request->getGet('enable')=='on'){
            setcookie('w_sandbox', '1', 0, '/', '', false, false);
            setcookie('w_sandbox', '1', 0, '/' . Env::getInstance()->getConfig('admin'), '', false, false);
        }
        $this->getMessageManager()->addSuccess(__('沙盒环境已启动'));
        $this->redirect($this->request->getServer('HTTP_REFERER'));
    }
}