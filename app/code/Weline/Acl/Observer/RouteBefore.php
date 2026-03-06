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

use Weline\Acl\Model\WhiteAclSource;
use Weline\Acl\Service\AclService;
use Weline\Acl\Service\AclServiceInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;

class RouteBefore implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var AuthenticatedSessionInterface
     */
    private AuthenticatedSessionInterface $session;
    /**
     * @var \Weline\Acl\Model\WhiteAclSource
     */
    private WhiteAclSource $whiteAclSource;
    /**
     * @var CachePoolInterface
     */
    private CachePoolInterface $aclCache;
    /**
     * @var AclServiceInterface
     */
    private AclServiceInterface $aclService;

    public function __construct(
        WhiteAclSource $whiteAclSource,
        AclService     $aclService
    ) {
        $this->session = SessionFactory::getInstance()->createBackendSession();
        $this->whiteAclSource = $whiteAclSource;
        $this->aclCache = w_cache('acl');
        $this->aclService = $aclService;
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
        
        // HEAD 请求跳过权限检查和重定向逻辑
        // HEAD 请求只是为了获取响应头信息（如 Content-Length），不应该触发业务逻辑重定向
        // 浏览器发起 HEAD 请求通常是为了预检或缓存验证
        if (\strtoupper($request->getMethod()) === 'HEAD') {
            return;
        }

        // 处理后台和后台API请求
        if ($request->isBackend() || $request->isApiBackend()) {$this->validateBackendAccess($request, $event);}
        
        // 处理前端API请求（需要Acl验证的）
        if ($request->isApiFrontend()) {$this->validateFrontendApiAccess($request, $event);}}

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
            // 未定义 ACL 的后台路由按白色 ACL 处理，不做登录/角色/权限校验
            if (!$this->aclService->isRouteProtected($uri)) {
                return;
            }

            // 获取用户和角色（支持多种认证方式）
            $user = null;
            $role = null;
            $access_sources = [];
            
            // 事件中的 user/role 仅由 API 请求时 ApiControllerInitBefore 设置；后台页面请求不设置，走下方 Session 分支。
            $eventUser = $event->getData('user');
            $eventRole = $event->getData('role');
            $eventAccessSources = $event->getData('access_sources');
            
            if ($eventUser) {
                // 使用事件传递的用户（API/第三方认证）；role 优先用事件，否则从用户模型取
                $user = $eventUser;
                $role = $eventRole;
                if (!$role && method_exists($user, 'getRoleModel')) {
                    $role = $user->getRoleModel();
                }
                $access_sources = $eventAccessSources ?? [];
            } else {
                // WLS 兼容：从 SessionFactory 获取当前请求的 BackendSession 实例
                // Observer 实例在 WLS 中是单例，$this->session 可能指向旧请求的 session
                $this->session = SessionFactory::getInstance()->createBackendSession();
                // 使用Session认证（传统方式）
                if ($request->isApiBackend()) {
                    // API后台请求：使用统一的 BackendSession
                    // API Token 认证已由 Api\Observer\ApiControllerInitBefore 处理
                    if ($this->session->isLoggedIn()) {
                        $user = $this->session->getUser();
                    }
                } else {
                    // 后台请求：使用BackendSession
                    if ($this->session->isLoggedIn()) {
                        $user = $this->session->getUser();
                    }
                }
                
                if ($user) {
                    // 检查用户是否有getRoleModel方法（BackendUser）
                    if (method_exists($user, 'getRoleModel')) {
                        $role = $user->getRoleModel();
                    }
                    // WLS 兼容：按当前用户的 role_id 重新加载 Role，避免 ObjectManager 复用导致拿到上一请求的 role（如 role_id=1）
                    // 否则会出现：首次请求误判为超管放行，刷新后才正确拦截
                    if ($role && method_exists($user, 'getRole')) {
                        $roleId = (int) ($user->getRole()->getRoleId() ?: 0);
                        if ($roleId > 0) {
                            $role = ObjectManager::getInstance(\Weline\Acl\Model\Role::class, [], false)->load($roleId);
                        }
                    }
                }
            }
            
            // 如果没有用户，返回未授权（不调用 logout，避免重定向后 Session 未就绪时误清登录态）
            if (!$user) {
                if ($request->isApiBackend()) {
                    $this->returnApiError(401, __('请先登录'), $request);
                    return;
                } else {
                    /**@var EventsManager $eventsManager */
                    $eventsManager = ObjectManager::getInstance(EventsManager::class);
                    $noAccessData = ['data' => ['reason' => 'not_logged_in']];
                    $eventsManager->dispatch('Weline_Acl::no_access_redirect_before', $noAccessData);
                    $request->getResponse()->noRouter(DEV ? 403 : 404);
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
                    $request->getResponse()->noRouter(DEV ? 403 : 404);
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
                    /**@var EventsManager $eventsManager */
                    $eventsManager = ObjectManager::getInstance(EventsManager::class);
                    $noAccessData = ['data' => ['reason' => 'no_role']];
                    $eventsManager->dispatch('Weline_Acl::no_access_redirect_before', $noAccessData);
                    $request->getResponse()->noRouter(DEV ? 403 : 404);
                    return;
                }
            }
            $can_referer = $this->getCanReferer($referer, $request);
            
            // 非超管角色统一通过 AclService 做权限判定
            if ($role->getId() !== 1) {
                $roleId = (int)$role->getId();
                // 没有任何 ACL 权限：直接按“无任何权限”处理
                if (!$this->aclService->hasAnyPermission($roleId)) {
                    if ($request->isApiBackend()) {
                        $this->returnApiError(403, __('你没有任何权限！请联系管理员！'), $request);
                        return;
                    }
                    $this->session->logout();
                    /** @var MessageManager $message */
                    $message = ObjectManager::getInstance(MessageManager::class);
                    $message->addWarning(__('你没有任何权限！请联系管理员！'));
                    /** @var EventsManager $eventsManager */
                    $eventsManager = ObjectManager::getInstance(EventsManager::class);
                    $noAccessData = ['data' => ['reason' => 'no_any_permission']];
                    $eventsManager->dispatch('Weline_Acl::no_access_redirect_before', $noAccessData);
                    $request->getResponse()->noRouter(DEV ? 403 : 404);
                    return;
                }

                // 当前路由是否允许
                $allowed = $this->aclService->isRouteAllowed($roleId, $uri, $request->getMethod());
                if (!$allowed) {
                    // 无权限访问当前路由的处理逻辑维持原有分支语义：返回错误或尝试寻找可跳转入口
                    if ($request->isApiBackend()) {
                        $this->returnApiError(403, __('你无权进行该操作！你不具备：%{1} 操作权限！', [$request->getMethod()]), $request);
                        return;
                    }

                    // 使用现有的 fallback 行为：尝试根据角色权限找到可访问的菜单路由
                    if (empty($access_sources)) {
                        $access_sources = $this->aclService->getRoleAclEntries($roleId);
                    }
                    $this->findAccessUrlRouteToRedirect($request, $access_sources);

                    /** @var EventsManager $eventsManager */
                    $eventsManager = ObjectManager::getInstance(EventsManager::class);
                    $noAccessData = ['data' => ['reason' => 'no_permission_for_route']];
                    $eventsManager->dispatch('Weline_Acl::no_access_redirect_before', $noAccessData);
                    if (!$request->isApiBackend()) {
                        $request->getResponse()->noRouter(DEV ? 403 : 404);
                    }
                    return;
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
        
        // 事件中的 user/role 由 API 请求时 ApiControllerInitBefore 设置；否则走 Session 分支。
        $eventUser = $event->getData('user');
        $eventRole = $event->getData('role');
        $eventAccessSources = $event->getData('access_sources');
        
        if ($eventUser) {
            // 使用事件传递的用户；role 优先用事件，否则从用户模型取
            $user = $eventUser;
            $role = $eventRole;
            if (!$role && method_exists($user, 'getRoleModel')) {
                $role = $user->getRoleModel();
            }
            $access_sources = $eventAccessSources ?? [];
        } else {
            // 使用Session认证（传统方式）
            /** @var AuthenticatedSessionInterface $frontendSession */
            $frontendSession = SessionFactory::getInstance()->createFrontendSession();
            if ($frontendSession->isLoggedIn()) {
                $user = $frontendSession->getUser();
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
     * 使用 ResponseTerminateException 替代 exit()，确保 WLS 兼容
     */
    private function returnApiError(int $code, string $message, Request $request): void
    {
        throw new \Weline\Framework\Http\ResponseTerminateException(
            $code,
            \json_encode(['code' => $code, 'msg' => $message, 'data' => null], JSON_UNESCAPED_UNICODE),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    private function findAccessUrlRouteToRedirect(Request &$request, array &$access_sources)
    {
        // 优先按照严格规则（非 add/edit 等、GET 方法、menus 类型）寻找可跳转入口
        foreach ($access_sources as $access_source) {
            $accessRoute = is_array($access_source) ? ($access_source['route'] ?? '') : ($access_source->getData('route') ?? '');
            $accessMethod = is_array($access_source) ? ($access_source['method'] ?? '') : ($access_source->getData('method') ?? '');
            $accessType = is_array($access_source) ? ($access_source['type'] ?? '') : ($access_source->getData('type') ?? '');
            $route = strtolower($accessRoute);
            $method = strtolower($accessMethod);
            if (($method === 'get' || $method === '') && $route) {
                // 跳过添加、编辑等操作页
                if (!self::canReferer($route)) {
                    continue;
                }
                // 只使用菜单类型作为入口
                if ($accessType !== 'menus') {
                    continue;
                }

                /** @var MessageManager $message */
                $message = ObjectManager::getInstance(MessageManager::class);
                $message->addWarning(__('你无权进行该操作！你不具备：%{1} 路由：%{2} 操作权限！已将你带到你可访问的页面！', [
                    $request->getMethod(),
                    $request->getUri()
                ]));
                // 使用后台 URL 构建器生成正确的后台地址
                $backendUrl = $request->getUrlBuilder()->getBackendUrl($accessRoute);
                $request->getResponse()->redirect($backendUrl);
                return;
            }
        }

        // 严格规则下没有找到入口时，降级为“宽松模式”：只要是 menus 类型且有路由，就拿第一个作为入口
        foreach ($access_sources as $access_source) {
            $accessRoute = is_array($access_source) ? ($access_source['route'] ?? '') : ($access_source->getData('route') ?? '');
            $accessType = is_array($access_source) ? ($access_source['type'] ?? '') : ($access_source->getData('type') ?? '');
            if (!$accessRoute || $accessType !== 'menus') {
                continue;
            }
            $backendUrl = $request->getUrlBuilder()->getBackendUrl($accessRoute);
            /** @var MessageManager $message */
            $message = ObjectManager::getInstance(MessageManager::class);
            $message->addWarning(__('你无权进行该操作！已将你带到你可访问的后台页面：%{1}', [$backendUrl]));
            $request->getResponse()->redirect($backendUrl);
            return;
        }

        // 没有任何 menus 类型的权限，视为“没有可用入口”
        $this->session->logout();
        /**@var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        $noAccessData = ['data' => ['reason' => 'no_usable_permission']];
        $eventsManager->dispatch('Weline_Acl::no_access_redirect_before', $noAccessData);
        $request->getResponse()->noRouter(DEV ? 403 : 404);
    }

    /**
     * @param string $referer
     * @param Request $request
     * @return bool
     */
    private function getCanReferer(string $referer, Request $request): bool
    {
        $can_referer = $referer && ($request->getFullUrl() !== $referer) && $this->session->isLoggedIn();
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
