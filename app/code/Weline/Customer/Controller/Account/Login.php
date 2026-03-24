<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use WeShop\Customer\Service\CustomerWebAuthService;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;

/**
 * 前端用户登录控制器
 */
class Login extends \Weline\Framework\App\Controller\FrontendController
{
    private Template $template;

    protected ?string $layoutType = 'account_auth';

    public function __construct(
        Template $template
    ) {
        $this->template = $template;
    }

    /**
     * 显示登录页面
     */
    public function getIndex()
    {
        // 如果已登录，跳转到个人中心
        if ($this->isLoggedIn()) {
            $this->redirect('/customer/account');
        }

        // 保存来源URL（referer）到session
        $referer = $this->request->getParam('referer') ?: $this->request->getReferer();
        if ($referer && !str_contains($referer, '/account/login')) {
            $this->session->getSession()->set('login_referer', $referer);
        }

        // 使用主题认证布局
        $redirectUrl = trim((string) ($this->request->getParam('redirect_url') ?? $this->request->getParam('redirect') ?? ''));
        if ($redirectUrl === '' && is_string($referer) && $referer !== '') {
            $redirectUrl = $referer;
        }

        $this->assign('redirect_url', $redirectUrl);
        $this->assign('title', __('鐢ㄦ埛鐧诲綍'));

        return $this->fetch('Weline_Customer::templates/frontend/account/login.phtml');
    }

    /**
     * 处理登录请求
     */
    public function postIndex()
    {
        if ($this->isLoggedIn()) {
            return $this->json([
                'success' => true,
                'message' => __('您已登录'),
                'redirect' => '/customer/account'
            ]);
        }

        $username = $this->request->getBodyParam('username');
        if ($username === null) {
            $username = $this->request->getPost('username');
        }
        $username = is_string($username) ? trim($username) : '';

        $password = $this->request->getBodyParam('password');
        if ($password === null) {
            $password = $this->request->getPost('password');
        }
        $password = is_string($password) ? $password : '';

        $rememberDuration = $this->request->getBodyParam('remember_duration');
        if ($rememberDuration === null) {
            $rememberDuration = $this->request->getPost('remember_duration', 0);
        }
        $rememberDuration = (int)$rememberDuration;

        $redirectUrl = $this->request->getBodyParam('redirect_url');
        if ($redirectUrl === null) {
            $redirectUrl = $this->request->getPost('redirect_url', '');
        }
        $redirectUrl = $this->normalizeRedirectTarget(is_string($redirectUrl) ? $redirectUrl : '');

        if (empty($username) || empty($password)) {
            return $this->json([
                'success' => false,
                'message' => __('用户名和密码不能为空')
            ]);
        }

        try {
            $weShopResponse = $this->handleWeShopPasswordLogin($username, $password, $rememberDuration, $redirectUrl);
            if ($weShopResponse !== null) {
                return $weShopResponse;
            }

            /** @var Customer $user */
            $user = ObjectManager::getInstance(Customer::class);
            $user->where('username', $username)->find()->fetch();

            if (!$user->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => __('用户不存在')
                ]);
            }

            // 检查登录尝试次数
            if ($user->getAttemptTimes() > 5) {
                return $this->json([
                    'success' => false,
                    'message' => __('登录尝试次数过多，请稍后再试')
                ]);
            }

            // 验证密码
            if (!password_verify($password, $user->getPassword())) {
                $user->addAttemptTimes()
                    ->setAttemptIp($this->request->clientIP())
                    ->save();
                
                return $this->json([
                    'success' => false,
                    'message' => __('密码错误')
                ]);
            }

            // 登录成功
            $this->session->login($user);
            $user->setSessionId($this->session->getSession()->getSessionId())
                ->setLoginIp($this->request->clientIP())
                ->resetAttemptTimes()
                ->save();
            $this->syncSandboxCookie($user->isSandboxAccount());

            // 处理"记住我"功能
            if ($rememberDuration > 0) {
                $token = CustomerToken::generateToken();
                $expireTime = time() + $rememberDuration;
                
                // 保存token到数据库
                /** @var CustomerToken $userToken */
                $userToken = ObjectManager::getInstance(CustomerToken::class);
                
                // 删除该用户的旧token
                $userToken->builder()
                    ->where('user_id', $user->getId())
                    ->where('type', 'remember_me')
                    ->delete();
                
                // 创建新token
                $userToken->reset()
                    ->setUserId($user->getId())
                    ->setToken($token)
                    ->setType('remember_me')
                    ->setTokenExpireTime($expireTime)
                    ->save();
                
                // 设置cookie
                Cookie::set('w_ut', $token, $rememberDuration, ['path' => '/']);
            }

            // 派发登录成功事件
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = new \Weline\Framework\DataObject\DataObject([
                'user' => $user,
                'request' => $this->request,
                'session' => $this->session
            ]);
            $eventManager->dispatch('Weline_Customer_Account_Login::login_after', $eventData);

            // 获取来源URL
            $referer = $this->session->getSession()->get('login_referer');
            $this->session->getSession()->delete('login_referer');

            // 确定跳转地址
            $redirectUrl = '/customer/account';
            if ($referer && $this->isValidReferer($referer)) {
                $redirectUrl = $referer;
            }

