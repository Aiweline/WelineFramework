<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;
use Weline\Customer\Model\Customer;

/**
 * 前端用户注册控制器
 */
class Register extends \Weline\Framework\App\Controller\FrontendController
{
    private Template $template;

    public function __construct(
        Template $template
    ) {
        $this->template = $template;
    }

    /**
     * 显示注册页面
     */
    public function getIndex()
    {
        // 如果已登录，跳转到个人中心
        if ($this->isLoggedIn()) {
            $this->redirect('/customer/account');
        }

        // 使用主题认证布局
        return $this->renderAuthLayout(
            'account/register.phtml',
            __('用户注册')
        );
    }

    /**
     * 处理注册请求
     */
    public function postIndex()
    {
        if ($this->isLoggedIn()) {
            return $this->json([
                'success' => false,
                'message' => __('您已登录，无需注册')
            ]);
        }

        $username = $this->request->getPost('username');
        $password = $this->request->getPost('password');
        $confirmPassword = $this->request->getPost('confirm_password');

        // 验证输入
        if (empty($username) || empty($password) || empty($confirmPassword)) {
            return $this->json([
                'success' => false,
                'message' => __('请填写完整信息')
            ]);
        }

        if (strlen($username) < 3 || strlen($username) > 20) {
            return $this->json([
                'success' => false,
                'message' => __('用户名长度必须在3-20个字符之间')
            ]);
        }

        if (strlen($password) < 6) {
            return $this->json([
                'success' => false,
                'message' => __('密码长度不能少于6位')
            ]);
        }

        if ($password !== $confirmPassword) {
            return $this->json([
                'success' => false,
                'message' => __('两次输入的密码不一致')
            ]);
        }

        try {
            // 检查用户名是否已存在
            /** @var Customer $existingUser */
            $existingUser = ObjectManager::getInstance(Customer::class);
            $existingUser->where('username', $username)->find()->fetch();

            if ($existingUser->getId()) {
                return $this->json([
                    'success' => false,
                    'message' => __('用户名已被使用')
                ]);
            }

            // 创建新用户
            /** @var Customer $newUser */
            $newUser = ObjectManager::getInstance(Customer::class);
            $newUser->setUsername($username)
                ->setPassword($password)
                ->setData('avatar', 'default-svg')  // 使用内联SVG标记
                ->save();

            // 自动登录
            $this->session->login($newUser);
            $newUser->setSessionId($this->session->getSession()->getSessionId())
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
            $eventManager->dispatch('Weline_Customer_Account_Register::register_after', $eventData);

            return $this->json([
                'success' => true,
                'message' => __('注册成功'),
                'redirect' => '/customer/account'
            ]);

        } catch (\Exception $e) {
            // 记录异常日志
            if (defined('DEV') && DEV) {
                w_log_error('注册异常: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
            return $this->json([
                'success' => false,
                'message' => __('注册失败：%{1}', [$e->getMessage()])
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
