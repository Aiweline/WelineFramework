<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Api\Observer;

use Weline\Api\Model\ApiUser;
use Weline\Api\Service\ApiSecurityService;
use Weline\Api\Service\IpWhitelistService;
use Weline\Api\Service\TokenService;
use Weline\Api\Service\UserAgentRestrictionService;
use Weline\Backend\Session\BackendSession;
use Weline\Framework\App\Session\BackendApiSession;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * API控制器初始化前Observer
 * 
 * 负责API请求的认证和授权验证
 */
class ApiControllerInitBefore implements ObserverInterface
{
    private Request $request;
    private ApiSecurityService $apiSecurityService;
    private IpWhitelistService $ipWhitelistService;
    private UserAgentRestrictionService $userAgentRestrictionService;
    private TokenService $tokenService;

    public function __construct(
        Request $request,
        ApiSecurityService $apiSecurityService,
        IpWhitelistService $ipWhitelistService,
        UserAgentRestrictionService $userAgentRestrictionService,
        TokenService $tokenService
    ) {
        $this->request = $request;
        $this->apiSecurityService = $apiSecurityService;
        $this->ipWhitelistService = $ipWhitelistService;
        $this->userAgentRestrictionService = $userAgentRestrictionService;
        $this->tokenService = $tokenService;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // 只处理API请求（后台和前台）
        if (!$this->request->isApiBackend() && !$this->request->isApiFrontend()) {
            return;
        }

        // 如果是API认证相关的接口，不需要验证登录状态和安全限制
        $currentUrl = $this->request->getRouteUrlPath();
        $authUrls = [
            'backend/api/auth/login',
            'backend/api/auth/exchange',
            'backend/api/auth/refresh',
            'backend/api/auth/token-info',
            'api/auth/login',
            'api/auth/exchange',
            'api/auth/refresh',
            'api/auth/token-info'
        ];

        if (in_array($currentUrl, $authUrls)) {
            return;
        }

        // 1. 检查是否为完全公开接口（无Acl，不需要登录）
        if ($this->apiSecurityService->isPublicApi($this->request)) {
            // 1.1 检查是否包含Cookie（公开接口不允许携带Cookie）
            if ($this->request->getHeader('Cookie')) {
                // 记录日志
                $this->logSecurityViolation(null, 'cookie_violation', [
                    'client_ip' => $this->request->clientIP(),
                    'user_agent' => $this->request->getHeader('User-Agent') ?? '',
                    'request_path' => $currentUrl
                ]);

                $this->returnError(400, __('公开接口不允许携带Cookie'));
                return;
            }
            // 公开接口不需要进一步验证，直接返回
            return;
        }

        // 2. 根据Area区分处理认证
        if ($this->request->isApiBackend()) {
            $this->validateBackendApi($event);
        } else {
            $this->validateFrontendApi($event);
        }
    }

