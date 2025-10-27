<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Account;

use Weline\Framework\App\Env;
use Weline\Framework\Http\Cookie;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\View\Template;
use Weline\Frontend\Model\FrontendUser;
use Weline\Frontend\Model\FrontendUserToken;
use Weline\Frontend\Session\FrontendUserSession;

/**
 * 前端用户登录控制器
 */
class Login extends \Weline\Framework\App\Controller\FrontendController
{
    private FrontendUserSession $session;
    private Template $template;

    public function __construct(
        FrontendUserSession $session,
        Template $template
    ) {
        $this->session = $session;
        $this->template = $template;
        parent::__construct();
    }

    /**
     * 显示登录页面
     */
    public function getIndex()
    {
        // 如果已登录，跳转到个人中心
        if ($this->session->isLogin()) {
            $this->redirect('/frontend/account');
        }

        // 保存来源URL（referer）到session
        $referer = $this->request->getParam('referer') ?: $this->request->getReferer();
        if ($referer && !str_contains($referer, '/account/login')) {
            $this->session->setData('login_referer', $referer);
        }

        return $this->template->setFile('Weline_Frontend::account/login.phtml')->toHtml();
    }

    /**
     * 处理登录请求
     */
    public function postIndex()
    {
        if ($this->session->isLogin()) {
            return $this->json([
                'success' => true,
                'message' => '您已登录',
                'redirect' => '/frontend/account'
            ]);
        }

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');
        $rememberDuration = (int)$this->request->getPost('remember_duration', 0);

        if (empty($username) || empty($password)) {
            return $this->json([
                'success' => false,
                'message' => '用户名和密码不能为空'
            ]);
        }

        try {
            /** @var FrontendUser $user */
            $user = ObjectManager::getInstance(FrontendUser::class);
            $user->where('username', $username)->find()->fetch();

            if (!$user->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => '用户不存在'
                ]);
            }

            // 检查登录尝试次数
            if ($user->getAttemptTimes() > 5) {
                return $this->json([
                    'success' => false,
                    'message' => '登录尝试次数过多，请稍后再试'
                ]);
            }

            // 验证密码
            if (!password_verify($password, $user->getPassword())) {
                $user->addAttemptTimes()
                    ->setAttemptIp($this->request->clientIP())
                    ->save();
                
                return $this->json([
                    'success' => false,
                    'message' => '密码错误'
                ]);
            }

            // 登录成功
            $this->session->login($user);
            $user->setSessionId($this->session->getSessionId())
                ->setLoginIp($this->request->clientIP())
                ->resetAttemptTimes()
                ->save();

            // 处理"记住我"功能
            if ($rememberDuration > 0) {
                $token = FrontendUserToken::generateToken();
                $expireTime = time() + $rememberDuration;
                
                // 保存token到数据库
                /** @var FrontendUserToken $userToken */
                $userToken = ObjectManager::getInstance(FrontendUserToken::class);
                
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
                Cookie::set('frontend_user_token', $token, $rememberDuration, ['path' => '/']);
            }

            // 获取来源URL
            $referer = $this->session->getData('login_referer');
            $this->session->delete('login_referer');

            // 确定跳转地址
            $redirectUrl = '/frontend/account';
            if ($referer && $this->isValidReferer($referer)) {
                $redirectUrl = $referer;
            }

            return $this->json([
                'success' => true,
                'message' => '登录成功',
                'redirect' => $redirectUrl
            ]);

        } catch (\Exception $e) {
            Printing::exception($e);
            return $this->json([
                'success' => false,
                'message' => '登录失败：' . $e->getMessage()
            ]);
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

