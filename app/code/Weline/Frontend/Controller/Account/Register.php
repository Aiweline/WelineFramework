<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Account;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\View\Template;
use Weline\Frontend\Model\FrontendUser;
use Weline\Frontend\Session\FrontendUserSession;

/**
 * 前端用户注册控制器
 */
class Register extends \Weline\Framework\App\Controller\FrontendController
{
    private FrontendUserSession $session;
    private Template $template;

    public function __construct(
        FrontendUserSession $session,
        Template $template
    ) {
        $this->session = $session;
        $this->template = $template;
    }

    /**
     * 显示注册页面
     */
    public function getIndex()
    {
        // 如果已登录，跳转到个人中心
        if ($this->session->isLogin()) {
            $this->redirect('/frontend/account');
        }

        // 使用主题认证布局
        return $this->fetch('Weline_Theme::theme/frontend/layouts/account/auth.phtml', [
            'title' => __('用户注册'),
            'content' => $this->fetch('Weline_Frontend::templates/frontend/account/register.phtml')
        ]);
    }

    /**
     * 处理注册请求
     */
    public function postIndex()
    {
        if ($this->session->isLogin()) {
            return $this->json([
                'success' => false,
                'message' => '您已登录，无需注册'
            ]);
        }

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');
        $confirmPassword = $this->request->getPost('confirm_password');

        // 验证输入
        if (empty($username) || empty($password) || empty($confirmPassword)) {
            return $this->json([
                'success' => false,
                'message' => '请填写完整信息'
            ]);
        }

        if (strlen($username) < 3 || strlen($username) > 20) {
            return $this->json([
                'success' => false,
                'message' => '用户名长度必须在3-20个字符之间'
            ]);
        }

        if (strlen($password) < 6) {
            return $this->json([
                'success' => false,
                'message' => '密码长度不能少于6位'
            ]);
        }

        if ($password !== $confirmPassword) {
            return $this->json([
                'success' => false,
                'message' => '两次输入的密码不一致'
            ]);
        }

        try {
            // 检查用户名是否已存在
            /** @var FrontendUser $existingUser */
            $existingUser = ObjectManager::getInstance(FrontendUser::class);
            $existingUser->where('username', $username)->find()->fetch();

            if ($existingUser->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => '用户名已被使用'
                ]);
            }

            // 创建新用户
            /** @var FrontendUser $newUser */
            $newUser = ObjectManager::getInstance(FrontendUser::class);
            $newUser->setUsername($username)
                ->setPassword($password)
                ->setData('avatar', 'default-svg')  // 使用内联SVG标记
                ->save();

            // 自动登录
            $this->session->login($newUser);
            $newUser->setSessionId($this->session->getSessionId())
                ->setLoginIp($this->request->clientIP())
                ->save();

            // 派发注册成功事件
            /** @var EventsManager $eventManager */
            $eventManager = ObjectManager::getInstance(EventsManager::class);
            $eventData = new \Weline\Framework\DataObject\DataObject([
                'user' => $newUser,
                'request' => $this->request,
                'session' => $this->session
            ]);
            $eventManager->dispatch('Frontend_Account_Register::register_after', $eventData);

            return $this->json([
                'success' => true,
                'message' => '注册成功',
                'redirect' => '/frontend/account'
            ]);

        } catch (\Exception $e) {
            Printing::exception($e);
            return $this->json([
                'success' => false,
                'message' => '注册失败：' . $e->getMessage()
            ]);
        }
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