    /**
     * 验证后端API
     * 
     * 认证优先级：
     * 1. 后端Session认证（优先）
     * 2. API Token认证（备选）
     */
    private function validateBackendApi(Event &$event): void
    {
        $isSessionAuthenticated = false;
        $user = null;

        // 2.1 优先检查后端Session是否已登录
        /** @var BackendSession $backendSession */
        $backendSession = ObjectManager::getInstance(BackendSession::class);
        if ($backendSession->isLogin()) {
            $user = $backendSession->getLoginUser();
            if ($user && method_exists($user, 'getIsEnabled') && $user->getIsEnabled()) {
                $isSessionAuthenticated = true;
                // Session认证通过，不需要检查IP白名单和User-Agent限制
                // 将用户信息传递到事件中，供RouteBefore使用
                $event->setData('user', $user);
                if (method_exists($user, 'getRoleModel')) {
                    $event->setData('role', $user->getRoleModel());
                }
                return;
            }
        }

        // 2.2 如果Session未登录，检查API Token
        if (!$isSessionAuthenticated) {
            $token = $this->getTokenFromRequest();
            if (empty($token)) {
                $this->returnError(401, __('请先登录'));
                return;
            }

            // 验证访问令牌
            /** @var ApiUser $apiUser */
            $apiUser = $this->tokenService->validateAccessToken($token);
            if (!$apiUser) {
                $this->returnError(401, __('Token无效或已过期'));
                return;
            }

            // 检查用户状态
            if (!$apiUser->getIsEnabled() || $apiUser->getIsDeleted()) {
                $this->returnError(403, __('用户已被禁用'));
                return;
            }

            // 3. 检查IP白名单（仅Token认证需要检查）
            if ($apiUser->isIpWhitelistEnabled()) {
                $allowedIps = $apiUser->getAllowedIps();
                $clientIp = $this->request->clientIP();

                if (!$this->ipWhitelistService->isIpAllowed($clientIp, $allowedIps)) {
                    // 记录日志
                    $this->logSecurityViolation($apiUser->getId(), 'ip_whitelist', [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);

                    $this->returnError(403, __('IP地址不在白名单中'), [
                        'client_ip' => $clientIp,
                        'allowed_ips' => $allowedIps
                    ]);
                    return;
                }
            }

            // 4. 检查用户代理限制（仅Token认证需要检查）
            if ($apiUser->isUserAgentRestrictionEnabled()) {
                $allowedUserAgents = $apiUser->getAllowedUserAgents();
                $userAgent = $this->request->getHeader('User-Agent') ?? '';

                if (!$this->userAgentRestrictionService->isUserAgentAllowed($userAgent, $allowedUserAgents)) {
                    // 记录日志
                    $this->logSecurityViolation($apiUser->getId(), 'user_agent_restriction', [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);

                    $this->returnError(403, __('用户代理不匹配'), [
                        'user_agent' => $userAgent,
                        'allowed_user_agents' => $allowedUserAgents
                    ]);
                    return;
                }
            }

            // 将API用户信息传递到事件中，供RouteBefore使用
            $event->setData('user', $apiUser);
            $role = $apiUser->getRoleModel();
            if ($role) {
                $event->setData('role', $role);
            }
        }
    }

    /**
     * 验证前端API
     * 
     * 认证优先级：
     * 1. 前端Session认证（优先）
     * 2. API Token认证（备选，暂未实现FrontendApiSession）
     */
    private function validateFrontendApi(Event &$event): void
    {
        // 2.1 优先检查前端Session是否已登录
        /** @var \Weline\Frontend\Session\FrontendUserSession $frontendSession */
        $frontendSession = ObjectManager::getInstance(\Weline\Frontend\Session\FrontendUserSession::class);
        if ($frontendSession->isLogin()) {
            $user = $frontendSession->getLoginUser();
            if ($user && method_exists($user, 'getIsEnabled') && $user->getIsEnabled()) {
                // Session认证通过，不需要检查IP白名单和User-Agent限制
                // 将用户信息传递到事件中，供RouteBefore使用
                $event->setData('user', $user);
                return;
            }
        }

        // 2.2 如果Session未登录，检查API Token（暂未实现，返回401）
        // TODO: 实现FrontendApiSession和FrontendApiUser
        $this->returnError(401, __('请先登录'));
    }

    /**
     * 从请求中获取token
     */
    private function getTokenFromRequest(): ?string
    {
        // 1. 从Authorization头获取Bearer token
        $authHeader = $this->request->getAuth('bearer');
        if (!empty($authHeader)) {
            return $authHeader;
        }

        // 2. 从X-API-Token头获取
        $apiToken = $this->request->getHeader('X-API-Token');
        if (!empty($apiToken)) {
            return $apiToken;
        }

        // 3. 从请求参数获取
        $tokenParam = $this->request->getParam('token');
        if (!empty($tokenParam)) {
            return $tokenParam;
        }

        // 4. 从POST数据获取
        $postToken = $this->request->getPost('token');
        if (!empty($postToken)) {
            return $postToken;
        }

        return null;
    }

    /**
     * 返回错误响应
     */
    private function returnError(int $code, string $message, array $data = []): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'code' => $code,
            'msg' => $message,
            'data' => $data
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 记录安全违规日志
     */
    private function logSecurityViolation(?int $userId, string $type, array $details): void
    {
        // TODO: 记录到数据库 w_api_security_log 表
        // 暂时记录到错误日志
        error_log(sprintf(
            '[API Security] User ID: %s, Type: %s, Details: %s, Time: %s, IP: %s',
            $userId ?? 'N/A',
            $type,
            json_encode($details, JSON_UNESCAPED_UNICODE),
            date('Y-m-d H:i:s'),
            $this->request->clientIP()
        ));
    }
}

