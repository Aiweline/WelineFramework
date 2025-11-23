<?php

declare(strict_types=1);

namespace Weline\Frontend\Controller\Account;

use Weline\Framework\View\Template;
use Weline\Frontend\Model\FrontendUser;
use Weline\Frontend\Session\FrontendUserSession;

/**
 * 个人中心控制器
 */
class Index extends \Weline\Framework\App\Controller\FrontendController
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
     * 个人中心首页
     */
    public function getIndex()
    {
        // 检查是否登录
        if (!$this->session->isLogin()) {
            // 保存当前URL作为来源
            $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
            $this->redirect('/frontend/account/login?referer=' . urlencode($currentUrl));
            return;
        }

        /** @var FrontendUser $user */
        $user = $this->session->getLoginUser();
        
        // 使用主题仪表盘布局
        return $this->fetch('Weline_Frontend::templates/frontend/account/index.phtml', ['user' => $user]);
    }

    /**
     * 更新个人信息
     */
    public function postUpdate()
    {
        if (!$this->session->isLogin()) {
            return $this->json([
                'success' => false,
                'message' => '请先登录'
            ]);
        }

        /** @var FrontendUser $user */
        $user = $this->session->getLoginUser();

        $avatar = $this->request->getPost('avatar');
        if ($avatar) {
            $user->setAvatar($avatar);
        }

        // 处理密码修改
        $oldPassword = $this->request->getPost('old_password');
        $newPassword = $this->request->getPost('new_password');
        $confirmPassword = $this->request->getPost('confirm_password');

        if ($newPassword) {
            if (empty($oldPassword)) {
                return $this->json([
                    'success' => false,
                    'message' => '请输入原密码'
                ]);
            }

            if (!password_verify($oldPassword, $user->getPassword())) {
                return $this->json([
                    'success' => false,
                    'message' => '原密码错误'
                ]);
            }

            if (strlen($newPassword) < 6) {
                return $this->json([
                    'success' => false,
                    'message' => '新密码长度不能少于6位'
                ]);
            }

            if ($newPassword !== $confirmPassword) {
                return $this->json([
                    'success' => false,
                    'message' => '两次输入的新密码不一致'
                ]);
            }

            $user->setPassword($newPassword);
        }

        try {
            $user->save();
            return $this->json([
                'success' => true,
                'message' => '更新成功'
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => '更新失败：' . $e->getMessage()
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

