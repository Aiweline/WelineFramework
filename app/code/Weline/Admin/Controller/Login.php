<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Controller;

use Weline\Admin\Helper\Data;
use Weline\Admin\Helper\MenuUrlValidator;
use Weline\Admin\Service\BackendVerificationCodeGate;
use Weline\Backend\Service\MenuService;
use Weline\Backend\Service\MenuServiceInterface;
use Weline\Backend\Model\BackendUserToken;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Http\HeaderCollector;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\Url;
use Weline\Framework\Session\Session;
use Weline\Framework\Session\Strategy\WlsStrategy;
use Weline\Backend\Model\Config as BackendConfig;
use Weline\FileManager\Helper\Image as ImageHelper;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\System\Text;

class Login extends \Weline\Framework\App\Controller\BackendController
{
    /**
     * 登录页不使用布局系统，使用独立完整的模板
     */
    private const SESSION_KEY_NEED_BACKEND_VERIFICATION_CODE = 'need_backend_verification_code';
    private const SESSION_KEY_BACKEND_VERIFICATION_CODE = 'backend_verification_code';
    protected ?string $layoutType = null;
    
    protected BackendUser $adminUser;
    private Data $helper;
    private MessageManager $messageManager;
    private MenuServiceInterface $menuService;
    private BackendVerificationCodeGate $backendVerificationCodeGate;

    public function __construct(
        BackendUser           $adminUser,
        MessageManager        $messageManager,
        Data                  $helper,
        MenuService           $menuService,
        BackendVerificationCodeGate $backendVerificationCodeGate
    ) {
        $this->adminUser = $adminUser;
        $this->helper = $helper;
        $this->messageManager = $messageManager;
        $this->menuService = $menuService;
        $this->backendVerificationCodeGate = $backendVerificationCodeGate;
    }

