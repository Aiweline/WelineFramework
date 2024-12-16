<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/20 01:05:29
 */

namespace Weline\Acl\Observer;

use Weline\Acl\Cache\AclCache;
use Weline\Acl\Model\Acl;
use Weline\Acl\Model\WhiteAclSource;
use Weline\Backend\Session\BackendSession;
use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

class RouteBefore implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var \Weline\Backend\Session\BackendSession
     */
    private BackendSession $session;
    /**
     * @var \Weline\Acl\Model\WhiteAclSource
     */
    private WhiteAclSource $whiteAclSource;
    /**
     * @var CacheInterface
     */
    private CacheInterface $aclCache;

    public function __construct(
        BackendSession $session,
        WhiteAclSource $whiteAclSource,
        AclCache       $aclCache
    )
    {
        $this->session = $session;
        $this->whiteAclSource = $whiteAclSource;
        $this->aclCache = $aclCache->create();
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        /**@var \Weline\Framework\Router\Core $route */
        $route = $event->getData('route');
        $request = $route->getRequest();

        if ($request->isBackend() || $request->isApiBackend()) {
            // 绕过白名单URL
            $white_acl_cache_key = 'backend_white_acl_sources';
            $white_lists = $this->aclCache->get($white_acl_cache_key);
            if (empty($white_lists)) {
                $white_lists = $this->whiteAclSource->fields('path')->select()->fetchArray();
                foreach ($white_lists as $key => $white_list) {
                    unset($white_lists[$key]);
                    $white_lists[] = $white_list['path'];
                }
                $this->aclCache->set($white_acl_cache_key, $white_lists);
            }
            // 不在白名单内
            $uri = trim($request->getRouteUrlPath(), '/');
            $referer = $request->getReferer();
            if (str_contains($referer, 'isIframe')) {
                $referer = '';
            }
            if (!in_array(strtolower($uri), $white_lists)) {
                $role = $this->session->getLoginUser()->getRoleModel();
                $can_referer = $this->getCanReferer($referer, $request);
                // 是否具有角色
                if (empty($role->getId())) {
                    $this->session->logout();
                    /**@var EventsManager $event */
                    $event = ObjectManager::getInstance(EventsManager::class);
                    $event->dispatch('Weline_Acl::no_access_redirect_before');
                    $request->_response->noRouter(DEV ? 403 : 404);
                }
                // 非超管
                if ($role->getId() !== 1) {
                    // 已有的权限
//                    $access_sources = $this->session->getData('access_sources');
//                    if (empty($access_sources)) {
//                        // 检测角色中是否有此路由
//                        $access_sources = $role->getAccess();
//                        /**@var \Weline\Acl\Model\RoleAccess $access_source */
//                        foreach ($access_sources as $key => $access_source) {
//                            unset($access_sources[$key]);
//                            $access_sources[] = $access_source->getData();
//                        }
//                        $this->session->setData('access_sources', $access_sources);
//                    }
                    // 检测角色中是否有此路由
                    $access_sources = $role->getAccess();
                    /**@var \Weline\Acl\Model\RoleAccess $access_source */
                    foreach ($access_sources as $key => $access_source) {
                        unset($access_sources[$key]);
                        if ($access_source['route']) {
                            $access_source['route'] = trim($access_source['route'], '/-');
                        }
                        $access_sources[] = $access_source;
                    }
                    // 没有任何权限的后台用户404，等待超管给权限，否则后台都没办法进入
                    if (empty($access_sources)) {
                        $this->session->logout();
                        /**@var MessageManager $message */
                        $message = ObjectManager::getInstance(MessageManager::class);
                        $message->addWarning(__('你没有任何权限！请联系管理员！'));
                        /**@var EventsManager $event */
                        $event = ObjectManager::getInstance(EventsManager::class);
                        $event->dispatch('Weline_Acl::no_access_redirect_before');
                        $request->_response->noRouter(DEV ? 403 : 404);
                    }
                    // 已有的权限中检测
                    $has_access = false;
                    foreach ($access_sources as $access_source) {
                        // 路由匹配
                        if ($uri === $access_source['route']) {
                            // 方法匹配
                            if ($access_source['method']) {
                                if ($request->getMethod() === $access_source['method']) {
                                    $has_access = true;
                                    // 再判断已有的权限中是否拥有
                                    break;
                                }
                            } else {
                                $has_access = true;
                                break;
                            }
                        }
                    }
                    // 检测没有权限的情况下是否该路由存在于acl系统控制中
                    if (!$has_access) {
                        // 读取所有资源路径
                        $all_acl_cache_key = 'backend_all_acl_sources';
                        $acl_sources = $this->aclCache->get($all_acl_cache_key);
                        if (empty($acl_sources)) {
                            /**@var Acl $aclModel */
                            $aclModel = ObjectManager::getInstance(Acl::class);
                            $acl_sources = $aclModel->select()->fetchArray();
                            $this->aclCache->set($all_acl_cache_key, $acl_sources);
                        }
                        foreach ($acl_sources as $acl_source) {
                            // 路由匹配
                            if ($uri === $acl_source['route']) {
                                // 方法匹配
                                if ($acl_source['method']) {
                                    if ($request->getMethod() === $acl_source['method']) {
                                        if ($can_referer) {
                                            // 判断referer是否可跳转，解决无限重定向问题
                                            $referer_in_access = false;
                                            foreach ($access_sources as $access_source) {
                                                if ($access_source['route'] === $request->getUrlPath($referer)) {
                                                    $referer_in_access = true;
                                                    break;
                                                }
                                            }
                                            if ($referer_in_access) {
                                                $can_referer = true;
                                            } else {
                                                $can_referer = false;
                                            }
                                        }
                                        // 没有权限又存在于acl控制列表中
                                        if ($can_referer) {
                                            /**@var MessageManager $message */
                                            $message = ObjectManager::getInstance(MessageManager::class);
                                            $message->addWarning(__('你无权进行该操作！已将你带回来源网址！你不具备：%1 操作权限！', $request->getMethod()));
                                            $request->_response->redirect($referer);
                                        } else {
                                            // 找一个有权限的get请求路由访问
                                            $this->findAccessUrlRouteToRedirect($request, $access_sources);
                                        }
                                        /**@var EventsManager $event */
                                        $event = ObjectManager::getInstance(EventsManager::class);
                                        $event->dispatch('Weline_Acl::no_access_redirect_before');
                                        $request->_response->noRouter(DEV ? 403 : 404);
                                    } else {
                                        // 找一个有权限的get请求路由访问
                                        $this->findAccessUrlRouteToRedirect($request, $access_sources);
                                    }
                                } else {
                                    if ($can_referer) {
                                        // 判断referer是否可跳转，解决无限重定向问题
                                        $referer_in_access = false;
                                        foreach ($access_sources as $access_source) {
                                            if ($access_source['route'] === $request->getUrlPath($referer)) {
                                                $referer_in_access = true;
                                                break;
                                            }
                                        }
                                        if ($referer_in_access) {
                                            $can_referer = true;
                                        } else {
                                            $can_referer = false;
                                        }
                                    }
                                    // 没有权限又存在于acl控制列表中
                                    if ($can_referer) {
                                        /**@var MessageManager $message */
                                        $message = ObjectManager::getInstance(MessageManager::class);
                                        $message->addWarning(__('你无权进行该操作！已将你带回来源网址！'));
                                        $request->_response->redirect($referer);
                                    } else {
                                        // 找一个有权限的get请求路由访问
                                        $this->findAccessUrlRouteToRedirect($request, $access_sources);
                                    }
                                    /**@var EventsManager $event */
                                    $event = ObjectManager::getInstance(EventsManager::class);
                                    $event->dispatch('Weline_Acl::no_access_redirect_before');
                                    $request->_response->noRouter(DEV ? 403 : 404);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    private function findAccessUrlRouteToRedirect(Request &$request, array &$access_sources)
    {
        foreach ($access_sources as $access_source) {
            $route = strtolower($access_source['route']??'');
            $method = strtolower($access_source['method']??'');
            if (($method === 'get' || $method === '') && $route) {
                # 跳过添加和编辑页面
                if (!self::canReferer($route)){
                    continue;
                }
                # 跳过非PC
                if ($access_source['type'] !== 'pc') {
                    continue;
                }
                if (($method === 'get' || $method === '') && $route) {
                    /**@var MessageManager $message */
                    $message = ObjectManager::getInstance(MessageManager::class);
                    $message->addWarning(__('你无权进行该操作！你不具备：%1 路由：%2 操作权限！已将你带到你可访问的页面！', [
                        $request->getMethod(),
                        $request->getUri()
                    ]));
                    $request->_response->redirect($request->getPrePath() . trim($access_source['route'], '/'));
                }
            }
        }
        // 没有任何可使用权限
        $this->session->logout();
        /**@var EventsManager $event */
        $event = ObjectManager::getInstance(EventsManager::class);
        $event->dispatch('Weline_Acl::no_access_redirect_before');
        $request->_response->noRouter(DEV ? 403 : 404);
    }

    /**
     * @param string $referer
     * @param Request $request
     * @return bool
     */
    private function getCanReferer(string $referer, Request $request): bool
    {
        $can_referer = $referer && ($request->getFullUrl() !== $referer) && $this->session->isLogin();
        # 跳过添加和编辑页面
        if (!self::canReferer($referer)) {
            $can_referer = false;
        }
        return $can_referer;
    }

    private static function canReferer(string $referer): bool
    {
        if (str_contains($referer, 'add') or str_contains($referer, 'edit') or str_contains($referer, 'download')
            or str_contains($referer, 'upload') or str_contains($referer, 'export') or str_contains($referer, 'import')
            or str_contains($referer, 'delete') or str_contains($referer, 'batch')
        ) {
            return false;
        }
        return true;
    }
}
