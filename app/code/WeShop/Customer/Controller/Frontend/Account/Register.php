<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;

class Register extends BaseController
{
    protected ?string $layoutType = 'account_auth';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerAccountService $customerAccountService
    ) {
    }

    public function index(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->redirect('weshop/customer/account/index');
        }

        $this->assign('login_url', $this->getUrl('weshop/customer/account/login'));
        $this->assign('title', __('注册'));

        return $this->fetch();
    }

    public function postIndex(): string
    {
        $request = $this->getRequest();
        $firstName = trim((string) ($request->getPost('firstname') ?? $request->getPost('first_name') ?? ''));
        $lastName = trim((string) ($request->getPost('lastname') ?? $request->getPost('last_name') ?? ''));
        $email = trim((string) ($request->getPost('email') ?? ''));
        $password = (string) ($request->getPost('password') ?? '');
        $passwordConfirm = (string) ($request->getPost('password_confirm') ?? $request->getPost('confirm_password') ?? '');
        $agreeTerms = (bool) ($request->getPost('agree_terms') ?? false);

        if ($firstName === '' || $lastName === '') {
            $this->getMessageManager()->addError(__('First name and last name are required.'));
            return $this->redirect('weshop/customer/account/register');
        }

        if ($email === '') {
            $this->getMessageManager()->addError(__('Email is required.'));
            return $this->redirect('weshop/customer/account/register');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->getMessageManager()->addError(__('Please enter a valid email address.'));
            return $this->redirect('weshop/customer/account/register');
        }

        if ($password !== $passwordConfirm) {
            $this->getMessageManager()->addError(__('The password confirmation does not match.'));
            return $this->redirect('weshop/customer/account/register');
        }

        if (!$agreeTerms) {
            $this->getMessageManager()->addError(__('Please accept the terms and privacy policy.'));
            return $this->redirect('weshop/customer/account/register');
        }

        try {
            $result = $this->customerAccountService->register($email, $password, [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]);

            $this->customerAccountService->login($result['auth_user']);
            $this->getMessageManager()->addSuccess(__('Registration succeeded. Welcome to WeShop.'));
            return $this->redirect('weshop/customer/account/index');
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('weshop/customer/account/register');
    }

    public function postRegister(): string
    {
        return $this->postIndex();
    }
}