    public function index()
    {
        // 防御：仅当无权限原因为「用户不存在/无角色」（DB 侧问题）时强制清空 Session，避免带着无效身份再进后台。
        // not_logged_in 时不清：上一请求可能是 Session 读回空导致误判，本请求已带 Cookie 且读回有效 Session，清掉会误杀登录态。
        $noAccessReasonParam = (string) $this->request->getParam('no_access_reason', '');
        $forceClearReasons = ['user_not_found', 'no_role'];
        if ($noAccessReasonParam !== '' && in_array($noAccessReasonParam, $forceClearReasons, true) && $this->session->isLoggedIn()) {
            w_auth_log('login_index_force_clear', '无权限重定向到登录页（用户不存在/无角色），强制清空 Session', ['reason' => $noAccessReasonParam, 'session' => $this->getSessionDataForLog()]);
            $this->session->logout();
            $this->session->getSession()->destroy();
        }
        if ($this->session->isLoggedIn()) {
            $targetPath = $this->resolveDefaultRedirectTarget();
            w_auth_log('login_index_already_logged_in', '已登录，重定向后台', ['target_path' => $targetPath, 'user_id' => $this->session->getUserId(), 'session' => $this->getSessionDataForLog()]);
            $this->redirectReferer();
            $this->redirect($this->getBackendUrlSameOrigin($targetPath));
        }
        //$this->session->delete('backend_disable_login');
        $this->assign('post_url', $this->_url->getBackendUrl('admin/login/post'));
        // 无权限重定向原因：仅当次请求通过 GET 传入，显示一次即不再保留，刷新后不显示
        $noAccessReason = $this->request->getParam('no_access_reason');
        if ($noAccessReason !== null && $noAccessReason !== '') {
            w_auth_log('login_index_no_access_display', '登录页展示无权限原因', ['reason' => $noAccessReason, 'session' => $this->getSessionDataForLog()]);
            [$title, $msg] = $this->getNoAccessMessageByReason((string) $noAccessReason);
            $this->assign('no_access_message', \Weline\Framework\Manager\MessageManager::process_message($msg, $title, 'warning'));
        } else {
            $this->assign('no_access_message', '');
        }
        // 显式输出 MessageManager 的 Flash 消息（密码错误、验证码错误等），确保 302 后能展示
        $this->assign('login_flash_message', (string) $this->messageManager);
        # 检测验证码
        if ($this->session->get(self::SESSION_KEY_NEED_BACKEND_VERIFICATION_CODE)) {
            $this->session->delete(self::SESSION_KEY_BACKEND_VERIFICATION_CODE);
            $this->assign('need_backend_verification_code', true);
            // 使用连字符小写路径以与白名单精确匹配，并兼容路由规则
            $this->assign('backend_verification_code_url', $this->_url->getBackendUrl('admin/login/verification-code'));
        }
        # 登录页：使用后台配置（Logo、站点名）
        $backendConfig = ObjectManager::getInstance(BackendConfig::class);
        $backendConfigs = $backendConfig->getConfigs('Weline_Backend');
        $logoDark = (string)($backendConfigs['logo_dark'] ?? '');
        $logoLight = (string)($backendConfigs['logo_light'] ?? '');
        $this->assign('login_logo_dark', $logoDark !== '' ? ImageHelper::pathToMediaUrl($logoDark, 125, 125) : '');
        $this->assign('login_logo_light', $logoLight !== '' ? ImageHelper::pathToMediaUrl($logoLight, 125, 125) : '');
        $siteName = (string)($backendConfigs['site_name'] ?? 'Weline');
        $this->assign('login_site_name', $siteName);
        $loginBg = trim((string)($backendConfigs['login_bg'] ?? ''));
        if ($loginBg !== '') {
            foreach (['/pub/media/', 'pub/media/', '/media/'] as $prefix) {
                if (str_starts_with($loginBg, $prefix)) {
                    $loginBg = ltrim(substr($loginBg, strlen($prefix)), '/');
                    break;
                }
            }
            $loginBg = ltrim($loginBg, '/');
        }
        $loginBgUrl = $loginBg !== '' ? '/pub/media/' . $loginBg : '';
        $this->assign('login_bg_url', $loginBgUrl);
        // 锁定提示以 Session 为准，但若数据库中该用户 attempt_times 已恢复（管理员改过），则清除 Session 标志避免一直提示
        $s = $this->session;
        $backendDisable = $s->get('backend_disable_login');
        if ($backendDisable) {
            $lockedUsername = $s->get('backend_disable_login_username') ?? $this->request->getParam('username') ?? '';
            $cleared = false;
            if ($lockedUsername !== '') {
                $user = clone $this->adminUser;
                $user->reset()->where('username', $lockedUsername)->find()->fetch();
                $uid = $user->getId();
                $attemptTimes = $user->getAttemptTimes();
                if ($uid && $attemptTimes <= 6) {
                    $s->delete('backend_disable_login');
                    $s->delete('backend_disable_login_username');
                    ObjectManager::getInstance(MessageManager::class)->clear();
                    $cleared = true;
                }
            } else {
                // 无用户名时视为老旧 session，清除锁定显示，让用户重试（POST 会重新校验）
                $s->delete('backend_disable_login');
                $s->delete('backend_disable_login_username');
                ObjectManager::getInstance(MessageManager::class)->clear();
                $cleared = true;
            }
            $afterClear = $s->get('backend_disable_login');
            if (!$cleared && $afterClear) {
                MessageManager::error(__('你的账户因尝试多次登录，已被锁定！请联系其他管理员开通。'));
            }
        }
        // 登录页本身就是一个独立完整模板，不依赖通用布局包装。
        // 在 WLS 下直接返回 detached HTML Response，避免控制器 fetch 事件链
        // 或后续结果归一化把登录页 body 吞成空响应。
        return Response::html($this->template('Weline_Admin::templates/Login/index.phtml'));
    }

    /**
     * 根据 no_access_reason 参数返回 [title, message]，与 NoAccessRedirectBefore 中 reason 一致。
     */
    private function getNoAccessMessageByReason(string $reason): array
    {
        switch ($reason) {
            case 'not_logged_in':
                return [__('未登录'), __('访问后台需要先登录。')];
            case 'user_not_found':
                return [__('账户异常'), __('该账户不存在或已被删除，请使用有效账户重新登录。')];
            case 'no_role':
                return [__('无权限'), __('用户没有分配角色，请联系管理员。')];
            case 'no_any_permission':
                return [__('无权限'), __('您没有任何后台权限，请联系管理员。')];
            case 'no_permission_for_route':
                return [__('无权限'), __('您没有访问该页面的权限，请联系管理员。')];
            case 'no_usable_permission':
                return [__('无权限'), __('当前没有可用的访问入口，请重新登录或联系管理员。')];
            default:
                return [__('无权限'), __('您没有访问该页面的权限，请先登录或联系管理员。')];
        }
    }

