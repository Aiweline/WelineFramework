<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Controller\Admin;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Ui\FormKey;

#[Acl('Weline_DeveloperWorkspace::dev-sandbox-manager', '沙盒管理', 'fa fa-database')]
class Sandbox extends BackendController
{
    protected function csrf(): string
    {
        return FormKey::key_name;
    }

    function index()
    {
        $enabled = Cookie::get('w_sandbox');
        $sandboxKey = Env::getInstance()->getConfig('sandbox_key');

        $this->assign('enabled', $enabled);
        $this->assign('sandboxKeyConfigured', \is_scalar($sandboxKey) && (string)$sandboxKey !== '');
        return $this->fetch();
    }

    function close()
    {
        if (!$this->request->isPost()) {
            Message::error(__('无效的请求方法'));
            $this->redirect('*/admin/sandbox', ['reload' => 1]);
            return;
        }

        $key = (string)$this->request->getPost('key', '');
        if (!$this->isValidSandboxKey($key)) {
            Message::error(__('启动Key错误'));
            $this->redirectBackToSandbox();
            return;
        }

        if ($this->request->getPost('close') === 'on') {
            Cookie::delete('w_sandbox', ['path' => '/']);
            Cookie::delete('w_sandbox', ['path' => $this->getBackendCookiePath()]);
        }

        Message::success(__('沙盒环境已关闭！接下来操作的数据将影响正式线上数据库。'));
        $this->redirectBackToSandbox();
    }

    function enable()
    {
        if (!$this->request->isPost()) {
            Message::error(__('无效的请求方法'));
            $this->redirect('*/admin/sandbox', ['reload' => 1]);
            return;
        }

        $key = (string)$this->request->getPost('key', '');
        if (!$this->isValidSandboxKey($key)) {
            Message::error(__('启动Key错误'));
            $this->redirectBackToSandbox();
            return;
        }

        if ($this->request->getPost('enable') === 'on') {
            Cookie::set('w_sandbox', '1', 0, ['path' => '/']);
            Cookie::set('w_sandbox', '1', 0, ['path' => $this->getBackendCookiePath()]);
        }

        Message::success(__('沙盒环境已启动！接下来操作的数据将写入沙盒数据库。'));
        $this->redirect('*/admin/sandbox', ['reload' => 1]);
    }

    function setSandboxKey()
    {
        if ($this->request->isPost()) {
            $key = \trim((string)$this->request->getPost('key', ''));
            if ($key === '') {
                Message::error(__('沙盒启动Key不能为空'));
                $this->redirect('*/admin/sandbox', ['reload' => 1]);
                return;
            }

            Env::getInstance()->setConfig('sandbox_key', $key);
            Message::success(__('沙盒启动Key设置成功'));
        }
        $this->redirect('*/admin/sandbox', ['reload' => 1]);
    }

    function getCloseSandbox()
    {
        if (!$this->request->isPost()) {
            Message::error(__('无效的请求方法'));
            $this->redirect('*/admin/sandbox', ['reload' => 1]);
            return;
        }

        Env::getInstance()->setConfig('sandbox_key', false);
        Message::success(__('沙盒环境已关闭'));
        $this->redirect('*/admin/sandbox', ['reload' => 1]);
    }

    private function isValidSandboxKey(string $key): bool
    {
        $expected = Env::getInstance()->getConfig('sandbox_key');
        return $key !== '' && \is_scalar($expected) && \hash_equals((string)$expected, $key);
    }

    private function getBackendCookiePath(): string
    {
        $backendPrefix = \trim((string)Env::getAreaRoutePrefix('backend'), '/');
        return $backendPrefix === '' ? '/' : '/' . $backendPrefix;
    }

    private function redirectBackToSandbox(): void
    {
        $referer = (string)$this->request->getServer('HTTP_REFERER');
        if ($referer !== '') {
            $this->redirect($referer);
            return;
        }

        $this->redirect('*/admin/sandbox', ['reload' => 1]);
    }
}
