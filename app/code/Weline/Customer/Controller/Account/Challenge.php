<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Customer\Service\CustomerWebAuthService;
use Weline\Framework\Manager\MessageManager;
use Weline\Framework\View\Template;

class Challenge extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account_auth';

    public function __construct(
        private readonly Template $template,
        private readonly CustomerWebAuthService $customerWebAuthService
    ) {
    }

    public function getIndex(): string
    {
        $challengeToken = trim((string) ($this->request->getParam('challenge_token') ?? ''));
        if ($challengeToken === '') {
            $this->getMessageManager()->addError(__('The login challenge token is missing.'));
            return $this->redirect('/customer/account/login');
        }

        $challenge = $this->customerWebAuthService->getChallenge($challengeToken);
        if (!$challenge) {
            $this->getMessageManager()->addError(__('The login challenge is invalid or has expired.'));
            return $this->redirect('/customer/account/login');
        }

        $this->assign('challenge_token', $challengeToken);
        $this->assign('expires_at', (int) $challenge->getData(PendingAuthChallenge::schema_fields_EXPIRES_AT));
        $this->assign('title', __('Two-Factor Verification'));

        $this->assign('error_message', MessageManager::get_error_message());
        $this->assign('success_message', MessageManager::get_success_message());

        return $this->fetch('Weline_Customer::templates/frontend/account/challenge.phtml');
    }

    public function postIndex(): string
    {
        $challengeToken = trim((string) ($this->request->getPost('challenge_token') ?? ''));
        $code = trim((string) ($this->request->getPost('code') ?? ''));

        if ($challengeToken === '' || $code === '') {
            $this->getMessageManager()->addError(__('Please enter the verification code.'));
            return $this->redirect('/customer/account/challenge?challenge_token=' . urlencode($challengeToken));
        }

        try {
            $result = $this->customerWebAuthService->completeChallenge($challengeToken, $code);
            $this->getMessageManager()->addSuccess(__('Two-factor verification succeeded.'));
            return $this->redirect($this->normalizeRedirectPath((string) ($result['redirect_url'] ?? 'customer/account')));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
            return $this->redirect('/customer/account/challenge?challenge_token=' . urlencode($challengeToken));
        }
    }

    private function normalizeRedirectPath(string $redirectUrl): string
    {
        $normalized = ltrim(trim($redirectUrl), '/');
        if ($normalized === '' || $normalized === 'customer/account/index' || $normalized === 'weshop/customer/account/index') {
            return '/customer/account';
        }

        return '/' . $normalized;
    }
}
