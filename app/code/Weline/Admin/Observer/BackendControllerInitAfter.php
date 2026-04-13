<?php

namespace Weline\Admin\Observer;

use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Admin\Service\BackendRememberLoginService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class BackendControllerInitAfter implements ObserverInterface
{
    private Request $request;
    private BackendRememberLoginService $backendRememberLoginService;

    public function __construct(Request $request, BackendRememberLoginService $backendRememberLoginService)
    {
        $this->request = $request;
        $this->backendRememberLoginService = $backendRememberLoginService;
    }

    private function getSession(): AuthenticatedSessionInterface
    {
        return SessionFactory::getInstance()->createBackendSession();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // WLS 下 Observer 可能复用旧实例，这里强制切到当前请求上下文。
        $this->request = ObjectManager::getInstance(Request::class);

        $currentRoutePath = trim($this->request->getRouteUrlPath(), '/');
        // 真实登录提交阶段不执行 remember-me 自动登录，避免干扰本次账号密码登录流程。
        $this->backendRememberLoginService->restoreIfNeeded($this->request);

        if (!$this->request->isBackend()) {
            return;
        }
        if ($this->request->isAjax()) {
            return;
        }
        if ($this->request->isIframe()) {
            return;
        }

        $whiteUrls = BackendWhitelistUrl::white_urls;
        $whiteUrls[] = ['path' => 'admin/login/logout'];
        foreach ($whiteUrls as &$whiteUrl) {
            $whiteUrl = $whiteUrl['path'];
        }

        if (!in_array($currentRoutePath, $whiteUrls, true) && !$this->request->getParam('isIframe')) {
            if (MenuUrlValidator::isMenuUrl($currentRoutePath)) {
                $this->getSession()->set('referer', $this->request->getUrlBuilder()->getCurrentUrl());
            }
        }
    }
}
