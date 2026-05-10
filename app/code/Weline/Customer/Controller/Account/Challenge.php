<?php

declare(strict_types=1);

namespace Weline\Customer\Controller\Account;

use Weline\Customer\Api\CustomerLoginChallengeHandlerInterface;
use Weline\Framework\View\Template;

class Challenge extends \Weline\Framework\App\Controller\FrontendController
{
    protected ?string $layoutType = 'account.auth';

    public function __construct(
        private readonly Template $template,
        private readonly CustomerLoginChallengeHandlerInterface $challengeHandler
    ) {
    }

    public function getIndex(): string
    {
        $challengeToken = trim((string) ($this->request->getParam('challenge_token') ?? ''));
        if ($challengeToken === '') {
            $this->getMessageManager()->addError(__('The login challenge token is missing.'));
            return $this->redirect('/customer/account/login');
        }

        $expiresAt = $this->challengeHandler->getChallengeExpiresAt($challengeToken);
        if ($expiresAt === null) {
            $this->getMessageManager()->addError(__('The login challenge is invalid or has expired.'));
            return $this->redirect('/customer/account/login');
        }

        $this->assign('challenge_token', $challengeToken);
        $this->assign('expires_at', $expiresAt);
        $this->assign('title', __('Two-Factor Verification'));

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
            $result = $this->challengeHandler->completeChallenge($challengeToken, $code);
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
        if ($normalized === '' || $normalized === 'customer/account/index') {
            return '/customer/account';
        }

        return '/' . $normalized;
    }
}
