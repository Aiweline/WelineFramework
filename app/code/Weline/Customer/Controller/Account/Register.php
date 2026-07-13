<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Service\CustomerAccountService;
use Weline\Customer\Service\CustomerAuthReturnUrlService;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\View\Template;

/**
 * Public storefront registration for core customer accounts.
 */
class Register extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account.auth';

    private readonly CustomerAuthReturnUrlService $authReturnUrlService;

    public function __construct(
        private readonly Template $template,
        private readonly CustomerAccountService $customerAccountService,
        ?CustomerAuthReturnUrlService $authReturnUrlService = null
    ) {
        $this->authReturnUrlService = $authReturnUrlService
            ?? ObjectManager::getInstance(CustomerAuthReturnUrlService::class);
    }

    public function getIndex(): string
    {
        if ($this->isLoggedIn()) {
            return (string) $this->redirect('/customer/account');
        }

        $explicitTarget = $this->request->getParam('redirect_url') ?? $this->request->getParam('redirect') ?? '';
        $referer = $this->request->getParam('referer') ?: $this->request->getReferer();
        $redirectUrl = $this->authReturnUrlService->capture(
            $this->session,
            is_string($explicitTarget) ? $explicitTarget : '',
            is_string($referer) ? $referer : ''
        );

        $this->assign('redirect_url', $redirectUrl);
        $this->assign(
            'login_url',
            $this->authReturnUrlService->buildAuthPageUrl('customer/account/login', $redirectUrl)
        );
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
        $redirectCandidate = $this->request->getPost('redirect_url') ?? $this->request->getPost('redirect') ?? '';
        $redirectUrl = $this->authReturnUrlService->resolve(
            $this->session,
            is_string($redirectCandidate) ? $redirectCandidate : ''
        );
        $registerUrl = $this->authReturnUrlService->buildAuthPageUrl(
            'customer/account/register',
            $redirectUrl,
            $referralCode !== '' ? ['ref' => $referralCode] : []
        );

        if ($firstName === '' || $lastName === '') {
            MessageManager::error(__('请填写名和姓。'));
            return (string) $this->redirect($registerUrl);
        }

        if ($email === '') {
            MessageManager::error(__('请填写邮箱。'));
            return (string) $this->redirect($registerUrl);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            MessageManager::error(__('请输入有效的邮箱地址。'));
            return (string) $this->redirect($registerUrl);
        }

        if ($password !== $confirmPassword) {
            MessageManager::error(__('两次输入的密码不一致。'));
            return (string) $this->redirect($registerUrl);
        }

        if (!$agreeTerms) {
            MessageManager::error(__('请同意服务条款与隐私政策。'));
            return (string) $this->redirect($registerUrl);
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
            $returnTarget = $this->authReturnUrlService->consume($this->session, $redirectUrl);
            return (string) $this->redirect($this->authReturnUrlService->formatRedirect($returnTarget));
        } catch (\Throwable $throwable) {
            MessageManager::error($throwable->getMessage());
        }

        return (string) $this->redirect($registerUrl);
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
