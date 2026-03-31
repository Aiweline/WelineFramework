<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use WeShop\Customer\Service\PasswordResetService;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

class ForgotPassword extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account_auth';

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
                $this->getMessageManager()->addError(__('The reset link is invalid or has expired.'));
                return $this->redirect('/customer/account/forgot-password');
            }
        }

        $this->assign('reset_token', $token);
        $this->assign('is_reset_mode', $resetRecord !== null);
        $this->assign('login_url', '/customer/account/login');
        $this->assign('register_url', '/customer/account/register');
        $this->assign('title', $resetRecord ? __('Reset Password') : __('Forgot Password'));

        $this->assign('error_message', MessageManager::get_error_message());
        $this->assign('success_message', MessageManager::get_success_message());

        return $this->fetch('Weline_Customer::templates/frontend/account/forgot-password.phtml');
    }

    public function postIndex(): string
    {
        $email = trim((string) ($this->request->getPost('email') ?? ''));
        if ($email === '') {
            $this->getMessageManager()->addError(__('Email is required.'));
            return $this->redirect('/customer/account/forgot-password');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->getMessageManager()->addError(__('Please enter a valid email address.'));
            return $this->redirect('/customer/account/forgot-password');
        }

        try {
            $sent = $this->passwordResetService->requestReset(
                $email,
                $this->getUrl('customer/account/forgot-password')
            );
            if (!$sent) {
                $this->getMessageManager()->addError(__('The email is not registered.'));
                return $this->redirect('/customer/account/forgot-password');
            }
            $this->getMessageManager()->addSuccess(__('A reset link has been sent to your email.'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError(__('Unable to create a password reset request right now.'));
        }

        return $this->redirect('/customer/account/forgot-password');
    }

    public function postResetPassword(): string
    {
        $token = trim((string) ($this->request->getPost('token') ?? ''));
        $password = (string) ($this->request->getPost('password') ?? '');
        $passwordConfirm = (string) ($this->request->getPost('password_confirm') ?? $this->request->getPost('confirm_password') ?? '');

        if ($token === '') {
            $this->getMessageManager()->addError(__('The reset token is required.'));
            return $this->redirect('/customer/account/forgot-password');
        }

        if ($password === '') {
            $this->getMessageManager()->addError(__('Please enter a new password.'));
            return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
        }

        if ($password !== $passwordConfirm) {
            $this->getMessageManager()->addError(__('The password confirmation does not match.'));
            return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
        }

        try {
            $reset = $this->passwordResetService->resetPassword($token, $password);
            if (!$reset) {
                $this->getMessageManager()->addError(__('The reset link is invalid or has expired.'));
                return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
            }

            $this->getMessageManager()->addSuccess(__('Your password has been reset. Please sign in with the new password.'));
            return $this->redirect('/customer/account/login');
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('/customer/account/forgot-password?token=' . urlencode($token));
    }
}
