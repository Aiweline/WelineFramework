<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：22/3/2024 10:03:34
 */

namespace Weline\DeveloperWorkspace\Controller\Admin;

use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;

class Sandbox extends BackendController
{
    function index()
    {
        return $this->fetch();
    }

    function setSandboxKey()
    {
        if ($this->request->isPost()) {
            $key = $this->request->getPost('key');
            Env::getInstance()->setConfig('sandbox_key', $key);
            $this->redirect('/component/offcanvas/success', ['msg' => __('沙盒启动Key设置成功'), 'url' => '*/admin/sandbox','reload' => 1]);
        }
        return $this->fetch();
    }

    function getCloseSandbox()
    {
        Env::getInstance()->setConfig('sandbox_key', false);
        $this->redirect('/component/offcanvas/success', ['msg' =>__( '沙盒启动Key设置成功'), 'url' => '*/admin/sandbox','reload' => 1]);
    }
}