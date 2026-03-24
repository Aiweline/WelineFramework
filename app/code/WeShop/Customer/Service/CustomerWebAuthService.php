<?php

declare(strict_types=1);

namespace WeShop\Customer\Service;

use WeShop\Auth\Data\ActorContext;
use WeShop\Auth\Model\PendingAuthChallenge;
use WeShop\Auth\Service\PendingAuthChallengeService;
use WeShop\Auth\Service\WeShopAuth2FAOrchestrator;
use Weline\Customer\Model\Customer as AuthCustomer;

class CustomerWebAuthService
{
    public function __construct(
        private readonly CustomerAccountService $customerAccountService,
        private readonly WeShopAuth2FAOrchestrator $twoFactorOrchestrator,
        private readonly PendingAuthChallengeService $pendingAuthChallengeService
    ) {
    }

    public function beginPasswordLogin(
        string $email,
        string $password,
        bool $rememberMe = false,
        string $redirectUrl = '',
        int $rememberDuration = 604800
    ): array {
        $result = $this->customerAccountService->authenticate($email, $password);

        return $this->beginLoginForAuthUser(
            $result['auth_user'],
            'password',
            $rememberMe,
            $redirectUrl,
            $rememberDuration
        );
    }

    public function beginLoginForAuthUser(
        AuthCustomer $authUser,
        string $authMethod = 'password',
        bool $rememberMe = false,
        string $redirectUrl = '',
        int $rememberDuration = 604800
    ): array {
        $redirectUrl = $this->normalizeRedirectTarget($redirectUrl);
        $rememberDuration = max(3600, $rememberDuration);
        $context = new ActorContext(
            ActorContext::ACTOR_CUSTOMER,
            (int) $authUser->getId(),
            'frontend',
            ['customer']
        );

        $primaryAuth = $this->twoFactorOrchestrator->beginPrimaryAuth(
            $context,
            $authMethod,
            'frontend',
            [
                'flow' => $authMethod,
                'remember_me' => $rememberMe,
                'remember_duration' => $rememberDuration,
                'redirect_url' => $redirectUrl,
            ]
        );

        if (($primaryAuth['status'] ?? '') === 'challenge_required') {
            /** @var PendingAuthChallenge $challenge */
            $challenge = $primaryAuth['challenge'];

            return [
                'status' => 'challenge_required',
                'challenge_token' => (string) $challenge->getData(PendingAuthChallenge::schema_fields_CHALLENGE_TOKEN),
                'expires_at' => (int) $challenge->getData(PendingAuthChallenge::schema_fields_EXPIRES_AT),
                'redirect_url' => $redirectUrl,
            ];
        }

        $this->customerAccountService->login($authUser, $rememberMe, $rememberDuration);

        return [
            'status' => 'authenticated',
            'redirect_url' => $redirectUrl,
        ];
    }

    public function completeChallenge(string $challengeToken, string $code): array
    {
        $result = $this->twoFactorOrchestrator->verifyChallenge($challengeToken, $code);
        if (!$result) {
            throw new \RuntimeException((string) __('The verification code is invalid.'));
        }

        /** @var ActorContext $context */
        $context = $result['context'];
        if ($context->getActorType() !== ActorContext::ACTOR_CUSTOMER) {
            throw new \RuntimeException((string) __('The challenge does not belong to a customer login.'));
        }

        $payload = (array) ($result['payload'] ?? []);
        $authUser = $this->customerAccountService->getAuthUserById($context->getActorId());
        if (!$authUser) {
            throw new \RuntimeException((string) __('The account for this challenge no longer exists.'));
        }

        $this->customerAccountService->login(
            $authUser,
            (bool) ($payload['remember_me'] ?? false),
            max(3600, (int) ($payload['remember_duration'] ?? 604800))
        );

        return [
            'status' => 'authenticated',
            'redirect_url' => $this->normalizeRedirectTarget((string) ($payload['redirect_url'] ?? '')),
        ];
    }

    public function getChallenge(string $challengeToken): ?PendingAuthChallenge
    {
        return $this->pendingAuthChallengeService->getValidChallenge($challengeToken);
    }

    private function normalizeRedirectTarget(string $redirectUrl): string
    {
        $redirectUrl = trim($redirectUrl);
        if ($redirectUrl === '') {
            return 'weshop/customer/account/index';
        }

        if (str_contains($redirectUrl, '://') || str_starts_with($redirectUrl, '//')) {
            return 'weshop/customer/account/index';
        }

        return ltrim($redirectUrl, '/');
    }
}
