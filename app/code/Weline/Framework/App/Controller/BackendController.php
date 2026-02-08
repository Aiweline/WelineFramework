<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\App\Controller;

use Weline\Framework\App\Session\BackendSession;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;

class BackendController extends PcController
{
    protected CacheInterface $cache;
    protected BackendSession $session;

    public function __init()
    {
        $this->getEventManager()->dispatch('Weline_Framework_App::backend_controller_init_before');
        $this->cache = $this->getControllerCache();
        if (!isset($this->session)) {
            $this->session = ObjectManager::getInstance(BackendSession::class);
        }
        parent::__init();
        $this->getEventManager()->dispatch('Weline_Framework_App::backend_controller_init_after');
        $this->loginCheck();
    }

    protected function loginCheck(): void
    {
        # 验证除了登录页面以外的所有地址需要登录
        # WLS 模式下 CLI=true 但有 HTTP 请求环境（REQUEST_URI），仍需登录验证
        $isHttpRequest = !CLI || isset($_SERVER['REQUEST_URI']);
        if ($isHttpRequest and !$this->session->isLogin()) {
            $whitelist_url_cache_key = 'whitelist_url_cache_key';
            $whitelist_url = $this->cache->get($whitelist_url_cache_key);
            if (!$whitelist_url) {
                /**@var EventsManager $evenManager */
                $evenManager = ObjectManager::getInstance(EventsManager::class);
                $whitelistUrlData = new DataObject(['whitelist_url' => []]);
                $evenManager->dispatch('Weline_Framework_Router::backend_whitelist_url', $whitelistUrlData);
                $whitelist_url = $whitelistUrlData->getData('whitelist_url');
                $this->cache->set($whitelist_url_cache_key, $whitelist_url);
            }
            
            // getRouteUrlPath() 现在正确返回 admin/login 格式，可以直接与白名单匹配
            $routeUrlPath = $this->request->getRouteUrlPath();
            
            // 检查是否在白名单中
            if (!in_array($routeUrlPath, $whitelist_url)) {
                $no_login_url_cache_key = 'no_login_redirect_url';
                $no_login_redirect_url = $this->cache->get($no_login_url_cache_key);
                if (!$no_login_redirect_url) {
                    /**@var EventsManager $evenManager */
                    $evenManager = ObjectManager::getInstance(EventsManager::class);
                    $noLoginRedirectUrl = new DataObject(['no_login_redirect_url' => []]);
                    $evenManager->dispatch('Weline_Framework_Router::backend_no_login_redirect_url', $noLoginRedirectUrl);
                    $no_login_redirect_url = $noLoginRedirectUrl->getData('no_login_redirect_url');
                    $this->cache->set($no_login_url_cache_key, $this->_url->getUri($no_login_redirect_url));
                }
                if ($no_login_redirect_url) {
                    $this->redirect($no_login_redirect_url);
                }
                $this->noRouter();
            }
        }
    }
}
