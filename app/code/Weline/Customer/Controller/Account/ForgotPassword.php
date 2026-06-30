<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Service\PasswordResetService;
use Weline\Framework\View\Template;

class ForgotPassword extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account.auth';

    public function __construct(
        private readonly Template $template,
        private readonly PasswordResetService $passwordResetService
    ) {
    }

    public function getIndex(): string
    {
        if ($this->isLoggedIn()) {
            return $this->redirect('/customer/account');
        }

        $token = trim((string) ($this->request->getParam('token') ?? ''));
        $resetRecord = null;
        if ($token !== '') {
            $resetRecord = $this->passwordResetService->validateToken($token);
            if (!$resetRecord) {
                $this->getMessageManager()->addError(__('重置链接无效或已过期。'));
                return $this->redirect('/customer/account/forgot-password');
            }
        }

        $this->assign('reset_token', $token);
        $this->assign('is_reset_mode', $resetRecord !== null);
        $this->assign('login_url', '/customer/account/login');
        $this->assign('register_url', '/customer/account/register');
        $this->assign('title', $resetRecord ? __('重置密码') : __('忘记密码'));

        return $this->fetch('Weline_Customer::templates/frontend/account/forgot-password.phtml');
    }

    public function postIndex(): string
    {
        $email = trim((string) ($this->request->getPost('email') ?? ''));
        if ($email === '') {
            $this->getMessageManager()->addError(__('请填写邮箱。'));
            return $this->redirect('/customer/account/forgot-password');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->getMessageManager()->addError(__('请输入有效的邮箱地址。'));
            return $this->redirect('/customer/account/forgot-password');
        }

        try {
            $sent = $this->passwordResetService->requestReset(
                $email,
                $this->getUrl('customer/account/forgot-password')
            );
            if (!$sent) {
                $this->getMessageManager()->addError(__('该邮箱尚未注册。'));
                return $this->redirect('/customer/account/forgot-password');
            }
            $this->getMessageManager()->addSuccess(__('重置链接已发送至您的邮箱。'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(__('暂时无法创建密码重置请求。'));
        }

        return $this->redirect('/customer/account/forgot-password');
    }

    public function postResetPassword(): string
    {
        $token = trim((string) ($this->request->getPost('token') ?? ''));
        $password = (string) ($this->request->getPost('password') ?? '');
        $passwordConfirm = (string) ($this->request->getPost('password_confirm') ?? $this->request->getPost('confirm_password') ?? '');

        if ($token === '') {
            $this->getMessageManager()->addError(__('缺少重置令牌。'));
            return $this->redirect('/customer/account/forgot-password');
        }

        if ($password === '') {
            $this->getMessageManager()->addError(__('请输入新密码。'));
            return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
        }

        if ($password !== $passwordConfirm) {
            $this->getMessageManager()->addError(__('两次输入的密码不一致。'));
            return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
        }

        try {
            $reset = $this->passwordResetService->resetPassword($token, $password);
            if (!$reset) {
                $this->getMessageManager()->addError(__('重置链接无效或已过期。'));
                return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
            }

            $this->getMessageManager()->addSuccess(__('密码已重置，请使用新密码登录。'));
            return $this->redirect('/customer/account/login');
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
    }
}
