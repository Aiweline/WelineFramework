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

        $sidebar = $this->template('Weline_Customer::templates/frontend/account/sidebar/side.phtml');
        $this->assign('sidebar', $sidebar);

        $existingMeta = $this->getData('meta');
        if (!is_array($existingMeta)) {
            $existingMeta = [];
        }
        $this->assign('meta', array_merge($existingMeta, [
            'user' => $user,
            'sidebar' => $sidebar,
            'showHeader' => true,
            'showFooter' => true,
        ]));

        return $this->fetch('Weline_Customer::templates/frontend/account/index.phtml');
    }

    /**
     * 更新个人信息
     */
    public function postUpdate()
    {
        if (!$this->isLoggedIn()) {
            return $this->fetchJson([
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
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('请输入原密码')
                ]);
            }

            if (!password_verify($oldPassword, $user->getPassword())) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('原密码错误')
                ]);
            }

            if (strlen($newPassword) < 6) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('新密码长度不能少于6位')
                ]);
            }

            if ($newPassword !== $confirmPassword) {
                return $this->fetchJson([
                    'success' => false,
                    'message' => __('两次输入的新密码不一致')
                ]);
            }

            $user->setPassword($newPassword);
        }

        try {
            $user->save();
        } catch (\Throwable $throwable) {
            return $this->fetchJson([
                'success' => false,
                'message' => $this->buildUpdateFailureMessage($throwable)
            ]);
        }

        return $this->fetchJson([
            'success' => true,
            'message' => __('更新成功')
        ]);
    }

    private function buildUpdateFailureMessage(\Throwable $throwable): string
    {
        $message = html_entity_decode((string) $throwable->getMessage(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $message = trim((string) preg_replace('/^[：:,，\s]+/u', '', $message));

        if ($message === '' || $message === (string) __('请稍后重试')) {
            return (string) __('更新失败，请稍后重试');
        }

        return (string) __('更新失败：%{1}', [$message]);
    }
}
