<?php

namespace Weline\Admin\Observer;

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
    public function execute(Event $event): void
    {
        $data = $event->getData('data');
        $url = $data->getUrl();
        $code = $data->getCode();
        $path = $this->request->getRouteUrlPath($url);
        if (!$this->request->isBackend()) {
            return;
        }
        if (!$this->request->isGet()) {
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
        if (!in_array(trim($this->request->getRouteUrlPath(), '/'), $white_urls)) {
            ObjectManager::getInstance(Session::class)->setData('backend_login_referer', $this->request->getUrlBuilder()->getCurrentUrl());
        }
    }
}
