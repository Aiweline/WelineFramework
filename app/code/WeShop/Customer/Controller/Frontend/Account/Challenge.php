<?php

declare(strict_types=1);

namespace WeShop\Customer\Controller\Frontend\Account;

use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Customer\Service\CustomerWebAuthService;
use WeShop\Frontend\Controller\BaseController;

class Challenge extends BaseController
{
    protected ?string $layoutType = 'account.auth';

    public function __construct(
        private readonly CustomerWebAuthService $customerWebAuthService
    ) {
    }

    public function index(): string
    {
        $challengeToken = trim((string) ($this->request->getParam('challenge_token') ?? ''));
        if ($challengeToken === '') {
            $this->getMessageManager()->addError(__('The login challenge token is missing.'));
            return $this->redirect('weshop/customer/account/login');
        }

        $challenge = $this->customerWebAuthService->getChallenge($challengeToken);
        if (!$challenge) {
            $this->getMessageManager()->addError(__('The login challenge is invalid or has expired.'));
            return $this->redirect('weshop/customer/account/login');
        }

        $this->assign('challenge_token', $challengeToken);
        $this->assign('expires_at', (int) $challenge->getData(PendingAuthChallenge::schema_fields_EXPIRES_AT));
        $this->assign('title', __('Two-Factor Verification'));

        return $this->fetch();
    }

    public function postIndex(): array|string
    {
        $wantsJson = $this->wantsChallengeJsonResponse();
        $challengeToken = trim((string) ($this->request->getPost('challenge_token') ?? ''));
        $code = trim((string) ($this->request->getPost('code') ?? ''));

        if ($challengeToken === '' || $code === '') {
            if ($wantsJson) {
                return [
                    'success' => false,
                    'message' => (string) __('Please enter the verification code.'),
                ];
            }
            $this->getMessageManager()->addError(__('Please enter the verification code.'));
            return $this->redirect('weshop/customer/account/challenge?challenge_token=' . urlencode($challengeToken));
        }

        try {
            $result = $this->customerWebAuthService->completeChallenge($challengeToken, $code);
            $target = (string) ($result['redirect_url'] ?? 'weshop/customer/account/index');
            if ($wantsJson) {
                return [
                    'success' => true,
                    'message' => (string) __('Two-factor verification succeeded.'),
                    'redirect' => $this->getUrl($target),
                ];
            }
            $this->getMessageManager()->addSuccess(__('Two-factor verification succeeded.'));
            return $this->redirect($target);
        } catch (\Throwable $e) {
            if ($wantsJson) {
                return [
                    'success' => false,
                    'message' => $e->getMessage(),
                ];
            }
            $this->getMessageManager()->addError($e->getMessage());
            return $this->redirect('weshop/customer/account/challenge?challenge_token=' . urlencode($challengeToken));
        }
    }

    private function wantsChallengeJsonResponse(): bool
    {
        $accept = (string) ($this->request->getHeader('Accept') ?? '');
        return str_contains(strtolower($accept), 'application/json');
    }
}
