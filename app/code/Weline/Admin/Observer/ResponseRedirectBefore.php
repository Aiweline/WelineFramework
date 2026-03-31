<?php

namespace Weline\Admin\Observer;

use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Session;

class ResponseRedirectBefore implements ObserverInterface
{
    /**
     * @var Request
     */
    protected Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        try {
            // WLS 下 Observer 可能复用旧实例，这里强制切到当前请求上下文。
            $this->request = ObjectManager::getInstance(Request::class);

            $data = $event->getData('data');
            $url = $data->getUrl();
            $code = $data->getCode();
            $path = $this->request->getRouteUrlPath($url);
            
            $isBackendResult = false;
            try {
                $isBackendResult = $this->request->isBackend();
            } catch (\Throwable $e) {
                throw $e;
            }
            
            if (!$isBackendResult) {
                return;
            }
            
            $isGetResult = false;
            try {
                $isGetResult = $this->request->isGet();
            } catch (\Throwable $e) {
                throw $e;
            }
            
            if (!$isGetResult) {
                return;
            }
            if (($path !== 'admin/login')) {
                return;
            }
            if (($code !== 302)) {
                return;
            }
            
            if ($this->request->isAjax() || $this->request->isIframe()) {
                return;
            }
            
            $white_urls = BackendWhitelistUrl::white_urls;
            $white_urls[] = ['path' => 'admin/login/logout'];
            foreach ($white_urls as &$white_url) {
                $white_url = $white_url['path'];
            }
            
            $currentRoutePath = trim($this->request->getRouteUrlPath(), '/');
            
            // 白名单跳过验证
            if (!in_array($currentRoutePath, $white_urls)) {
                // 只存储菜单链接
                if (MenuUrlValidator::isMenuUrl($currentRoutePath)) {
                    ObjectManager::getInstance(Session::class)->setData('backend_login_referer', $this->request->getUrlBuilder()->getCurrentUrl());
                }
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
