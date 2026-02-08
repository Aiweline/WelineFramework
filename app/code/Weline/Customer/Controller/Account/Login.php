<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Framework\App\Env;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Customer\Model\Customer;
use Weline\Customer\Model\CustomerToken;
use Weline\Customer\Session\CustomerSession;

/**
 * 前端用户登录控制器
 */
class Login extends \Weline\Framework\App\Controller\FrontendController
{
    private CustomerSession $session;
    private Template $template;

    protected ?string $layoutType = 'account.auth';

    public function __construct(
        CustomerSession $session,
        Template $template
    ) {
        $this->session = $session;
        $this->template = $template;
    }

    /**
     * 显示登录页面
     */
    public function getIndex()
    {
        // 如果已登录，跳转到个人中心
        if ($this->session->isLogin()) {
            $this->redirect('/customer/account');
        }

        // 保存来源URL（referer）到session
        $referer = $this->request->getParam('referer') ?: $this->request->getReferer();
        if ($referer && !str_contains($referer, '/account/login')) {
            $this->session->setData('login_referer', $referer);
        }

        // 使用主题认证布局
        return $this->fetch('Weline_Customer::templates/frontend/account/login.phtml');
    }

    /**
     * 处理登录请求
     */
    public function postIndex()
    {
        if ($this->session->isLogin()) {
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

        if (empty($username) || empty($password)) {
            return $this->json([
                'success' => false,
                'message' => __('用户名和密码不能为空')
            ]);
        }

        try {
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
            $user->setSessionId($this->session->getSessionId())
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
            $referer = $this->session->getData('login_referer');
            $this->session->delete('login_referer');

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
                error_log('登录异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
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
