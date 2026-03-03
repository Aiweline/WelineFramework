<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Framework\View\Template;
use Weline\Customer\Model\Customer;

/**
 * 个人中心控制器
 */
class Index extends \Weline\Framework\App\Controller\FrontendController
{
    private Template $template;
    protected ?string $layoutType = 'account.dashboard';

    public function __construct(
        Template $template
    ) {
        $this->template = $template;
    }

    /**
     * 个人中心首页
     */
    public function getIndex()
    {
        // 检查是否登录
        if (!$this->isLoggedIn()) {
            // 保存当前URL作为来源
            $currentUrl = $this->request->getUrlBuilder()->getCurrentUrl();
            $this->redirect('/customer/account/login?referer=' . urlencode($currentUrl));
            return;
        }

        // 获取登录用户
        /** @var Customer $user */
        $user = $this->getLoginUser();
        // 设置用户数据
        $this->assign('user', $user);
        
        // 使用 template() 方法渲染侧边栏（不触发事件），然后赋值给模板
        $sidebar = $this->template('Weline_Customer::templates/frontend/account/sidebar/side.phtml');
        $this->assign('sidebar', $sidebar);
        
        // 直接返回主内容模板，ControllerFetchFileBefore 和 ControllerFetchFileAfter 观察者会自动处理布局包装
        return $this->fetch('Weline_Customer::templates/frontend/account/index.phtml');
    }

    /**
     * 更新个人信息
     */
    public function postUpdate()
    {
        if (!$this->isLoggedIn()) {
            return $this->json([
                'success' => false,
                'message' => __('请先登录')
            ]);
        }

        /** @var Customer $user */
        $user = $this->getLoginUser();

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
                    'message' => __('请输入原密码')
                ]);
            }

            if (!password_verify($oldPassword, $user->getPassword())) {
                return $this->json([
                    'success' => false,
                    'message' => __('原密码错误')
                ]);
            }

            if (strlen($newPassword) < 6) {
                return $this->json([
                    'success' => false,
                    'message' => __('新密码长度不能少于6位')
                ]);
            }

            if ($newPassword !== $confirmPassword) {
                return $this->json([
                    'success' => false,
                    'message' => __('两次输入的新密码不一致')
                ]);
            }

            $user->setPassword($newPassword);
        }

        try {
            $user->save();
            return $this->json([
                'success' => true,
                'message' => __('更新成功')
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'success' => false,
                'message' => __('更新失败：%{1}', [$e->getMessage()])
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
