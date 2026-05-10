<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Customer\Service\PasswordResetService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;

class ForgotPassword extends BaseController
{
    protected ?string $layoutType = 'account.auth';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly PasswordResetService $passwordResetService
    ) {
    }

    public function index(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->redirect('weshop/customer/account/index');
        }

        $request = $this->getRequest();
        $token = trim((string) ($request->getParam('token') ?? ''));
        $resetRecord = null;
        if ($token !== '') {
            $resetRecord = $this->passwordResetService->validateToken($token);
            if (!$resetRecord) {
                $this->getMessageManager()->addError(__('The reset link is invalid or has expired.'));
                return $this->redirect('weshop/customer/account/forgot-password');
            }
        }

        $this->assign('reset_token', $token);
        $this->assign('is_reset_mode', $resetRecord !== null);
        $this->assign('login_url', $this->getUrl('weshop/customer/account/login'));
        $this->assign('register_url', $this->getUrl('weshop/customer/account/register'));
        $this->assign('title', $resetRecord ? __('重置密码') : __('忘记密码'));

        return $this->fetch();
    }

    public function postIndex(): string
    {
        $email = trim((string) ($this->getRequest()->getPost('email') ?? ''));
        if ($email === '') {
            $this->getMessageManager()->addError(__('Email is required.'));
            return $this->redirect('weshop/customer/account/forgot-password');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->getMessageManager()->addError(__('Please enter a valid email address.'));
            return $this->redirect('weshop/customer/account/forgot-password');
        }

        try {
            $sent = $this->passwordResetService->requestReset(
                $email,
                $this->getUrl('weshop/customer/account/forgot-password')
            );
            if (!$sent) {
                $this->getMessageManager()->addError(__('The email is not registered.'));
                return $this->redirect('weshop/customer/account/forgot-password');
            }
            $this->getMessageManager()->addSuccess(__('A reset link has been sent to your email.'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError(__('Unable to create a password reset request right now.'));
        }

        return $this->redirect('weshop/customer/account/forgot-password');
    }

    public function postResetPassword(): string
    {
        $request = $this->getRequest();
        $token = trim((string) ($request->getPost('token') ?? ''));
        $password = (string) ($request->getPost('password') ?? '');
        $passwordConfirm = (string) ($request->getPost('password_confirm') ?? $request->getPost('confirm_password') ?? '');

        if ($token === '') {
            $this->getMessageManager()->addError(__('The reset token is required.'));
            return $this->redirect('weshop/customer/account/forgot-password');
        }

        if ($password === '') {
            $this->getMessageManager()->addError(__('Please enter a new password.'));
            return $this->redirect('weshop/customer/account/forgot-password?token=' . urlencode($token));
        }

        if ($password !== $passwordConfirm) {
            $this->getMessageManager()->addError(__('The password confirmation does not match.'));
            return $this->redirect('weshop/customer/account/forgot-password?token=' . urlencode($token));
        }

        try {
            $reset = $this->passwordResetService->resetPassword($token, $password);
            if (!$reset) {
                $this->getMessageManager()->addError(__('The reset link is invalid or has expired.'));
                return $this->redirect('weshop/customer/account/forgot-password?token=' . urlencode($token));
            }

            $this->getMessageManager()->addSuccess(__('Your password has been reset. Please sign in with the new password.'));
            return $this->redirect('weshop/customer/account/login');
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('weshop/customer/account/forgot-password?token=' . urlencode($token));
    }

    public function postForgotPassword(): string
    {
        return $this->postIndex();
    }
}
