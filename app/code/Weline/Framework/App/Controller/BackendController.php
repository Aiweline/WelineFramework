<?php

declare(strict_types=1);

namespace Weline\Framework\App\Controller;

use Weline\Framework\Cache\Pool\CachePool;
use Weline\Framework\Controller\PcController;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

/**
 * 后台控制器基类
 *
 * 提供后台页面的通用功能：
 * - 登录状态检查
 * - Session 管理（通过 AuthenticatedSession）
 * - 缓存管理
 * - 布局配置
 */
class BackendController extends PcController
{
    protected CachePool $cache;
    
    /** 认证 Session（使用新架构） */
    protected AuthenticatedSessionInterface $session;
    
    /**
     * 后端默认使用 default.default 布局，自动加载主题变量 CSS
     * 格式：布局类型.布局选项（如 'default.default', 'dashboard.default', 'login.default'）
     * 子类可覆盖此属性以使用其他布局或设为 null 禁用布局
     */
    protected ?string $layoutType = 'default.default';

    public function __init()
    {
        $this->getEventManager()->dispatch('Weline_Framework_App::backend_controller_init_before');
        $this->cache = $this->getControllerCache();
        
        if (!isset($this->session)) {
            $this->session = SessionFactory::getInstance()->createBackendSession();
        }
        // 尽早初始化底层 Session（读 Cookie 或生成新 ID，并加入 flush 队列），避免后续 302 前 flush 时尚未 start 导致落库遗漏
        $this->session->start(null);

        parent::__init();
        
        $response = $this->request->getResponse();
        $response->setHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        
        $this->getEventManager()->dispatch('Weline_Framework_App::backend_controller_init_after');
        $this->loginCheck();
    }

    protected function loginCheck(): void
    {
        $isHttpRequest = !CLI || isset($_SERVER['REQUEST_URI']);
        $sessionIsLogin = $this->session->isLoggedIn();
        
        if ($isHttpRequest && !$sessionIsLogin) {
            $whitelist_url_cache_key = 'whitelist_url_cache_key';
            $whitelist_url = $this->cache->get($whitelist_url_cache_key);
            
            if (!$whitelist_url) {
                /** @var EventsManager $evenManager */
                $evenManager = ObjectManager::getInstance(EventsManager::class);
                $whitelistUrlData = new DataObject(['whitelist_url' => []]);
                $evenManager->dispatch('Weline_Framework_Router::backend_whitelist_url', $whitelistUrlData);
                $whitelist_url = $whitelistUrlData->getData('whitelist_url');
                $this->cache->set($whitelist_url_cache_key, $whitelist_url);
            }
            
            $routeUrlPath = $this->request->getRouteUrlPath();
            
            if (!\in_array($routeUrlPath, $whitelist_url, true)) {
                $no_login_url_cache_key = 'no_login_redirect_url';
                $no_login_redirect_url = $this->cache->get($no_login_url_cache_key);
                
                if (!$no_login_redirect_url) {
                    /** @var EventsManager $evenManager */
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

    /**
     * 获取当前登录用户
     *
     * @return \Weline\Framework\Session\Auth\AuthenticableInterface|null
     */
    protected function getLoginUser(): ?\Weline\Framework\Session\Auth\AuthenticableInterface
    {
        return $this->session->getUser();
    }

    /**
     * 获取当前登录用户 ID
     *
     * @return int|string|null
     */
    protected function getLoginUserId(): int|string|null
    {
        return $this->session->getUserId();
    }

    /**
     * 获取当前登录用户名
     *
     * @return string|null
     */
    protected function getLoginUsername(): ?string
    {
        return $this->session->getUsername();
    }

    /**
     * 检查是否已登录
     *
     * @return bool
     */
    protected function isLoggedIn(): bool
    {
        return $this->session->isLoggedIn();
    }
}
