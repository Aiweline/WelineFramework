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
use Weline\Backend\Model\BackendUser;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestLifecycleTrace;
use Weline\Framework\Runtime\StateManager;
use Weline\Framework\Session\Strategy\WlsStrategy;

class RouteBefore implements \Weline\Framework\Event\ObserverInterface
{
    private const OBSERVER_SPAN_NAME = 'observer::Weline::Acl::Observer::RouteBefore';
    /** 请求级白名单缓存，同请求内避免重复读 cache/DB，WLS 下由 StateManager 重置 */
    private static array|false $backendWhiteListRequestCache = false;
    private static array|false $frontendWhiteListRequestCache = false;
    /** 请求级后台 Session 缓存，WLS 下由 StateManager 重置，避免跨请求复用 */
    private static ?AuthenticatedSessionInterface $backendSessionRequestCache = null;
    private static bool $stateManagerRegistered = false;

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
        $this->whiteAclSource = $whiteAclSource;
        $this->aclCache = w_cache('acl');
        $this->aclService = $aclService;
    }

    /** 获取当前请求的后台 Session，延迟创建；同请求内复用，WLS 下由 StateManager 在请求结束时清空 */
    private function getBackendSession(): AuthenticatedSessionInterface
    {
        if (self::$backendSessionRequestCache === null) {
            self::registerStateManager();
            self::$backendSessionRequestCache = SessionFactory::getInstance()->createBackendSession();
        }
        return self::$backendSessionRequestCache;
    }

    /**
     * logout 后彻底销毁 Session（storage 删除 + 清除 cookie），确保下一请求到 login 时 isLoggedIn() 为 false，避免登录页再跳回 listing 形成循环。
     * 仅 save+writeClose 在某些环境（如 WLS）下下一请求仍读到旧数据，故改为 destroy。
     */
    private function persistBackendSessionAfterLogout(): void
    {
        $backendSession = $this->getBackendSession();
        $backendSession->getSession()->destroy();
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
    private static function registerStateManager(): void
    {
        if (self::$stateManagerRegistered) {
            return;
        }
        if (class_exists(StateManager::class)) {
            StateManager::registerResetCallback('RouteBefore', [self::class, 'resetRequestCache']);
            self::$stateManagerRegistered = true;
        }
    }

    /** WLS 请求结束后清空请求级缓存（白名单 + 后台 Session） */
    public static function resetRequestCache(): void
    {
        self::$backendWhiteListRequestCache = false;
        self::$frontendWhiteListRequestCache = false;
        self::$backendSessionRequestCache = null;
    }

    private function getBackendWhiteList(): array
    {
        self::registerStateManager();
        if (self::$backendWhiteListRequestCache !== false) {
            return self::$backendWhiteListRequestCache;
        }
        $white_acl_cache_key = 'backend_white_acl_sources';
        $white_lists = $this->aclCache->get($white_acl_cache_key);
        if (empty($white_lists)) {
            $white_lists = $this->whiteAclSource
                ->fields('path')
                ->where('type', \Weline\Acl\Model\WhiteAclSource::type_PC)
                ->select()
                ->fetchArray();
            $paths = [];
            foreach ($white_lists as $white_list) {
                $paths[] = $white_list['path'];
            }
            $white_lists = $paths;
            $this->aclCache->set($white_acl_cache_key, $white_lists);
        }
        self::$backendWhiteListRequestCache = $white_lists;
        return $white_lists;
    }

    private function validateBackendAccess(Request $request, Event &$event): void
    {
        $parent = self::OBSERVER_SPAN_NAME;

        $t0 = microtime(true);
        $white_lists = $this->getBackendWhiteList();
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::recordSpan('acl::RouteBefore::whiteList', (microtime(true) - $t0) * 1000, 'observer', $parent);
        }

        // 不在白名单内
        $uri = trim($request->getRouteUrlPath(), '/');
        $referer = $request->getReferer();
        if (str_contains($referer, 'isIframe')) {
            $referer = '';
        }

        if (in_array(strtolower($uri), $white_lists)) {
            w_auth_log('acl_whitelist', 'URI 在白名单内，跳过权限校验', ['uri' => $uri]);
            return;
        }

        $t0 = microtime(true);
        $routeProtected = $this->aclService->isRouteProtected($uri);
        if (RequestLifecycleTrace::isEnabled()) {
            RequestLifecycleTrace::recordSpan('acl::RouteBefore::isRouteProtected', (microtime(true) - $t0) * 1000, 'observer', $parent);
        }
        // 未定义 ACL 的后台路由按白色 ACL 处理，不做登录/角色/权限校验
        if (!$routeProtected) {
            w_auth_log('acl_not_protected', '路由未受 ACL 保护，跳过校验', ['uri' => $uri]);
            return;
        }

        // CLI 下无浏览器 Session，不创建 getBackendSession()、不查 getAclContext()，避免几百毫秒
        if (\PHP_SAPI === 'cli') {
            $user = null;
            $role = null;
            $sessionAclContext = null;
            $access_sources = [];
            $roleId = 0;
            // 后续 hasUser 为 false，走未授权分支；需要带登录态时由调用方通过 event 传入 user/role
        } else {
            // 获取用户和角色（支持多种认证方式）
            $user = null;
            $role = null;
            /** @var array{user_id: int, role_id: int, is_enabled: int}|null 仅 session 分支轻量查询时非空 */
            $sessionAclContext = null;
            $access_sources = [];
            $eventUser = $event->getData('user');
            $eventRole = $event->getData('role');
            $eventAccessSources = $event->getData('access_sources');

            $t0 = microtime(true);
            if ($eventUser) {
                $user = $eventUser;
                $role = $eventRole;
                if (!$role && method_exists($user, 'getRoleModel')) {
                    $role = call_user_func([$user, 'getRoleModel']);
                }
                $access_sources = $eventAccessSources ?? [];
            } else {
                // Session 分支：精确定位耗时。读 var/session 小文件本身不会几百毫秒；若仍慢，多为：
                // - WLS 下 getBackendSession() 首次创建 WlsSharedStorage 并 sessionClient->connect() 建连 Session Server（TCP）
                // - 或 getAclContext() 的 2 次 DB
                $backendSession = $this->getBackendSession();
                if (RequestLifecycleTrace::isEnabled()) {
                    RequestLifecycleTrace::recordSpan('acl::RouteBefore::sessionCreate', (microtime(true) - $t0) * 1000, 'observer', $parent);
                }
                $userId = $backendSession->getUserId();
                if (RequestLifecycleTrace::isEnabled()) {
                    RequestLifecycleTrace::recordSpan('acl::RouteBefore::sessionGetUserId', (microtime(true) - $t0) * 1000, 'observer', $parent);
                }
                $tAcl = microtime(true);
                $fromCache = false;
                if ($userId !== null && $userId !== '') {
                    $rawSession = $backendSession->getSession();
                    $cachedRoleId = $rawSession->get('backend_acl_role_id');
                    $cachedIsEnabled = $rawSession->get('backend_acl_is_enabled');
                    if ($cachedRoleId !== null && $cachedIsEnabled !== null) {
                        $roleId = (int) $cachedRoleId;
                        $sessionAclContext = [
                            'user_id' => (int) $userId,
                            'role_id' => $roleId,
                            'is_enabled' => (int) $cachedIsEnabled,
                        ];
                        $fromCache = true;
                    } else {
                        try {
                            $sessionAclContext = BackendUser::getAclContext((int) $userId);
                        } catch (\Throwable $e) {
                            $sessionAclContext = null;
                        }
                        if ($sessionAclContext !== null) {
                            $roleId = $sessionAclContext['role_id'];
                            $rawSession->set('backend_acl_role_id', $roleId);
                            $rawSession->set('backend_acl_is_enabled', $sessionAclContext['is_enabled']);
                        } else {
                            // getAclContext 返回 null = 用户不存在或被删除（如线上 backend_user 无该 id），与“未分配角色”区分
                            $roleId = 0;
                            $sessionAclContext = ['user_id' => (int) $userId, 'role_id' => 0, 'is_enabled' => 1, '_user_not_found' => true];
                        }
                    }
                } else {
                    $roleId = 0;
                }
                w_auth_log('acl_session_context', 'Session 分支获取用户/角色与 ACL 上下文', [
                    'uri' => $uri,
                    'userId' => $userId,
                    'roleId' => $roleId ?? 0,
                    'from_cache' => $fromCache ?? false,
                    '_user_not_found' => (bool) ($sessionAclContext['_user_not_found'] ?? false),
                ]);
                if (RequestLifecycleTrace::isEnabled()) {
                    RequestLifecycleTrace::recordSpan('acl::RouteBefore::aclContext', (microtime(true) - $tAcl) * 1000, 'observer', $parent);
                }
            }
            // 事件分支未设置 roleId，此处统一补全（仅用 user/role 取值，不 load Role）
            if (!isset($roleId)) {
                $roleId = 0;
                if ($role && method_exists($role, 'getId')) {
                    $roleId = (int)$role->getId();
                } elseif ($user && method_exists($user, 'getRole')) {
                    $r = call_user_func([$user, 'getRole']);
                    $roleId = (int)($r && method_exists($r, 'getRoleId') ? ($r->getRoleId() ?: 0) : 0);
                }
            }
            if (RequestLifecycleTrace::isEnabled()) {
                RequestLifecycleTrace::recordSpan('acl::RouteBefore::sessionUserRole', (microtime(true) - $t0) * 1000, 'observer', $parent);
            }
        }

        // 如果没有用户，返回未授权（不调用 logout，避免重定向后 Session 未就绪时误清登录态）
        $hasUser = $user !== null || $sessionAclContext !== null;
        if (!$hasUser) {
            $sidHint = \strlen((string) ($_COOKIE[WlsStrategy::SESSION_NAME] ?? '')) > 0 ? \substr((string) $_COOKIE[WlsStrategy::SESSION_NAME], 0, 8) . '...' : 'none';
            $sessIdHint = \PHP_SAPI !== 'cli' ? (\strlen($this->getBackendSession()->getSession()->getId()) > 0 ? \substr($this->getBackendSession()->getSession()->getId(), 0, 8) . '...' : 'empty') : 'cli';
            w_auth_log('acl_not_logged_in', 'Session 无 user_id，重定向登录', ['uri' => $uri, 'cookie_sid_hint' => $sidHint, 'session_id_hint' => $sessIdHint]);
        }
        // 根因说明：not_logged_in = Session 无 user_id（getUserId() 为空），非「数据库查不到用户」。数据库查不到时会有 _user_not_found 且走 no_role 分支并提示「用户不存在或已被删除」；若 var/log 中见 getAclContext 的 acl 日志则为 DB 问题。
        if (!$hasUser) {
            w_log_warning(
                    '[ACL] not_logged_in：Session 无 user_id（getUserId 为空），重定向登录。若为「数据库查不到用户」会先有 getAclContext 的 acl 日志',
                    ['uri' => $uri],
                    'acl'
                );
                if ($request->isApiBackend()) {
                    $this->returnApiError(401, __('请先登录'), $request);
                    return;
                }
                /**@var EventsManager $eventsManager */
                $eventsManager = ObjectManager::getInstance(EventsManager::class);
                $noAccessData = ['data' => ['reason' => 'not_logged_in']];
                $eventsManager->dispatch('Weline_Acl::no_access_redirect_before', $noAccessData);
                $request->getResponse()->noRouter(DEV ? 403 : 404);
                return;
            }

            // 检查用户状态（事件分支用 $user->getIsEnabled()，session 分支用 $sessionAclContext['is_enabled']）
            $isEnabled = $user !== null && method_exists($user, 'getIsEnabled')
                ? $user->getIsEnabled()
                : (bool) ($sessionAclContext['is_enabled'] ?? 1);
            if (!$isEnabled) {
                w_auth_log('acl_user_disabled', '用户已被禁用', ['uri' => $uri, 'user_id' => $sessionAclContext['user_id'] ?? null, 'role_id' => $roleId]);
                if ($request->isApiBackend()) {
                    $this->returnApiError(403, __('用户已被禁用'), $request);
                    return;
                }
                $this->getBackendSession()->logout();
                $this->persistBackendSessionAfterLogout();
                $request->getResponse()->noRouter(DEV ? 403 : 404);
                return;
            }

            // 如果没有角色，或用户不存在（getAclContext 曾返回 null）
            if ($roleId <= 0) {
                $userNotFound = (bool) ($sessionAclContext['_user_not_found'] ?? false);
                $reason = $userNotFound ? 'user_not_found' : 'no_role';
                w_auth_log('acl_no_role', '无角色或用户不存在', ['uri' => $uri, 'user_id' => $sessionAclContext['user_id'] ?? null, 'role_id' => $roleId, 'reason' => $reason]);
                if ($request->isApiBackend()) {
                    $this->returnApiError(403, $userNotFound ? __('用户不存在或已被删除') : __('用户没有分配角色'), $request);
                    return;
                } else {
                    $this->getBackendSession()->logout();
                    $this->persistBackendSessionAfterLogout();
                    /**@var EventsManager $eventsManager */
                    $eventsManager = ObjectManager::getInstance(EventsManager::class);
                    $noAccessData = ['data' => ['reason' => $reason]];
                    $eventsManager->dispatch('Weline_Acl::no_access_redirect_before', $noAccessData);
                    $request->getResponse()->noRouter(DEV ? 403 : 404);
                    return;
                }
            }
            $can_referer = $this->getCanReferer($referer, $request);

            // 非超管角色统一通过 AclService 做权限判定（roleId 已在上方从 user/role 取得，无需再 load Role）
            if ($roleId !== 1) {
                $t0 = microtime(true);
                $hasAny = $this->aclService->hasAnyPermission($roleId);
                if (RequestLifecycleTrace::isEnabled()) {
                    RequestLifecycleTrace::recordSpan('acl::RouteBefore::hasAnyPermission', (microtime(true) - $t0) * 1000, 'observer', $parent);
                }
                // 没有任何 ACL 权限：直接按“无任何权限”处理
                if (!$hasAny) {
                    w_auth_log('acl_no_any_permission', '角色没有任何 ACL 权限', ['uri' => $uri, 'role_id' => $roleId]);
                    if ($request->isApiBackend()) {
                        $this->returnApiError(403, __('你没有任何权限！请联系管理员！'), $request);
                        return;
                    }
                    $this->getBackendSession()->logout();
                    $this->persistBackendSessionAfterLogout();
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

                $t0 = microtime(true);
                $allowed = $this->aclService->isRouteAllowed($roleId, $uri, $request->getMethod());
                if (RequestLifecycleTrace::isEnabled()) {
                    RequestLifecycleTrace::recordSpan('acl::RouteBefore::isRouteAllowed', (microtime(true) - $t0) * 1000, 'observer', $parent);
                }
                if (!$allowed) {
                    w_auth_log('acl_no_permission_for_route', '角色无当前路由权限', ['uri' => $uri, 'role_id' => $roleId, 'method' => $request->getMethod()]);
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
        w_auth_log('acl_allowed', '权限校验通过', ['uri' => $uri, 'role_id' => $roleId, 'user_id' => $sessionAclContext['user_id'] ?? ($user && method_exists($user, 'getId') ? $user->getId() : null)]);
    }

    /**
     * 验证前端API访问权限
     * 
     * 支持第三方认证：从事件中获取用户、角色和权限
     */
    private function getFrontendWhiteList(): array
    {
        self::registerStateManager();
        if (self::$frontendWhiteListRequestCache !== false) {
            return self::$frontendWhiteListRequestCache;
        }
        $white_acl_cache_key = 'frontend_api_white_acl_sources';
        $white_lists = $this->aclCache->get($white_acl_cache_key);
        if (empty($white_lists)) {
            $white_lists = $this->whiteAclSource
                ->fields('path')
                ->where('type', \Weline\Acl\Model\WhiteAclSource::type_API)
                ->select()
                ->fetchArray();
            $paths = [];
            foreach ($white_lists as $white_list) {
                $paths[] = $white_list['path'];
            }
            $white_lists = $paths;
            $this->aclCache->set($white_acl_cache_key, $white_lists);
        }
        self::$frontendWhiteListRequestCache = $white_lists;
        return $white_lists;
    }

    private function validateFrontendApiAccess(Request $request, Event &$event): void
    {
        $white_lists = $this->getFrontendWhiteList();

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
                $role = call_user_func([$user, 'getRoleModel']);
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
                $message->warning(__('你无权进行该操作！你不具备：%{1} 路由：%{2} 操作权限！已将你带到你可访问的页面！', [
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
            $message->warning(__('你无权进行该操作！已将你带到你可访问的后台页面：%{1}', [$backendUrl]));
            $request->getResponse()->redirect($backendUrl);
            return;
        }

        // 没有任何 menus 类型的权限，视为“没有可用入口”
        $this->getBackendSession()->logout();
        $this->persistBackendSessionAfterLogout();
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
        $can_referer = $referer && ($request->getFullUrl() !== $referer) && $this->getBackendSession()->isLoggedIn();
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
