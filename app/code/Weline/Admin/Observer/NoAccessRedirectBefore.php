<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/2/2 17:13:52
 */

namespace Weline\Admin\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Http\Request;

class NoAccessRedirectBefore implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var \Weline\Framework\Http\Request
     */
    private Request $request;

    function __construct(
        Request $request
    )
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $isBackend = false;
        try {
            $isBackend = $this->request->isBackend();
        } catch (\Throwable $e) {
            throw $e;
        }
        
        if ($isBackend) {
            try {
                // 使用当前请求同源登录 URL，避免与 Cookie 域不一致导致 admin ↔ login 循环重定向
                $loginUrl = $this->getBackendLoginUrlSameOrigin();
                $response = $this->request->getResponse();
                $response->redirect($loginUrl);
            } catch (\Throwable $e) {
                throw $e;
            }
        }
    }

    /**
     * 使用当前请求的 scheme+host 生成后台登录 URL，保证与 Cookie 同源，避免循环重定向。
     */
    private function getBackendLoginUrlSameOrigin(): string
    {
        $pathPart = $this->request->getUrlBuilder()->getBackendUrlPath('admin/login');
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = $this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost';
        return $scheme . '://' . $host . $pathPart;
    }
}