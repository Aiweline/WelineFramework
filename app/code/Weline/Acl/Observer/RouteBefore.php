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
    public function execute(Event &$event): void
    {
        // 从事件中获取 route 对象
        // 事件数据格式：['route' => $routeObject]
        // 由于 Event 类将数据直接存储在 _data 中（而不是 _data['data']），
        // 需要直接从事件数据中获取 route
        $route = $event->getData('route');
        
        // 如果 getData('route') 返回的是数组，可能是整个事件数据
        // 尝试从事件数据的 'route' 键获取
        if (is_array($route)) {
            if (isset($route['route']) && is_object($route['route'])) {
                $route = $route['route'];
            } else {
                // 尝试从事件的所有数据中获取 route（Event 的 _data 直接包含 route）
                $allData = $event->getData();
                if (is_array($allData)) {
                    if (isset($allData['route']) && is_object($allData['route'])) {
                        $route = $allData['route'];
                    } elseif (isset($allData['data']['route']) && is_object($allData['data']['route'])) {
                        // 如果数据存储在 data 键下
                        $route = $allData['data']['route'];
                    } else {
                        // 如果还是数组，说明事件数据格式有问题，直接返回
                        return;
                    }
                } else {
                    return;
                }
            }
        }
        
        // 确保 route 是对象且具有 getRequest 方法
        if (!is_object($route) || !method_exists($route, 'getRequest')) {
            return;
        }
        
        $request = $route->getRequest();

        // 处理后台和后台API请求
        if ($request->isBackend() || $request->isApiBackend()) {
            $this->validateBackendAccess($request, $event);
        }
        
        // 处理前端API请求（需要Acl验证的）
        if ($request->isApiFrontend()) {
            $this->validateFrontendApiAccess($request, $event);
        }
    }

    /**
     * 验证后台访问权限（包括后台和后台API）
     */
    private function validateBackendAccess(Request $request, Event &$event): void
    {
        // 绕过白名单URL（只读取PC类型的白名单）
        $white_acl_cache_key = 'backend_white_acl_sources';
        $white_lists = $this->aclCache->get($white_acl_cache_key);
        if (empty($white_lists)) {
            $white_lists = $this->whiteAclSource
                ->fields('path')
                ->where('type', \Weline\Acl\Model\WhiteAclSource::type_PC)
                ->select()
                ->fetchArray();
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
            // 获取用户和角色（支持多种认证方式）
            $user = null;
            $role = null;
            $access_sources = [];
            
            // 尝试从事件中获取用户权限和角色（用于第三方认证）
            $eventUser = $event->getData('user');
            $eventRole = $event->getData('role');
            $eventAccessSources = $event->getData('access_sources');
            
            if ($eventUser && $eventRole) {
                // 使用事件传递的用户和角色（第三方认证）
                $user = $eventUser;
                $role = $eventRole;
                $access_sources = $eventAccessSources ?? [];
            } else {
                // 使用Session认证（传统方式）
                if ($request->isApiBackend()) {
                    // API请求：优先使用BackendApiSession，其次BackendSession
                    $backendApiSession = ObjectManager::getInstance(\Weline\Framework\App\Session\BackendApiSession::class);
                    if ($backendApiSession->isLogin()) {
                        $user = $backendApiSession->getApiUser();
                    } elseif ($this->session->isLogin()) {
                        $user = $this->session->getLoginUser();
                    }
                } else {
                    // 后台请求：使用BackendSession
                    if ($this->session->isLogin()) {
                        $user = $this->session->getLoginUser();
                    }
                }
                
                if ($user) {
                    // 检查用户是否有getRoleModel方法（BackendUser）
                    if (method_exists($user, 'getRoleModel')) {
                        $role = $user->getRoleModel();
                    }
                }
            }
            
            // 如果没有用户，返回未授权
            if (!$user) {
                if ($request->isApiBackend()) {
                    $this->returnApiError(401, __('请先登录'), $request);
                    return;
                } else {
                    $this->session->logout();
                    /**@var EventsManager $event */
                    $event = ObjectManager::getInstance(EventsManager::class);
                    $event->dispatch('Weline_Acl::no_access_redirect_before');
                    $request->_response->noRouter(DEV ? 403 : 404);
                    return;
                }
            }
            
            // 检查用户状态
            if (method_exists($user, 'getIsEnabled') && !$user->getIsEnabled()) {
                if ($request->isApiBackend()) {
                    $this->returnApiError(403, __('用户已被禁用'), $request);
                    return;
                } else {
                    $this->session->logout();
                    $request->_response->noRouter(DEV ? 403 : 404);
                    return;
                }
            }
            
            // 如果没有角色，返回无权限
            if (!$role || !$role->getId()) {
                if ($request->isApiBackend()) {
                    $this->returnApiError(403, __('用户没有分配角色'), $request);
                    return;
                } else {
                    $this->session->logout();
                    /**@var EventsManager $event */
                    $event = ObjectManager::getInstance(EventsManager::class);
                    $event->dispatch('Weline_Acl::no_access_redirect_before');
                    $request->_response->noRouter(DEV ? 403 : 404);
                    return;
                }
            }
            $can_referer = $this->getCanReferer($referer, $request);
            
            // 非超管需要验证权限
            if ($role->getId() !== 1) {
                // 如果事件中没有传递权限列表，从角色中获取
                if (empty($access_sources)) {
                    // 检测角色中是否有此路由
                    $access_sources = $role->getAccess();
                    /**@var \Weline\Acl\Model\RoleAccess $access_source */
                    foreach ($access_sources as $key => $access_source) {
                        unset($access_sources[$key]);
                        if (is_array($access_source) && isset($access_source['route'])) {
                            $access_source['route'] = trim($access_source['route'], '/-');
                        } elseif (is_object($access_source) && method_exists($access_source, 'getData')) {
                            $access_source = $access_source->getData();
                            if (isset($access_source['route'])) {
                                $access_source['route'] = trim($access_source['route'], '/-');
                            }
                        }
                        $access_sources[] = $access_source;
                    }
                }
                // 没有任何权限的后台用户404，等待超管给权限，否则后台都没办法进入
                if (empty($access_sources)) {
                    if ($request->isApiBackend()) {
                        $this->returnApiError(403, __('你没有任何权限！请联系管理员！'), $request);
                        return;
                    } else {
                        $this->session->logout();
                        /**@var MessageManager $message */
                        $message = ObjectManager::getInstance(MessageManager::class);
                        $message->addWarning(__('你没有任何权限！请联系管理员！'));
                        /**@var EventsManager $event */
                        $event = ObjectManager::getInstance(EventsManager::class);
                        $event->dispatch('Weline_Acl::no_access_redirect_before');
                        $request->_response->noRouter(DEV ? 403 : 404);
                        return;
                    }
                }
                // 已有的权限中检测
                $has_access = false;
                foreach ($access_sources as $access_source) {
                    $accessRoute = is_array($access_source) ? ($access_source['route'] ?? '') : ($access_source->getData('route') ?? '');
                    $accessMethod = is_array($access_source) ? ($access_source['method'] ?? '') : ($access_source->getData('method') ?? '');
                    
                    // 路由匹配
                    if ($uri === $accessRoute) {
                        // 方法匹配
                        if ($accessMethod) {
                            if ($request->getMethod() === $accessMethod) {
                                $has_access = true;
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
                                        foreach ($access_sources as $access_source_item) {
                                            $accessRoute = is_array($access_source_item) ? ($access_source_item['route'] ?? '') : ($access_source_item->getData('route') ?? '');
                                            if ($accessRoute === $request->getUrlPath($referer)) {
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
                                        if ($request->isApiBackend()) {
                                            $this->returnApiError(403, __('你无权进行该操作！你不具备：%{1} 操作权限！', [$request->getMethod()]), $request);
                                            return;
                                        } else {
                                            /**@var MessageManager $message */
                                            $message = ObjectManager::getInstance(MessageManager::class);
                                            $message->addWarning(__('你无权进行该操作！已将你带回来源网址！你不具备：%{1} 操作权限！', $request->getMethod()));
                                            $request->_response->redirect($referer);
                                        }
                                    } else {
                                        // 找一个有权限的get请求路由访问
                                        if ($request->isApiBackend()) {
                                            $this->returnApiError(403, __('你无权进行该操作！你不具备：%{1} 操作权限！', [$request->getMethod()]), $request);
                                            return;
                                        } else {
                                            $this->findAccessUrlRouteToRedirect($request, $access_sources);
                                        }
                                    }
                                    /**@var EventsManager $event */
                                    $event = ObjectManager::getInstance(EventsManager::class);
                                    $event->dispatch('Weline_Acl::no_access_redirect_before');
                                    if (!$request->isApiBackend()) {
                                        $request->_response->noRouter(DEV ? 403 : 404);
                                    }
                                    return;
                                } else {
                                    if ($request->isApiBackend()) {
                                        $this->returnApiError(403, __('你无权进行该操作！你不具备：%{1} 操作权限！', [$request->getMethod()]), $request);
                                        return;
                                    } else {
                                        // 找一个有权限的get请求路由访问
                                        $this->findAccessUrlRouteToRedirect($request, $access_sources);
                                    }
                                }
                            } else {
                                if ($can_referer) {
                                    // 判断referer是否可跳转，解决无限重定向问题
                                    $referer_in_access = false;
                                    foreach ($access_sources as $access_source) {
                                        $accessRoute = is_array($access_source) ? ($access_source['route'] ?? '') : ($access_source->getData('route') ?? '');
                                        if ($accessRoute === $request->getUrlPath($referer)) {
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
                                    if ($request->isApiBackend()) {
                                        $this->returnApiError(403, __('你无权进行该操作！'), $request);
                                        return;
                                    } else {
                                        /**@var MessageManager $message */
                                        $message = ObjectManager::getInstance(MessageManager::class);
                                        $message->addWarning(__('你无权进行该操作！已将你带回来源网址！'));
                                        $request->_response->redirect($referer);
                                    }
                                } else {
                                    // 找一个有权限的get请求路由访问
                                    if ($request->isApiBackend()) {
                                        $this->returnApiError(403, __('你无权进行该操作！'), $request);
                                        return;
                                    } else {
                                        $this->findAccessUrlRouteToRedirect($request, $access_sources);
                                    }
                                }
                                /**@var EventsManager $event */
                                $event = ObjectManager::getInstance(EventsManager::class);
                                $event->dispatch('Weline_Acl::no_access_redirect_before');
                                if (!$request->isApiBackend()) {
                                    $request->_response->noRouter(DEV ? 403 : 404);
                                }
                                return;
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * 验证前端API访问权限
     * 
     * 支持第三方认证：从事件中获取用户、角色和权限
     */
    private function validateFrontendApiAccess(Request $request, Event &$event): void
    {
        // 绕过白名单URL（从数据库读取API类型的白名单）
        $white_acl_cache_key = 'frontend_api_white_acl_sources';
        $white_lists = $this->aclCache->get($white_acl_cache_key);
        if (empty($white_lists)) {
            $white_lists = $this->whiteAclSource
                ->fields('path')
                ->where('type', \Weline\Acl\Model\WhiteAclSource::type_API)
                ->select()
                ->fetchArray();
            foreach ($white_lists as $key => $white_list) {
                unset($white_lists[$key]);
                $white_lists[] = $white_list['path'];
            }
            $this->aclCache->set($white_acl_cache_key, $white_lists);
        }
        
        // 检查是否在白名单内
        $uri = trim($request->getRouteUrlPath(), '/');

        if (in_array(strtolower($uri), $white_lists)) {
            // 在白名单内，跳过登录验证
            return;
        }
        
        // 获取用户和角色（支持多种认证方式）
        $user = null;
        $role = null;
        $access_sources = [];
        
        // 尝试从事件中获取用户权限和角色（用于第三方认证）
        $eventUser = $event->getData('user');
        $eventRole = $event->getData('role');
        $eventAccessSources = $event->getData('access_sources');
        
        if ($eventUser && $eventRole) {
            // 使用事件传递的用户和角色（第三方认证）
            $user = $eventUser;
            $role = $eventRole;
            $access_sources = $eventAccessSources ?? [];
        } else {
            // 使用Session认证（传统方式）
            /** @var \Weline\Frontend\Session\FrontendUserSession $frontendSession */
            $frontendSession = ObjectManager::getInstance(\Weline\Frontend\Session\FrontendUserSession::class);
            if ($frontendSession->isLogin()) {
                $user = $frontendSession->getLoginUser();
                // 前端用户可能没有角色，这里可以根据需要实现
            }
        }
        
        // 如果没有用户，返回未授权
        if (!$user) {
            $this->returnApiError(401, __('请先登录'), $request);
            return;
        }
        
        // 检查用户状态
        if (method_exists($user, 'getIsEnabled') && !$user->getIsEnabled()) {
            $this->returnApiError(403, __('用户已被禁用'), $request);
            return;
        }
        
        // 如果事件中没有传递权限列表，且用户有角色，从角色中获取
        if (empty($access_sources) && $role && $role->getId()) {
            $access_sources = $role->getAccess();
        }
        
        // 前端API通常不需要Acl验证，只需要登录验证
        // 如果需要Acl验证，可以在这里实现类似后台API的逻辑
        // 目前前端API的Acl验证由ApiControllerInitBefore Observer处理
    }

    /**
     * 返回API错误响应
     */
    private function returnApiError(int $code, string $message, Request $request): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'code' => $code,
            'msg' => $message,
            'data' => null
        ]);
        exit;
    }

    private function findAccessUrlRouteToRedirect(Request &$request, array &$access_sources)
    {
        foreach ($access_sources as $access_source) {
            $accessRoute = is_array($access_source) ? ($access_source['route'] ?? '') : ($access_source->getData('route') ?? '');
            $accessMethod = is_array($access_source) ? ($access_source['method'] ?? '') : ($access_source->getData('method') ?? '');
            $accessType = is_array($access_source) ? ($access_source['type'] ?? '') : ($access_source->getData('type') ?? '');
            $route = strtolower($accessRoute);
            $method = strtolower($accessMethod);
            if (($method === 'get' || $method === '') && $route) {
                # 跳过添加和编辑页面
                if (!self::canReferer($route)) {
                    continue;
                }
                # 跳过非PC
                if ($accessType !== 'menus') {
                    continue;
                }
                if (($method === 'get' || $method === '') && $route) {
                    /**@var MessageManager $message */
                    $message = ObjectManager::getInstance(MessageManager::class);
                    $message->addWarning(__('你无权进行该操作！你不具备：%{1} 路由：%{2} 操作权限！已将你带到你可访问的页面！', [
                        $request->getMethod(),
                        $request->getUri()
                    ]));
                    // 使用后台 URL 构建器生成正确的后台地址
                    $backendUrl = $request->getUrlBuilder()->getBackendUrl($accessRoute);
                    $request->_response->redirect($backendUrl);
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
