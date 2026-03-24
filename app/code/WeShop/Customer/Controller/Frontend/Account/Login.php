<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Customer\Service\CustomerWebAuthService;
use WeShop\Customer\Session\CustomerSession;
use WeShop\Frontend\Controller\BaseController;

class Login extends BaseController
{
    protected ?string $layoutType = 'account_auth';

    public function __construct(
        private readonly CustomerSession $customerSession,
        private readonly CustomerWebAuthService $customerWebAuthService
    ) {
    }

    public function index(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return $this->redirect('weshop/customer/account/index');
        }

        $request = $this->getRequest();
        $redirectUrl = (string) ($request->getParam('redirect') ?? $request->getParam('redirect_url') ?? '');
        $this->assign('redirect_url', $redirectUrl);
        $this->assign('register_url', $this->getUrl('weshop/customer/account/register'));
        $this->assign('forgot_password_url', $this->getUrl('weshop/customer/account/forgot-password'));
        $this->assign('title', __('登录'));

        return $this->fetch();
    }

    public function postIndex(): string
    {
        $request = $this->getRequest();
        $email = trim((string) ($request->getPost('email') ?? ''));
        $password = (string) ($request->getPost('password') ?? '');
        $rememberMe = (bool) ($request->getPost('remember_me') ?? $request->getPost('remember') ?? false);
        $redirectUrl = (string) ($request->getPost('redirect_url') ?? $request->getParam('redirect') ?? '');

        if ($email === '' || $password === '') {
            $this->getMessageManager()->addError(__('邮箱和密码不能为空'));
            return $this->redirect('weshop/customer/account/login' . $this->buildRedirectQuery($redirectUrl));
        }

        try {
            $result = $this->customerWebAuthService->beginPasswordLogin(
                $email,
                $password,
                $rememberMe,
                $redirectUrl
            );

            if (($result['status'] ?? '') === 'challenge_required') {
                $this->getMessageManager()->addWarning(__('Please complete two-factor verification to finish sign in.'));
                return $this->redirect(
                    'weshop/customer/account/challenge?challenge_token=' . urlencode((string) ($result['challenge_token'] ?? ''))
                );
            }

            $this->getMessageManager()->addSuccess(__('Login succeeded.'));
            return $this->redirect((string) ($result['redirect_url'] ?? 'weshop/customer/account/index'));
        } catch (\Throwable $e) {
            $this->getMessageManager()->addError($e->getMessage());
        }

        return $this->redirect('weshop/customer/account/login' . $this->buildRedirectQuery($redirectUrl));
    }

    public function postLogin(): string
    {
        return $this->postIndex();
    }

    private function buildRedirectQuery(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '') {
            return '';
        }

        return '?redirect=' . urlencode($redirectUrl);
    }
}
