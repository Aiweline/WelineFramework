<?php
declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Controller;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\Message;
use Weline\Framework\Ui\FormKey;

class Sandbox extends FrontendController
{
    protected function csrf(): string
    {
        return FormKey::key_name;
    }

    function close()
    {
        if (!$this->request->isPost()) {
            Message::error(__('无效的请求方法'));
            $this->redirectBack();
            return;
        }

        $key = (string)$this->request->getPost('key', '');
        if (!$this->isValidSandboxKey($key)) {
            Message::error(__('启动Key错误'));
            $this->redirectBack();
            return;
        }

        if ($this->request->getPost('close') === 'on') {
            Cookie::delete('w_sandbox', ['path' => '/']);
            Cookie::delete('w_sandbox', ['path' => $this->getBackendCookiePath()]);
        }

        Message::success(__('沙盒环境已关闭！接下来操作的数据将影响正式线上数据库。'));
        $this->redirectBack();
    }

    function enable()
    {
        if (!$this->request->isPost()) {
            Message::error(__('无效的请求方法'));
            $this->redirectBack();
            return;
        }

        $key = (string)$this->request->getPost('key', '');
        if (!$this->isValidSandboxKey($key)) {
            Message::error(__('启动Key错误'));
            $this->redirectBack();
            return;
        }

        if ($this->request->getPost('enable') === 'on') {
            Cookie::set('w_sandbox', '1', 0, ['path' => '/']);
            Cookie::set('w_sandbox', '1', 0, ['path' => $this->getBackendCookiePath()]);
        }

        Message::success(__('沙盒环境已启动！接下来操作的数据将写入沙盒数据库。'));
        $this->redirectBack();
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

    private function redirectBack(): void
    {
        $referer = (string)$this->request->getServer('HTTP_REFERER');
        $this->redirect($referer !== '' ? $referer : '/');
    }
}
