<?php

declare(strict_types=1);

namespace WeShop\GoogleAuth\Controller\Frontend\Auth;

use WeShop\Customer\Service\CustomerAccountService;
use WeShop\Customer\Service\CustomerWebAuthService;
use WeShop\GoogleAuth\Service\BackendWebAuthService;
use WeShop\GoogleAuth\Service\GoogleLoginService;
use WeShop\GoogleAuth\Service\GoogleOAuthService;
use Weline\Backend\Model\BackendUser;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Url;

class Callback extends FrontendController
{
    public function __construct(
        private readonly GoogleOAuthService $googleOAuthService,
        private readonly GoogleLoginService $googleLoginService,
        private readonly CustomerAccountService $customerAccountService,
        private readonly CustomerWebAuthService $customerWebAuthService,
        private readonly BackendWebAuthService $backendWebAuthService,
        private readonly BackendUser $backendUser,
        private readonly Url $url
    ) {
    }

    public function index(): void
    {
        $state = trim((string) ($this->request->getParam('state') ?? ''));
        $error = trim((string) ($this->request->getParam('error') ?? ''));
        $errorDescription = trim((string) ($this->request->getParam('error_description') ?? ''));
        $payload = $this->googleOAuthService->consumeState($state);

        if (!$payload) {
            $this->getMessageManager()->addError(__('The Google login state is invalid or has expired.'));
            $this->redirect($this->url->getFrontendUrl('weshop/customer/account/login'));
            return;
        }

        $area = strtolower((string) ($payload['area'] ?? 'frontend'));
        $mode = strtolower((string) ($payload['mode'] ?? 'login'));
        $localUserId = (int) ($payload['local_user_id'] ?? 0);
        $redirectUrl = $this->googleOAuthService->sanitizeRedirectUrl(
            $area,
            (string) ($payload['redirect_url'] ?? ''),
            true
        );

        if ($error !== '') {
            $message = $errorDescription !== '' ? $errorDescription : $error;
            $this->getMessageManager()->addError(__('Google authorization failed: %{1}', [$message]));
            $this->redirect($this->getFallbackUrl($area, $mode));
            return;
        }

        $code = trim((string) ($this->request->getParam('code') ?? ''));
        if ($code === '') {
            $this->getMessageManager()->addError(__('Google authorization code is missing.'));
            $this->redirect($this->getFallbackUrl($area, $mode));
            return;
        }

        try {
            if ($mode === 'bind') {
                $this->googleLoginService->bindByCode($area, $localUserId, $code);
                $this->getMessageManager()->addSuccess(__('Google account bound successfully.'));
                $this->redirect($this->getBindSuccessUrl($area, $redirectUrl));
                return;
            }

            if ($area === 'backend') {
                $result = $this->handleBackendLogin($code, $redirectUrl);
                if (($result['status'] ?? '') === 'challenge_required') {
                    $this->getMessageManager()->addNotice(__('Please complete two-factor verification to finish sign in.'));
                    $this->redirect($this->url->getFrontendUrl('weshop_googleauth/frontend/auth/backend-challenge', [
                        'challenge_token' => (string) ($result['challenge_token'] ?? ''),
                    ]));
                    return;
                }

                $this->redirect((string) ($result['redirect_url'] ?? $this->url->getBackendUrl('admin')));
                return;
            }

            $result = $this->handleFrontendLogin($code, $redirectUrl);
            if (($result['status'] ?? '') === 'challenge_required') {
                $this->getMessageManager()->addNotice(__('Please complete two-factor verification to finish sign in.'));
                $this->redirect($this->url->getFrontendUrl('weshop/customer/account/challenge', [
                    'challenge_token' => (string) ($result['challenge_token'] ?? ''),
                ]));
                return;
            }

            $this->getMessageManager()->addSuccess(__('Login succeeded.'));
            $this->redirect((string) ($result['redirect_url'] ?? 'weshop/customer/account/index'));
        } catch (\Throwable $throwable) {
            $this->getMessageManager()->addError($throwable->getMessage());
            $this->redirect($this->getFallbackUrl($area, $mode));
        }
    }

    private function handleFrontendLogin(string $code, string $redirectUrl): array
    {
        $login = $this->googleLoginService->authenticateByCode('frontend', $code);
        $authUser = $this->customerAccountService->getAuthUserById((int) ($login['actor_id'] ?? 0));
        if (!$authUser) {
            throw new \RuntimeException((string) __('The customer account for this Google login no longer exists.'));
        }

        return $this->customerWebAuthService->beginLoginForAuthUser($authUser, 'google', false, $redirectUrl);
    }

    private function handleBackendLogin(string $code, string $redirectUrl): array
    {
        $login = $this->googleLoginService->authenticateByCode('backend', $code);
        $backendUser = clone $this->backendUser;
        $backendUser->load((int) ($login['actor_id'] ?? 0));
        if (!$backendUser->getId()) {
            throw new \RuntimeException((string) __('The backend account for this Google login no longer exists.'));
        }

        return $this->backendWebAuthService->beginLoginForBackendUser($backendUser, 'google', false, $redirectUrl);
    }

    private function getBindSuccessUrl(string $area, string $redirectUrl): string
    {
        if ($area === 'backend') {
            return $this->url->getBackendUrl('weshop_googleauth/backend/auth/binding');
        }

        if ($redirectUrl !== '') {
            return $redirectUrl;
        }

        return $this->url->getFrontendUrl('weshop/customer/account/index');
    }

    private function getFallbackUrl(string $area, string $mode): string
    {
        if ($area === 'backend' && $mode === 'bind') {
            return $this->url->getBackendUrl('weshop_googleauth/backend/auth/binding');
        }

        if ($area === 'backend') {
            return $this->url->getBackendUrl('admin/login');
        }

        if ($mode === 'bind') {
            return $this->url->getFrontendUrl('weshop/customer/account/index');
        }

        return $this->url->getFrontendUrl('weshop/customer/account/login');
    }
}