    public function postPost(): void
    {
        # 已经登录直接进入后台
        if ($this->session->isLoggedIn()) {
            w_auth_log('login_post_already_logged_in', 'POST 时已登录，直接重定向后台', ['user_id' => $this->session->getUserId(), 'session' => $this->getSessionDataForLog()]);
            $this->redirectReferer();
            $this->redirect($this->getBackendUrlSameOrigin('admin'));
        }
        # 验证 form 表单
        // if (empty($this->request->getParam('form_key')) || ($this->session->get('form_key') !== $this->request->getParam('form_key'))) {
        //     MessageManager::error(__('异常的登录操作！'));
        //     $this->redirect($this->_url->getBackendUrl('/admin/login'));
        //     return;
        // }

        $adminUsernameUser = $this->helper->getRequestBackendUser();
        if (!$adminUsernameUser->getId() or $adminUsernameUser->getIsDeleted()) {
            w_auth_log('login_post_user_not_found', '账户不存在或已删除', ['username' => $this->request->getParam('username'), 'session' => $this->getSessionDataForLog()]);
            MessageManager::error(__('账户不存在！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        if (!$adminUsernameUser->getIsEnabled()) {
            w_auth_log('login_post_disabled', '账户被禁用', ['user_id' => $adminUsernameUser->getId(), 'username' => $adminUsernameUser->getUsername(), 'session' => $this->getSessionDataForLog()]);
            MessageManager::error(__('账户被禁用！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        if ($adminUsernameUser->getAttemptTimes() > 6) {
            w_auth_log('login_post_locked', '尝试次数超限，账户锁定', ['user_id' => $adminUsernameUser->getId(), 'username' => $adminUsernameUser->getUsername(), 'attempt_times' => $adminUsernameUser->getAttemptTimes(), 'session' => $this->getSessionDataForLog()]);
            $adminUsernameUser->setSessionId($this->session->getId())->setAttemptIp($this->request->clientIP())->save();
            $s = $this->session;
            $s->set('backend_disable_login', true);
            $s->set('backend_disable_login_username', $adminUsernameUser->getUsername());
            if ($adminUsernameUser->getAttemptTimes() > 60) {
                # FIXME 将IP封死，为了不占用服务器资源，将封锁过程提前到框架入口处，此处只作为拉入黑名单处理【设置为Security框架函数处理】
                $this->noRouter();
            }
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        } else {
            $this->session->set('backend_disable_login', false);
        }
        # 自增尝试登录次数
        try {
            $adminUsernameUser->addAttemptTimes()->save();
        } catch (\Exception $exception) {
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            MessageManager::error(__('登录异常！'));
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        # 如果大于2次的尝试登录 验证客户提供的验证码
        $verificationCodeState = $this->backendVerificationCodeGate->evaluate(
            $adminUsernameUser->getAttemptTimes(),
            $this->session->get(self::SESSION_KEY_BACKEND_VERIFICATION_CODE),
            $this->request->getParam('code')
        );
        if ($verificationCodeState['should_display_captcha']) {
            $this->session->set(self::SESSION_KEY_NEED_BACKEND_VERIFICATION_CODE, 1);
        }
        # 验证验证码
        if ($verificationCodeState['should_block']) {
            if ($verificationCodeState['error_message'] !== null) {
                w_auth_log('login_post_captcha_error', '验证码错误', ['user_id' => $adminUsernameUser->getId(), 'username' => $adminUsernameUser->getUsername(), 'session' => $this->getSessionDataForLog()]);
                MessageManager::error(__($verificationCodeState['error_message']));
            }
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        # 尝试登录
        $password = $this->request->getParam('password');
        $storedPassword = $adminUsernameUser->getPassword();
        $passwordVerifyResult = $storedPassword && password_verify($password, $storedPassword);
        if ($passwordVerifyResult) {
            if ($this->dispatchPasswordVerifiedLoginExtension($adminUsernameUser)) {
                return;
            }
            # SESSION登录用户
            try {
                // 确保session已启动
                $currentSessionId = $this->session->getId();
                if (empty($currentSessionId)) {
                    $this->session->start('');
                }
                // 调用login方法（只传入一个参数）
                $this->session->login($adminUsernameUser);
                // 检查用户是否有角色，如果没有角色，显示友好提示并退出登录（user_id=1 视为超管，无角色记录也允许登录）
                $userRole = $adminUsernameUser->getRole();
                $hasRole = (bool)($userRole && $userRole->getRoleId());
                $isSuperAdminById = (int) $adminUsernameUser->getId() === 1;
                if (!$hasRole && !$isSuperAdminById) {
                    w_auth_log('login_post_no_role', '账户未分配角色，拒绝登录', ['user_id' => $adminUsernameUser->getId(), 'username' => $adminUsernameUser->getUsername(), 'session' => $this->getSessionDataForLog()]);
                    $this->session->logout();
                    MessageManager::error(__('您的账户尚未分配角色，无法登录后台。请联系系统管理员为您分配角色。'));
                    $this->redirect($this->_url->getBackendUrl('/admin/login'));
                    return;
                }
                // 写入 ACL 上下文到 Session，路由校验时直接读 Session 免去每次请求 2 次 DB
                $aclRoleId = $userRole && $userRole->getRoleId() ? (int) $userRole->getRoleId() : ($isSuperAdminById ? 1 : 0);
                $this->session->getSession()->set('backend_acl_role_id', $aclRoleId);
                $this->session->getSession()->set('backend_acl_is_enabled', $adminUsernameUser->getIsEnabled() ? 1 : 0);
                w_auth_log('login_post_success', '登录成功，写入 Session ACL 上下文', ['user_id' => $adminUsernameUser->getId(), 'username' => $adminUsernameUser->getUsername(), 'acl_role_id' => $aclRoleId, 'session_id_hint' => \substr($this->session->getId(), 0, 8) . '...', 'session' => $this->getSessionDataForLog()]);
            } catch (\Exception $e) {
                w_auth_log('login_post_exception', '登录过程异常', ['user_id' => $adminUsernameUser->getId(), 'message' => $e->getMessage(), 'session' => $this->getSessionDataForLog()]);
                throw $e;
            }
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setLoginIp($this->request->clientIP());
            # 重置 尝试登录次数
            $adminUsernameUser->resetAttemptTimes()->save();
            # 登录成功后清理验证码相关的session数据
            $this->clearBackendVerificationCodeState();
            $this->syncSandboxCookie($adminUsernameUser->isSandboxAccount());
            # 检测是否记住我
            if ($this->request->getParam('remember')) {
                /**@var BackendUserToken $backendUserToken */
                $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
                $backendUserToken->load($adminUsernameUser->getId());
                $token = Text::random_string(32);
                $rememberTtl = 7 * 24 * 60 * 60;
                $token_expire_time = \time() + $rememberTtl;
                $backendUserToken
                    ->setData($backendUserToken::schema_fields_ID, $adminUsernameUser->getId())
                    ->setData($backendUserToken::schema_fields_type, 'admin_login_remember_me')
                    ->setData($backendUserToken::schema_fields_token, $token)
                    ->setData($backendUserToken::schema_fields_token_expire_time, $token_expire_time)
                    ->save();
                Cookie::set('w_ut', $token, $rememberTtl, ['path' => '/']);
                $this->session->set('remember_expire_time', $token_expire_time);
            } else {
                $this->session->delete('remember_expire_time');
            }
        } else {
            w_auth_log('login_post_password_fail', '密码验证失败', ['user_id' => $adminUsernameUser->getId(), 'username' => $adminUsernameUser->getUsername(), 'session' => $this->getSessionDataForLog()]);
            $adminUsernameUser->setSessionId($this->session->getId())
                ->setAttemptIp($this->request->clientIP())
                ->save();
            MessageManager::error(__('登录凭据错误！'));
            // 用户未登录，无需 logout；logout 会 destroy session 导致 MessageManager 的错误信息丢失
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return;
        }
        // 登录成功后、302 前必须落库：先持久化本请求内所有 Session，再发 Cookie 与重定向
        $rawSession = $this->session->getSession();
        $rawSession->save();
        if ($rawSession instanceof Session) {
            $rawSession->getStrategy()->writeClose();
        }
        Session::flushRequestSessions();
        // WLS 下确保 Session Cookie 随 302 响应发出（双重保障，避免 Worker 合并逻辑遗漏）
        $sid = $this->session->getId();
        if ($sid !== '') {
            $expire = \time() + 86400 * 30;
            $secure = $this->request->isSecure();
            HeaderCollector::getInstance()->setCookie(
                WlsStrategy::SESSION_NAME,
                $sid,
                $expire,
                '/',
                '',
                $secure,
                true,
                'Lax'
            );
        }
        // 优先跳回上次访问的地址，找不到才跳转 admin
        $this->redirectReferer($adminUsernameUser);

        $targetPath = $this->resolveDefaultRedirectTarget($adminUsernameUser);
        w_auth_log('login_post_redirect', '登录成功，即将 302 重定向', ['user_id' => $adminUsernameUser->getId(), 'target_path' => $targetPath, 'session' => $this->getSessionDataForLog()]);
        // 跳转后台入口（使用当前请求同源 URL，确保 Cookie 能带上，避免跨 host 丢失 Session）
        $this->redirect($this->getBackendUrlSameOrigin($targetPath));
    }

    /**
     * 优先跳回上次访问的地址（须验证当前用户对该路由有权限）。
     *
     * @param BackendUser|null $user 已登录用户，null 时从 session 加载
     */
    private function redirectReferer(?BackendUser $user = null): void
    {
        $user ??= $this->loadCurrentBackendUser();
        $candidates = [
            Url::removeExtraDoubleSlashes((string)$this->session->get('backend_login_referer')),
            Url::removeExtraDoubleSlashes((string)$this->session->get('referer')),
        ];
        foreach ($candidates as $refererUrl) {
            if (!$refererUrl || $this->request->getUrlPath($refererUrl) === $this->request->getUrlPath()) {
                continue;
            }
            if (!Url::is_same_site($refererUrl)) {
                continue;
            }
            $parsed = \Weline\Framework\Http\Url::parser($refererUrl);
            $refererRoutePath = trim($parsed['uri'] ?? '', '/');
            if (!$refererRoutePath || !MenuUrlValidator::isValidLoginRedirectTarget($refererRoutePath)) {
                $this->session->delete('backend_login_referer');
                $this->session->delete('referer');
                continue;
            }
            // 必须验证当前用户对该路由有权限，否则跳转后会再次提示“无权操作”
            if (!$user || !$this->userHasRoutePermission($user, $refererRoutePath)) {
                $this->session->delete('backend_login_referer');
                $this->session->delete('referer');
                continue;
            }
            $this->session->delete('backend_login_referer');
            $this->session->delete('referer');
            $this->redirect($this->ensureSameOrigin($refererUrl));
            return;
        }
    }

    private function loadCurrentBackendUser(): ?BackendUser
    {
        $userId = $this->session->getUserId();
        if (!$userId) {
            return null;
        }
        $user = clone $this->adminUser;
        $user->load((int)$userId);
        return $user->getId() ? $user : null;
    }

    /**
     * 开发模式下 auth 日志用：获取当前 Session 全部键值，便于排查登录/权限问题。
     */
    private function getSessionDataForLog(): array
    {
        if (!\defined('DEV') || !DEV) {
            return [];
        }
        try {
            $raw = $this->session->getSession();
            $all = \method_exists($raw, 'getData') ? $raw->getData('') : (\method_exists($raw, 'all') ? $raw->all() : []);
            return \is_array($all) ? $all : [];
        } catch (\Throwable $e) {
            return ['_error' => $e->getMessage()];
        }
    }

    private function userHasRoutePermission(BackendUser $user, string $routePath): bool
    {
        $role = $user->getRoleModel();
        if (!$role || !$role->getId()) {
            return (int)$user->getId() === 1; // 超管无角色也放行
        }
        return $this->menuService->findMenuNodeByRoute((int)$role->getId(), $routePath) !== null;
    }

    /**
     * 获取默认跳转目标：优先使用角色第一个可访问菜单，否则 admin。
     */
    private function resolveDefaultRedirectTarget(?BackendUser $user = null): string
    {
        $user ??= $this->loadCurrentBackendUser();
        if ($user) {
            $role = $user->getRoleModel();
            if ($role && $role->getId()) {
                $defaultRoute = $this->menuService->getDefaultEntryRoute((int)$role->getId());
                if ($defaultRoute !== null && $defaultRoute !== '') {
                    return $defaultRoute;
                }
            }
        }
        return 'admin';
    }

    /**
     * 使用当前请求的 scheme+host 及后台路由前缀生成后台 URL，保证含 admin_xxx 前缀且同源。
     */
    private function getBackendUrlSameOrigin(string $path): string
    {
        $pathPart = $this->getBackendPathWithPrefix($path);
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = $this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost';
        return $scheme . '://' . $host . $pathPart;
    }

    /**
     * 获取带后台路由前缀的路径（如 /admin_696f02955db39/CNY/zh_Hans_CN/admin），避免重定向丢失后端 key。
     * 仅当 WELINE_AREA_ROUTE 已含后端 prefix 时使用；否则用 Env backend prefix + 货币 + 语言 拼接。
     */
    private function getBackendPathWithPrefix(string $path): string
    {
        $backendPrefix = \Weline\Framework\App\Env::getAreaRoutePrefix('backend');
        $areaRoute = $this->request->getServer('WELINE_AREA_ROUTE') ?? '';
        if ($areaRoute !== '' && $backendPrefix !== null && $backendPrefix !== ''
            && (str_starts_with($areaRoute, $backendPrefix . '/') || $areaRoute === $backendPrefix)) {
            return '/' . \trim($areaRoute, '/') . '/' . \ltrim($path, '/');
        }
        if ($backendPrefix !== null && $backendPrefix !== '') {
            $currency = (string) (\w_env('user.currency', 'CNY') ?? 'CNY');
            $language = (string) (\w_env('user.lang', 'zh_Hans_CN') ?? 'zh_Hans_CN');
            return '/' . $backendPrefix . '/' . $currency . '/' . $language . '/' . \ltrim($path, '/');
        }
        return $this->_url->getBackendUrlPath($path);
    }

    /**
     * 将 URL 规范为当前请求同源（保留 path+query，替换 scheme+host），path 已含后台前缀则不再改写。
     */
    private function ensureSameOrigin(string $url): string
    {
        $parsed = \parse_url($url);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '';
        $scheme = $this->request->isSecure() ? 'https' : 'http';
        $host = $this->request->getServer('HTTP_HOST') ?: $this->request->getServer('SERVER_NAME') ?: 'localhost';
        return $scheme . '://' . $host . $path . $query;
    }

    public function logout(): void
    {
        // 在退出登录前，保存当前页面URL（如果是菜单链接）
        // 优先从 session 的 referer 获取，如果没有则从 HTTP_REFERER 头获取
        $currentUrl = '';
        
        // 1. 优先从 session 的 referer 获取
        $referer = $this->session->get('referer');
        if ($referer) {
            $referer = Url::removeExtraDoubleSlashes($referer);
            if ($referer && Url::is_same_site($referer)) {
                $currentUrl = $referer;
            }
        }
        
        // 2. 如果 session 中没有，尝试从 HTTP_REFERER 头获取
        if (!$currentUrl) {
            $httpReferer = $this->request->getReferer();
            if ($httpReferer && Url::is_same_site($httpReferer)) {
                $currentUrl = Url::removeExtraDoubleSlashes($httpReferer);
            }
        }
        
        // 验证URL是否可以作为登录后跳转的有效目标，若是则写入 session（logout 只清除认证相关 key，此项会保留）
        if ($currentUrl) {
            $parsed = \Weline\Framework\Http\Url::parser($currentUrl);
            $routePath = trim($parsed['uri'] ?? '', '/');
            if ($routePath && MenuUrlValidator::isValidLoginRedirectTarget($routePath)) {
                $this->session->set('backend_login_referer', $currentUrl);
            }
        }
        
        $userId = $this->session->getUserId();
        w_auth_log('logout', '用户退出登录', ['user_id' => $userId, 'session' => $this->getSessionDataForLog()]);
        $this->session->logout();
        $this->session->getSession()->delete('backend_acl_role_id');
        $this->session->getSession()->delete('backend_acl_is_enabled');
        if ($userId) {
            $backendUserToken = ObjectManager::getInstance(BackendUserToken::class);
            $backendUserToken->reset()
                ->where($backendUserToken::schema_fields_ID, (int)$userId)
                ->where($backendUserToken::schema_fields_type, 'admin_login_remember_me')
                ->find()
                ->fetch();
            if ($backendUserToken->getId()) {
                $backendUserToken->setData($backendUserToken::schema_fields_token, '')
                    ->setData($backendUserToken::schema_fields_token_expire_time, 0)
                    ->save();
            }
        }
        Cookie::set('w_ut', '', -1, ['path' => '/']);
        Cookie::set('w_ut', '', -1, ['path' => '/' . $this->request->getAreaRouter()]);
        Cookie::set('w_sandbox', '', -1, ['path' => '/']);
        Cookie::set('w_sandbox', '', -1, ['path' => '/' . $this->request->getAreaRouter()]);
        $this->session->delete('remember_expire_time');
        $this->clearBackendVerificationCodeState();
        $this->session->getSession()->destroy();
        $this->redirect($this->_url->getBackendUrl('admin/login'));
    }

    private function syncSandboxCookie(bool $enabled): void
    {
        $lifetime = $enabled ? 0 : -1;
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/']);
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/' . $this->request->getAreaRouter()]);
    }

    /**
     * @DESC          # 获取验证码
     *
     * @AUTH    秋枫雁飞
     * @EMAIL aiweline@qq.com
     * @DateTime: 2021/11/9 23:54
     * 参数区：
     * @return never 通过 send() 抛出 ResponseTerminateException，由 Runtime 发送
     */
    public function verificationCode()
    {
        if (
            !$this->backendVerificationCodeGate->canAccessCaptcha(
                (bool)$this->session->get(self::SESSION_KEY_NEED_BACKEND_VERIFICATION_CODE)
            )
        ) {
            $this->request->getResponse()->noRouter(DEV ? 403 : 404);
        }
        # --1 设置验证码图片的大小
        $image = imagecreatetruecolor(100, 30);
        # --2 设置验证码颜色 imagecolorallocate(int im, int red, int green, int blue);
        $bgcolor = imagecolorallocate($image, 255, 255, 255); //#ffffff
        # --3 区域填充 int imagefill(int im, int x, int y, int col) (x,y) 所在的区域着色,col 表示欲涂上的颜色
        imagefill($image, 0, 0, $bgcolor);
        # --4 设置变量
        $captcha_code = '';
        # --5 生成随机数字
        for ($i = 0; $i < 6; $i++) {
            # --5-1 设置字体大小
            $fontsize = 6;
            # --5-2 设置字体颜色，随机颜色
            $fontcolor = imagecolorallocate($image, rand(0, 120), rand(0, 120), rand(0, 120));      //0-120深颜色
            # --5-3 设置数字
            $fontcontent = rand(0, 9);
            # --5-4 .=连续定义变量
            $captcha_code .= $fontcontent;
            # --5-5 设置坐标
            $x = intval(($i * 100 / 6) + rand(5, 10));
            $y = rand(5, 10);
            imagestring($image, $fontsize, $x, $y, (string)$fontcontent, $fontcolor);
        }
        $this->session->set(self::SESSION_KEY_BACKEND_VERIFICATION_CODE, $captcha_code);

        # --6 增加干扰元素，设置雪花点
        for ($i = 0; $i < 200; $i++) {
            # --6-1 设置点的颜色，50-200颜色比数字浅，不干扰阅读
            $pointcolor = imagecolorallocate($image, rand(50, 200), rand(50, 200), rand(50, 200));
            # --6-2 imagesetpixel — 画一个单一像素
            imagesetpixel($image, rand(1, 99), rand(1, 29), $pointcolor);
        }
        # --7 增加干扰元素，设置横线
        for ($i = 0; $i < 4; $i++) {
            # --7-1 设置线的颜色
            $linecolor = imagecolorallocate($image, rand(80, 220), rand(80, 220), rand(80, 220));
            # --7-2 设置线，两点一线
            imageline($image, rand(1, 99), rand(1, 29), rand(1, 99), rand(1, 29), $linecolor);
        }

        # --8 通过 Response 输出并发送，兼容 FPM/WLS，由 Runtime 统一处理
        ob_start();
        $pngGenerated = imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        if (!$pngGenerated || !is_string($png) || $png === '') {
            $response = $this->request->getResponse();
            $response->setHeader('Content-Type', 'text/plain; charset=UTF-8');
            $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->setBody((string)__('验证码生成失败，请刷新重试。'));
            $response->send();
            return;
        }

        // 某些运行时链路会残留输出缓冲内容，可能导致 PNG 响应被污染为破损图。
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $response = $this->request->getResponse();
        $response->setHeader('Content-Type', 'image/png');
        $response->setHeader('X-Content-Type-Options', 'nosniff');
        $response->setHeader('Content-Disposition', 'inline; filename="captcha.png"');
        $response->setHeader('Content-Length', (string)strlen($png));
        $response->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->setHeader('Pragma', 'no-cache');
        $response->setHeader('Expires', '0');
        $response->setBody($png);
        $response->send();
    }

    /**
     * 派发密码校验通过事件，由集成模块（如 WeShop GoogleAuth）接管 WebAuth/2FA。
     *
     * @return bool 已处理并完成响应（含重定向）时返回 true
     */
    private function dispatchPasswordVerifiedLoginExtension(BackendUser $adminUsernameUser): bool
    {
        $loginEventData = new DataObject([
            'user' => $adminUsernameUser,
            'auth_method' => 'password',
            'remember' => (bool) $this->request->getParam('remember'),
            'redirect_url' => '',
            'handled' => false,
            'result' => null,
            'error' => null,
        ]);

        /** @var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $eventManager->dispatch('Weline_Admin_Login::password_verified', $loginEventData);

        if (!$loginEventData->getData('handled')) {
            return false;
        }

        $error = $loginEventData->getData('error');
        if ($error instanceof \Throwable) {
            w_auth_log('login_post_exception', '后台扩展登录流程失败', [
                'user_id' => $adminUsernameUser->getId(),
                'message' => $error->getMessage(),
                'session' => $this->getSessionDataForLog(),
            ]);
            MessageManager::error($error->getMessage());
            $this->redirect($this->_url->getBackendUrl('/admin/login'));
            return true;
        }

        $result = $loginEventData->getData('result');
        if (!is_array($result)) {
            return false;
        }

        if (($result['status'] ?? '') === 'challenge_required') {
            $challengeToken = (string) ($result['challenge_token'] ?? '');
            w_auth_log('login_post_challenge_required', '后台登录需完成两步验证', [
                'user_id' => $adminUsernameUser->getId(),
                'challenge_token' => $challengeToken,
                'session' => $this->getSessionDataForLog(),
            ]);
            $this->getMessageManager()->addWarning(__('请完成两步验证以完成登录。'));
            $this->redirect($this->_url->getFrontendUrl('weshop_googleauth/frontend/auth/backend-challenge', [
                'challenge_token' => $challengeToken,
            ]));
            return true;
        }

        if (($result['status'] ?? '') !== 'authenticated') {
            return false;
        }

        $redirectUrl = (string) ($result['redirect_url'] ?? '');
        if ($redirectUrl === '') {
            $redirectUrl = $this->getBackendUrlSameOrigin($this->resolveDefaultRedirectTarget($adminUsernameUser));
        }
        w_auth_log('login_post_redirect', '后台扩展登录成功并重定向', [
            'user_id' => $adminUsernameUser->getId(),
            'target_url' => $redirectUrl,
            'session' => $this->getSessionDataForLog(),
        ]);
        $this->redirect($redirectUrl);
        return true;
    }

    private function clearBackendVerificationCodeState(): void
    {
        $this->session->delete(self::SESSION_KEY_NEED_BACKEND_VERIFICATION_CODE);
        $this->session->delete(self::SESSION_KEY_BACKEND_VERIFICATION_CODE);
    }
}
