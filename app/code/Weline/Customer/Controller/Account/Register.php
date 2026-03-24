<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use WeShop\Customer\Service\CustomerAccountService;
use Weline\Framework\View\Template;

/**
 * Public storefront registration bridge backed by the WeShop customer account service.
 */
class Register extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account_auth';

    public function __construct(
        private readonly Template $template,
        private readonly CustomerAccountService $customerAccountService
    ) {
    }

    public function getIndex(): string
    {
        if ($this->isLoggedIn()) {
            return $this->redirect('/customer/account');
        }

        $this->assign('login_url', '/customer/account/login');
        $this->assign('title', __('Create Account'));

        return $this->fetch('Weline_Customer::templates/frontend/account/register.phtml');
    }

    public function postIndex(): string
    {
        if ($this->isLoggedIn()) {
            $this->getMessageManager()->addWarning(__('You are already signed in.'));
            return $this->redirect('/customer/account');
        }

        $firstName = trim((string) ($this->request->getPost('firstname') ?? $this->request->getPost('first_name') ?? ''));
        $lastName = trim((string) ($this->request->getPost('lastname') ?? $this->request->getPost('last_name') ?? ''));
        $email = trim((string) ($this->request->getPost('email') ?? $this->request->getPost('username') ?? ''));
        $password = (string) ($this->request->getPost('password') ?? '');
        $confirmPassword = (string) ($this->request->getPost('confirm_password') ?? '');
        $agreeTerms = (bool) ($this->request->getPost('agree_terms') ?? false);

        if ($firstName === '' || $lastName === '') {
            $this->getMessageManager()->addError(__('First name and last name are required.'));
            return $this->redirect('/customer/account/register');
        }

        if ($email === '') {
            $this->getMessageManager()->addError(__('Email is required.'));
            return $this->redirect('/customer/account/register');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->getMessageManager()->addError(__('Please enter a valid email address.'));
            return $this->redirect('/customer/account/register');
        }

        if ($password !== $confirmPassword) {
            $this->getMessageManager()->addError(__('The password confirmation does not match.'));
            return $this->redirect('/customer/account/register');
        }

        if (!$agreeTerms) {
            $this->getMessageManager()->addError(__('Please accept the terms and privacy policy.'));
            return $this->redirect('/customer/account/register');
        }

        try {
            $result = $this->customerAccountService->register($email, $password, [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);
            $this->customerAccountService->login($result['auth_user']);
            $this->getMessageManager()->addSuccess(__('Registration succeeded. Welcome to WeShop.'));
            return $this->redirect('/customer/account');
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
        }

        return $this->redirect('/customer/account/register');
    }
}
