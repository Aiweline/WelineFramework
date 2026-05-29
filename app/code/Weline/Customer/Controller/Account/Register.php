<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Service\CustomerAccountService;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

/**
 * Public storefront registration for core customer accounts.
 */
class Register extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account.auth';

    public function __construct(
        private readonly Template $template,
        private readonly CustomerAccountService $customerAccountService
    ) {
    }

    public function getIndex(): string
    {
        if ($this->isLoggedIn()) {
            return (string) $this->redirect('/customer/account');
        }

        $this->assign('login_url', '/customer/account/login');
        $this->assign('title', __('创建账户'));

        $referralCode = $this->readReferralCode();
        if ($referralCode !== '') {
            $this->assign('referral_code', $referralCode);
        }

        return (string) $this->fetch('Weline_Customer::templates/frontend/account/register.phtml');
    }

    public function postIndex(): string
    {
        if ($this->isLoggedIn()) {
            MessageManager::warning(__('你已经登录了，请先退出登录。'));
            return (string) $this->redirect('/customer/account');
        }

        $firstName = trim((string) ($this->request->getPost('firstname') ?? $this->request->getPost('first_name') ?? ''));
        $lastName = trim((string) ($this->request->getPost('lastname') ?? $this->request->getPost('last_name') ?? ''));
        $email = trim((string) ($this->request->getPost('email') ?? $this->request->getPost('username') ?? ''));
        $password = (string) ($this->request->getPost('password') ?? '');
        $confirmPassword = (string) ($this->request->getPost('confirm_password') ?? '');
        $agreeTerms = (bool) ($this->request->getPost('agree_terms') ?? false);
        $referralCode = $this->readReferralCode();

        if ($firstName === '' || $lastName === '') {
            MessageManager::error(__('请填写名和姓。'));
            return (string) $this->redirect('/customer/account/register');
        }

        if ($email === '') {
            MessageManager::error(__('请填写邮箱。'));
            return (string) $this->redirect('/customer/account/register');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            MessageManager::error(__('请输入有效的邮箱地址。'));
            return (string) $this->redirect('/customer/account/register');
        }

        if ($password !== $confirmPassword) {
            MessageManager::error(__('两次输入的密码不一致。'));
            return (string) $this->redirect('/customer/account/register');
        }

        if (!$agreeTerms) {
            MessageManager::error(__('请同意服务条款与隐私政策。'));
            return (string) $this->redirect('/customer/account/register');
        }

        try {
            $profileData = [
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
            if ($referralCode !== '') {
                $profileData['referral_code'] = $referralCode;
            }
            $result = $this->customerAccountService->register($email, $password, $profileData);
            $this->customerAccountService->loginCustomer($result['customer']);
            MessageManager::success(__('注册成功，欢迎加入。'));
            return (string) $this->redirect('/customer/account');
        } catch (\Throwable $throwable) {
            MessageManager::error($throwable->getMessage());
        }

        return (string) $this->redirect('/customer/account/register');
    }

    private function readReferralCode(): string
    {
        if (!isset($this->request)) {
            return '';
        }

        return trim((string) (
            $this->request->getPost('ref')
            ?? $this->request->getParam('ref')
            ?? $this->request->getPost('referral_code')
            ?? $this->request->getParam('referral_code')
            ?? ''
        ));
    }
}
