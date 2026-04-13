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

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;

#[Acl('Weline_DeveloperWorkspace::dev-sandbox-manager', '沙盒管理', 'fa fa-database')]
class Sandbox extends BackendController
{
    function index()
    {
        $enabled = Cookie::get('w_sandbox');
        $this->assign('enabled', $enabled);
        return $this->fetch();
    }

    function close()
    {
        $key = $this->request->getGet('key');
        if($key != Env::getInstance()->getConfig('sandbox_key')){
            Message::error(__('启动Key错误'));
            $this->redirect($this->request->getServer('HTTP_REFERER'));
        }
        if($this->request->getGet('close')=='on'){
            setcookie('w_sandbox', '', 0, '/', '', false, false);
            setcookie('w_sandbox', '', 0, '/' . Env::getAreaRoutePrefix('backend'), '', false, false);
        }
        Message::success(__('沙盒环境已关闭,接下来操作的数据将影响正式线上数据库！'));
        $this->redirect($this->request->getServer('HTTP_REFERER'));
    }

    function enable()
    {
        $key = $this->request->getGet('key');
        if($key != Env::getInstance()->getConfig('sandbox_key')){
            Message::error(__('启动Key错误'));
            $this->redirect($this->request->getServer('HTTP_REFERER'));
        }
        if($this->request->getGet('enable')=='on'){
            setcookie('w_sandbox', '1', 0, '/', '', false, false);
            setcookie('w_sandbox', '1', 0, '/' . Env::getAreaRoutePrefix('backend'), '', false, false);
        }
        Message::success(__('沙盒环境已启动! 接下来操作的数据将写入沙盒数据库！'));
        $this->redirect('*/admin/sandbox', ['reload' => 1]);
    }

    function setSandboxKey()
    {
        if ($this->request->isPost()) {
            $key = $this->request->getPost('key');
            Env::getInstance()->setConfig('sandbox_key', $key);
            Message::success(__('沙盒启动Key设置成功'));
        }
        $this->redirect('*/admin/sandbox', ['reload' => 1]);
    }

    function getCloseSandbox()
    {
        Env::getInstance()->setConfig('sandbox_key', false);
        Message::success(__('沙盒环境已关闭'));
        $this->redirect('*/admin/sandbox', ['reload' => 1]);
    }
}