            return $this->json([
                'success' => true,
                'message' => __('登录成功'),
                'redirect' => $redirectUrl,
                'user' => [
                    'user_id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                    'is_sandbox' => $user->isSandboxAccount(),
                ],
            ]);

        } catch (\Exception $e) {
            // 记录异常日志
            if (defined('DEV') && DEV) {
                w_log_error('登录异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            return $this->json([
                'success' => false,
                'message' => __('登录失败：%{1}', [$e->getMessage()])
            ]);
        }
    }

    private function syncSandboxCookie(bool $enabled): void
    {
        $lifetime = $enabled ? 0 : -1;
        Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/']);
        $adminPath = Env::getAreaRoutePrefix('backend') ?? '';
        if (!empty($adminPath)) {
            Cookie::set('w_sandbox', $enabled ? '1' : '', $lifetime, ['path' => '/' . ltrim($adminPath, '/')]);
        }
    }

    private function handleWeShopPasswordLogin(string $username, string $password, int $rememberDuration, string $redirectUrl): ?string
    {
        if (!str_contains($username, '@')) {
            return null;
        }

        try {
            /** @var CustomerWebAuthService $customerWebAuthService */
            $customerWebAuthService = ObjectManager::getInstance(CustomerWebAuthService::class);
        } catch (\Throwable $throwable) {
            throw new \RuntimeException(
                (string) __('WeShop login bridge is unavailable: %{1}', [$throwable->getMessage()]),
                previous: $throwable
            );
        }

        $effectiveRedirect = $redirectUrl;
        if ($effectiveRedirect === '') {
            $effectiveRedirect = $this->normalizeRedirectTarget($this->getStoredLoginReferer());
        }

        $result = $customerWebAuthService->beginPasswordLogin(
            $username,
            $password,
            $rememberDuration > 0,
            $effectiveRedirect,
            $rememberDuration > 0 ? $rememberDuration : 604800
        );

        $this->clearStoredLoginReferer();

        if (($result['status'] ?? '') === 'challenge_required') {
            $challengeToken = (string) ($result['challenge_token'] ?? '');
            $challengePath = $challengeToken !== ''
                ? 'weshop/customer/account/challenge?challenge_token=' . rawurlencode($challengeToken)
                : 'weshop/customer/account/login';

            return $this->json([
                'success' => true,
                'status' => 'challenge_required',
                'requires_challenge' => true,
                'message' => __('Please complete two-factor verification to finish sign in.'),
                'redirect' => $this->formatClientRedirect($challengePath),
            ]);
        }

        return $this->json([
            'success' => true,
            'status' => 'authenticated',
            'message' => __('Login succeeded.'),
            'redirect' => $this->formatClientRedirect((string) ($result['redirect_url'] ?? $effectiveRedirect)),
        ]);
    }

    private function getStoredLoginReferer(): string
    {
        $referer = $this->session->getSession()->get('login_referer');
        return is_string($referer) ? trim($referer) : '';
    }

    private function clearStoredLoginReferer(): void
    {
        $this->session->getSession()->delete('login_referer');
    }

    private function normalizeRedirectTarget(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '' || str_starts_with($redirectUrl, '//')) {
            return '';
        }

        if ((bool) preg_match('/^[a-z][a-z0-9+.-]*:/i', $redirectUrl)) {
            $absoluteUrl = $redirectUrl;
            if (!$this->isValidReferer($redirectUrl)) {
                return '';
            }

            $path = trim((string) (parse_url($redirectUrl, PHP_URL_PATH) ?? ''), '/');
            if ($path === '') {
                return '';
            }

            $redirectUrl = $path;
            $query = trim((string) (parse_url($absoluteUrl, PHP_URL_QUERY) ?? ''));
            if ($query !== '') {
                $redirectUrl .= '?' . $query;
            }
        } else {
            $redirectUrl = ltrim($redirectUrl, '/');
        }

        $redirectUrl = ltrim($redirectUrl, '/');
        if ($redirectUrl === '') {
            return '';
        }

        if (preg_match('#^(customer/account/login|weshop/customer/account/login)(\\?|$)#', $redirectUrl) === 1) {
            return '';
        }

        return $redirectUrl;
    }

    private function formatClientRedirect(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '') {
            return '/customer/account';
        }

        if ($this->isValidReferer($redirectUrl) && str_contains($redirectUrl, '://')) {
            return $redirectUrl;
        }

        $normalized = ltrim($redirectUrl, '/');
        if ($normalized === '' || $normalized === 'weshop/customer/account/index' || $normalized === 'customer/account/index') {
            return '/customer/account';
        }

        return '/' . $normalized;
    }

    /**
     * 验证referer是否有效
     */
    private function isValidReferer(string $referer): bool
    {
        // 只允许站内跳转
        $baseUrl = Env::getInstance()->getBaseUrl();
        return str_starts_with($referer, $baseUrl) || str_starts_with($referer, '/');
    }

    /**
     * 返回JSON响应
     */
    private function json(array $data): string
    {
        header('Content-Type: application/json');
        return json_encode($data, JSON_UNESCAPED_UNICODE);
    }
}
